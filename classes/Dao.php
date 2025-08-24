<?php

/**
 * @file classes/Dao.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Dao
 *
 * @ingroup plugins_generic_fullTextSearch
 *
 * @brief Data Access Object for the full-text search index table
 */

namespace APP\plugins\generic\fullTextSearch\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\submission\Submission;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use PKP\context\Context;
use PKP\search\SubmissionSearch;
use PKP\submissionFile\SubmissionFile;

class Dao
{
    public const TABLE_NAME = 'full_text_search_plugin_index';

    /**
     * Insert or update a submission record in the full-text search index
     *
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

        DB::table(static::TABLE_NAME)->updateOrInsert(
            ['submission_id' => $submissionId],
            $data
        );
    }

    /**
     * Delete a submission record from the full-text search index
     *
     * @param int $submissionId The submission ID to delete
     */
    public function deleteBySubmission(int $submissionId): void
    {
        DB::table(static::TABLE_NAME)->where('submission_id', $submissionId)->delete();
    }

    /**
     * Remove galley text from a submission record in the index
     *
     * @param int $submissionId The submission ID
     */
    public function removeFileFromIndex(int $submissionId): void
    {
        DB::table(static::TABLE_NAME)->where('submission_id', $submissionId)->update([
            'galley_text' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Search the full-text index for submissions matching the given criteria
     *
     * @param ?Context $context The context object or null for all contexts
     * @param array $keywords Array of search keywords keyed by field type
     * @param string $orderBy The field to order by
     * @param string $orderDirection The order direction (ASC/DESC)
     * @param array $exclude Array of submission IDs to exclude
     * @param int $page The page number for pagination
     * @param int $perPage The number of items per page
     * @param mixed $publishedFrom Optional publication date from filter
     * @param mixed $publishedTo Optional publication date to filter
     *
     * @return array{0:int[],1:int} Array containing submission IDs and total count
     */
    public function search(?Context $context, array $keywords, string $orderBy, string $orderDirection, array $exclude, int $page, int $perPage, $publishedFrom = null, $publishedTo = null): array
    {
        $q = DB::table(static::TABLE_NAME, 'fts');

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
                foreach ([
                    SubmissionSearch::SUBMISSION_SEARCH_AUTHOR,
                    SubmissionSearch::SUBMISSION_SEARCH_TITLE,
                    SubmissionSearch::SUBMISSION_SEARCH_ABSTRACT,
                    SubmissionSearch::SUBMISSION_SEARCH_GALLEY_FILE,
                    SubmissionSearch::SUBMISSION_SEARCH_DISCIPLINE,
                    SubmissionSearch::SUBMISSION_SEARCH_SUBJECT,
                    SubmissionSearch::SUBMISSION_SEARCH_KEYWORD,
                    SubmissionSearch::SUBMISSION_SEARCH_TYPE,
                    SubmissionSearch::SUBMISSION_SEARCH_COVERAGE
                ] as $fieldType) {
                    $fields[] = 'fts.' . $this->getFieldForType($fieldType);
                }
            }

            $hasSearchCriteria = true;
            $q->where(function (Builder $q) use ($fields, $query, &$scores, &$scoreParams) {
                foreach ($fields as $field) {
                    if (DB::connection() instanceof PostgresConnection) {
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
        $q->orderBy(match($orderBy) {
            'score' => 'score',
            default => 'score'
        }, $orderDirection);

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
            SubmissionSearch::SUBMISSION_SEARCH_AUTHOR => 'authors',
            SubmissionSearch::SUBMISSION_SEARCH_TITLE => 'title',
            SubmissionSearch::SUBMISSION_SEARCH_ABSTRACT => 'abstract',
            SubmissionSearch::SUBMISSION_SEARCH_GALLEY_FILE => 'galley_text',
            SubmissionSearch::SUBMISSION_SEARCH_DISCIPLINE => 'disciplines',
            SubmissionSearch::SUBMISSION_SEARCH_SUBJECT => 'subjects',
            SubmissionSearch::SUBMISSION_SEARCH_KEYWORD => 'keywords',
            SubmissionSearch::SUBMISSION_SEARCH_TYPE => 'type',
            SubmissionSearch::SUBMISSION_SEARCH_COVERAGE => 'coverage',
        ];

        return $fieldMap[$fieldType] ?? null;
    }

    /**
     * Clear standard search tables
     */
    public function clearStandardSearchTables(): void
    {
        $connection = DB::connection();
        $connection->table('submission_search_keyword_list')->delete();
        $connection->table('submission_search_objects')->delete();
    }

    /**
     * Clean up old/unpublished submissions from the full-text search index
     *
     * @param array $contextIds Array of context IDs to clean up
     */
    public function cleanUnpublishedSubmissions(array $contextIds): void
    {
        foreach ($contextIds as $contextId) {
            if (empty($contextId)) {
                continue;
            }

            DB::table(static::TABLE_NAME, 'fts')
                ->join('submissions AS s', 's.submission_id', '=', 'fts.submission_id')
                ->where('s.context_id', $contextId)
                ->where('s.status', '!=', Submission::STATUS_PUBLISHED)
                ->delete();
        }
    }

    /**
     * Get all contexts for settings form
     *
     * @return array Array of context ID => localized name
     */
    public function getAllContexts(): array
    {
        $contexts = [];
        foreach (Application::getContextDAO()->getAll(false)->toIterator() as $context) {
            $contexts[$context->getId()] = $context->getLocalizedName();
        }

        return $contexts;
    }

    /**
     * Rebuild the search index for selected contexts
     *
     * @param array $contextIds Array of context IDs to rebuild
     */
    public function rebuildSearchIndex(array $contextIds): void
    {
        $indexer = new Indexer();
        foreach ($contextIds as $contextId) {
            if (empty($contextId)) {
                continue;
            }

            $context = Application::getContextDAO()->getById($contextId);
            if (!$context) {
                continue;
            }

            // Get only published submissions for this context
            $submissionsIterator = Repo::submission()
                ->getCollector()
                ->filterByContextIds([$contextId])
                ->filterByStatus([Submission::STATUS_PUBLISHED])
                ->getMany();

            foreach ($submissionsIterator as $submission) {
                $indexer->indexSubmission($submission);
                // Also index any galley files
                $submissionFilesIterator = Repo::submissionFile()
                    ->getCollector()
                    ->filterBySubmissionIds([$submission->getId()])
                    ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_PROOF])
                    ->getMany();
                foreach ($submissionFilesIterator as $submissionFile) {
                    $indexer->indexSubmissionFile($submission->getId(), $submissionFile->getId());
                }
            }
        }

        // Clean up old/unpublished submissions from the index
        $this->cleanUnpublishedSubmissions($contextIds);
    }
}
