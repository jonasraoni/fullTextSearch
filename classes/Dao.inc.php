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

class Dao
{
    public const TABLE_NAME = 'full_text_search_plugin_index';
    public const CHUNKS_TABLE = 'full_text_search_plugin_index_chunks';

    /**
     * Characters stored in one galley chunk. Stays under MySQL TEXT, the Postgres tsvector cap, and the 16383 position cap.
     */
    public const CHUNK_CHARACTERS = 15000;

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
     * Insert a parent row when a galley is indexed before its metadata.
     */
    public function ensureSubmission(int $submissionId, int $contextId): void
    {
        if (Manager::table(static::TABLE_NAME)->where('submission_id', $submissionId)->exists()) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        Manager::table(static::TABLE_NAME)->insert([
            'submission_id' => $submissionId,
            'context_id' => $contextId,
            'galley_text' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Remove galley chunks for a submission. The parent row is rewritten by the metadata hook.
     */
    public function deleteChunks(int $submissionId): void
    {
        Manager::table(static::CHUNKS_TABLE)->where('submission_id', $submissionId)->delete();
    }

    /**
     * Append one galley chunk. Sequence continues across files for the same submission.
     */
    public function insertChunk(int $submissionId, string $body): void
    {
        $max = Manager::table(static::CHUNKS_TABLE)->where('submission_id', $submissionId)->max('seq');
        Manager::table(static::CHUNKS_TABLE)->insert([
            'submission_id' => $submissionId,
            'seq' => $max === null ? 0 : ((int) $max) + 1,
            'body' => $body,
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
                    $this->applyFieldMatch($q, $field, $query, $scores, $scoreParams);
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
     * Match one indexed field and add its rank to the score.
     * @param string[] $scores
     * @param array $scoreParams
     */
    private function applyFieldMatch(Builder $q, string $field, string $query, array &$scores, array &$scoreParams): void
    {
        if ($field === 'fts.galley_text') {
            $this->applyGalleyMatch($q, $query, $scores, $scoreParams);
            return;
        }

        if (Manager::connection() instanceof PostgresConnection) {
            $tsVector = "to_tsvector('simple', coalesce({$field}, ''))";
            $tsQuery = "plainto_tsquery('simple', ?)";
            $q->orWhereRaw("{$tsVector} @@ {$tsQuery}", [$query]);
            $scores[] = "ts_rank({$tsVector}, {$tsQuery})";
            $scoreParams[] = $query;
            return;
        }

        $match = "MATCH({$field}) AGAINST (? IN NATURAL LANGUAGE MODE)";
        $q->orWhereRaw($match, [$query]);
        $scores[] = $match;
        $scoreParams[] = $query;
    }

    /**
     * Match galley chunks without joining them onto the submission row.
     * @param string[] $scores
     * @param array $scoreParams
     */
    private function applyGalleyMatch(Builder $q, string $query, array &$scores, array &$scoreParams): void
    {
        $chunks = static::CHUNKS_TABLE;
        if (Manager::connection() instanceof PostgresConnection) {
            $vector = "to_tsvector('simple', coalesce(body, ''))";
            $tsQuery = "plainto_tsquery('simple', ?)";
            $match = "{$vector} @@ {$tsQuery}";
            $rank = "ts_rank({$vector}, {$tsQuery})";
        } else {
            $match = 'MATCH(body) AGAINST (? IN NATURAL LANGUAGE MODE)';
            $rank = $match;
        }

        $q->orWhereExists(function (Builder $sub) use ($chunks, $match, $query) {
            $sub->selectRaw('1')
                ->from($chunks)
                ->whereColumn("{$chunks}.submission_id", 'fts.submission_id')
                ->whereRaw($match, [$query]);
        });
        $scores[] = "(SELECT COALESCE(SUM({$rank}), 0) FROM {$chunks} WHERE {$chunks}.submission_id = fts.submission_id)";
        $scoreParams[] = $query;
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
