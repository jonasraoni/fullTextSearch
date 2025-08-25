<?php

/**
 * @file classes/Dao.inc.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Dao
 * @ingroup plugins_generic_fullTextSearch
 *
 * @brief Data Access Object for the full-text search index table
 */

namespace APP\plugins\generic\fullTextSearch\classes;

use Application;
use Context;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;
use Services;
use APP\plugins\generic\fullTextSearch\classes\Indexer;

class Dao
{
    public const TABLE_NAME = 'full_text_search_plugin_index';

    /**
     * Insert or update a submission record in the full-text search index
     * @param int $submissionId The submission ID
     * @param int $contextId The context ID
     * @param array $fields The fields to store (title, abstract, authors, etc.)
     */
    public function upsert(int $submissionId, int $contextId, array $fields): void
    {
        $data = array_merge([
            'submission_id' => $submissionId,
            'context_id' => $contextId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $fields);

        Manager::table(static::TABLE_NAME)->updateOrInsert(
            ['submission_id' => $submissionId],
            $data
        );
    }

    /**
     * Delete a submission record from the full-text search index
     * @param int $submissionId The submission ID to delete
     */
    public function deleteBySubmission(int $submissionId): void
    {
        Manager::table(static::TABLE_NAME)->where('submission_id', $submissionId)->delete();
    }

    /**
     * Remove galley text from a submission record in the index
     * @param int $submissionId The submission ID
     */
    public function removeFileFromIndex(int $submissionId): void
    {
        Manager::table(static::TABLE_NAME)->where('submission_id', $submissionId)->update([
            'galley_text' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Search the full-text index for submissions matching the given criteria
     * @param ?Context $context The context object or null for all contexts
     * @param array $keywords Array of search keywords keyed by field type
     * @param string $orderBy The field to order by
     * @param string $orderDirection The order direction (ASC/DESC)
     * @param array $exclude Array of submission IDs to exclude
     * @param int $page The page number for pagination
     * @param int $perPage The number of items per page
     * @param mixed $publishedFrom Optional publication date from filter
     * @param mixed $publishedTo Optional publication date to filter
     * @return array{0:int[],1:int} Array containing submission IDs and total count
     */
    public function search(?Context $context, array $keywords, string $orderBy, string $orderDirection, array $exclude, int $page, int $perPage, $publishedFrom = null, $publishedTo = null): array
    {
        $q = Manager::table(static::TABLE_NAME, 'fts');

        if ($context) {
            $q->where('fts.context_id', $context->getId());
        }

        if (!empty($exclude)) {
            $q->whereNotIn('fts.submission_id', array_map('intval', $exclude));
        }

        // Build field-specific search queries
        $hasSearchCriteria = false;
        $scores = [];
        $scoreParams = [];

        foreach ($keywords as $fieldType => $query) {
            if (empty($query)) {
                continue;
            }

            $field = $this->getFieldForType($fieldType);
            $fields = $field ? ["fts.{$field}"] : [];
            if (!$field) {
                foreach([
                    SUBMISSION_SEARCH_AUTHOR,
                    SUBMISSION_SEARCH_TITLE,
                    SUBMISSION_SEARCH_ABSTRACT,
                    SUBMISSION_SEARCH_GALLEY_FILE,
                    SUBMISSION_SEARCH_DISCIPLINE,
                    SUBMISSION_SEARCH_SUBJECT,
                    SUBMISSION_SEARCH_KEYWORD,
                    SUBMISSION_SEARCH_TYPE,
                    SUBMISSION_SEARCH_COVERAGE
                ] as $fieldType) {
                    $fields[] = 'fts.' . $this->getFieldForType($fieldType);
                }
            }

            $hasSearchCriteria = true;
            $q->where(function (Builder $q) use ($fields, $query, &$scores, &$scoreParams) {
                foreach ($fields as $field) {
                    if (Manager::connection() instanceof PostgresConnection) {
                        $tsVector = "to_tsvector('simple', coalesce({$field}, ''))";
                        $tsQuery = "plainto_tsquery('simple', ?)";
                        $q->orWhereRaw("{$tsVector} @@ {$tsQuery}", [$query]);
                        $scores[] = "ts_rank({$tsVector}, {$tsQuery})";
                        $scoreParams[] = $query;
                    } else {
                        $match = "MATCH({$field}) AGAINST (? IN NATURAL LANGUAGE MODE)";
                        $q->orWhereRaw($match, [$query]);
                        $scores[] = $match;
                        $scoreParams[] = $query;
                    }
                }
            });
        }

        // If no specific search criteria, select all with default score
        if (!$hasSearchCriteria) {
            $scores = ['1'];
        }

        $q->select('fts.submission_id')->selectRaw(implode(' + ', $scores) . ' AS score', $scoreParams);
        // Optional publication date join/filter if provided
        if ($publishedFrom || $publishedTo) {
            $q->join('submissions as s', 's.submission_id', '=', 'fts.submission_id')
                ->join('publications as p', 'p.publication_id', '=', 's.current_publication_id');
            if ($publishedFrom) {
                $q->where('p.date_published', '>=', $publishedFrom);
            }
            if ($publishedTo) {
                $q->where('p.date_published', '<=', $publishedTo);
            }
        }

        // Order
        $orderBy = strtolower($orderBy);
        $orderDirection = strtolower($orderDirection) === 'asc' ? 'asc' : 'desc';
        switch ($orderBy) {
            case 'score':
            default:
                $q->orderBy('score', $orderDirection);
        }

        // Count total
        $total = (clone $q)->count('fts.submission_id');
        // Pagination
        $offset = max(0, ($page - 1) * $perPage);
        $q->offset($offset)->limit($perPage);
        $ids = $q->pluck('submission_id')->all();
        return [$ids, (int) $total];
    }

    /**
     * Map search type constants to database field names
     */
    private function getFieldForType(string $fieldType): ?string
    {
        $fieldMap = [
            SUBMISSION_SEARCH_AUTHOR => 'authors',
            SUBMISSION_SEARCH_TITLE => 'title',
            SUBMISSION_SEARCH_ABSTRACT => 'abstract',
            SUBMISSION_SEARCH_GALLEY_FILE => 'galley_text',
            SUBMISSION_SEARCH_DISCIPLINE => 'disciplines',
            SUBMISSION_SEARCH_SUBJECT => 'subjects',
            SUBMISSION_SEARCH_KEYWORD => 'keywords',
            SUBMISSION_SEARCH_TYPE => 'type',
            SUBMISSION_SEARCH_COVERAGE => 'coverage',
        ];

        return $fieldMap[$fieldType] ?? null;
    }

    /**
     * Clear standard search tables
     */
    public function clearStandardSearchTables(): void
    {
        $connection = Manager::connection();
        $connection->table('submission_search_object_keywords')->truncate();
        $connection->table('submission_search_objects')->truncate();
        $connection->table('submission_search_keyword_list')->truncate();
    }

    /**
     * Clean up old/unpublished submissions from the full-text search index
     * @param array $contextIds Array of context IDs to clean up
     */
    public function cleanUnpublishedSubmissions(array $contextIds): void
    {
        Manager::table(static::TABLE_NAME, 'fts')
            ->join('submissions AS s', 's.submission_id', '=', 'fts.submission_id')
            ->whereIn('s.context_id', $contextIds)
            ->where('s.status', '!=', STATUS_PUBLISHED)
            ->delete();
    }

    /**
     * Get all contexts for settings form
     * @return array Array of context ID => localized name
     */
    public function getAllContexts(): array
    {
        $contexts = [];

        /** @var Context $context */
        foreach (Application::getContextDAO()->getAll(false)->toIterator() as $context) {
            $contexts[$context->getId()] = $context->getLocalizedName();
        }

        return $contexts;
    }

    /**
     * Rebuild the search index for selected contexts
     * @param array $contextIds Array of context IDs to rebuild
     * @param ?bool $log Whether to log the rebuild process
     * @param ?array $switches The switches to use for the rebuild process
     */
    public function rebuildSearchIndex(array $contextIds, ?bool $log = false, ?array $switches = []): void
    {
        set_time_limit(0);
        import('classes.submission.Submission');
        $searchIndex = Application::getSubmissionSearchIndex();
        foreach ($contextIds as $contextId) {
            $context = Application::getContextDAO()->getById($contextId);
            if (!$context) {
                continue;
            }

            if ($log) {
                echo "Rebuilding index for context {$context->getLocalizedName()}\n";
            }

            // Get only published submissions for this context
            $submissionsIterator = Services::get('submission')->getMany([
                'contextId' => $contextId,
                'status' => [STATUS_PUBLISHED]
            ]);

            foreach ($submissionsIterator as $submission) {
                $searchIndex->submissionMetadataChanged($submission);
                $searchIndex->submissionFilesChanged($submission);
            }
        }

        if ($log) {
            echo "Cleaning up old/unpublished submissions from the index\n";
        }

        // Clean up old/unpublished submissions from the index
        $this->cleanUnpublishedSubmissions($contextIds);
    }

    /**
     * Rebuild the search index
     * @param ?Context $context The context
     * @param ?bool $log Whether to log the rebuild process
     * @param ?array $switches The switches to use for the rebuild process
     */
    public function rebuildIndex(?Context $context = null, ?bool $log = false, ?array $switches = []): void
    {
        $contextIds = $context ? [$context->getId()] : array_keys(Application::getContextDAO()->getAll()->toAssociativeArray());
        $this->rebuildSearchIndex($contextIds, $log, $switches);
    }
}
