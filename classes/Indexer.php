<?php

/**
 * @file classes/Indexer.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Indexer
 *
 * @ingroup plugins_generic_fullTextSearch
 *
 * @brief Handles indexing of submission data into the full-text search table
 */

namespace APP\plugins\generic\fullTextSearch\classes;

use APP\core\Services;
use APP\facades\Repo;
use PKP\search\SearchFileParser;

class Indexer
{
    private Dao $dao;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->dao = new Dao();
    }

    /**
     * Index a submission by extracting relevant text fields and storing them in the search index
     *
     * @param object $submission The submission object to index
     */
    public function indexSubmission(object $submission): void
    {
        $publication = $submission->getCurrentPublication();
        $contextId = (int) $submission->getData('contextId');
        $submissionId = (int) $submission->getId();

        $authors = [];
        foreach ($publication->getData('authors') as $author) {
            $authors = array_merge(
                $authors,
                array_values((array) $author->getData('givenName')),
                array_values((array) $author->getData('familyName')),
                array_values((array) $author->getData('preferredPublicName')),
                array_values((array) $author->getData('affiliation'))
            );
        }

        $fields = [
            'title' => $this->implodeLocalized($publication->getFullTitles()),
            'abstract' => $this->implodeLocalized($publication->getData('abstract')),
            'authors' => $this->implodeLocalized($authors),
            'keywords' => $this->implodeLocalized($this->flattenLocalizedArray($publication->getData('keywords'))),
            'subjects' => $this->implodeLocalized($this->flattenLocalizedArray($publication->getData('subjects'))),
            'disciplines' => $this->implodeLocalized($this->flattenLocalizedArray($publication->getData('disciplines'))),
            'coverage' => $this->implodeLocalized((array) $publication->getData('coverage')),
            'type' => $this->implodeLocalized((array) $publication->getData('type')),
        ];

        $this->dao->upsert($submissionId, $contextId, $fields);
    }

    /**
     * Index a submission file by extracting text content and updating the search index
     *
     * @param int $submissionId The submission ID
     * @param int $submissionFileId The submission file ID
     */
    public function indexSubmissionFile(int $submissionId, int $submissionFileId): void
    {
        $submissionFile = Repo::submissionFile()->get($submissionFileId);
        if (!$submissionFile) {
            return;
        }

        $parser = SearchFileParser::fromFile($submissionFile);
        $texts = [];
        if ($parser?->open()) {
            while (($text = $parser->read()) !== false) {
                $texts[] = $text;
            }
            $parser->close();
        }

        $galleyText = $this->implodeLocalized($texts);
        $this->dao->upsert(
            $submissionId,
            (int) Services::get('submission')->get($submissionId)->getData('contextId'),
            ['galley_text' => $galleyText]
        );
    }

    /**
     * Delete a submission from the search index
     *
     * @param int $submissionId The submission ID to delete
     */
    public function deleteSubmission(int $submissionId): void
    {
        $this->dao->deleteBySubmission($submissionId);
    }

    /**
     * Remove galley text from a submission in the search index
     *
     * @param int $submissionId The submission ID
     */
    public function removeFileFromIndex(int $submissionId): void
    {
        $this->dao->removeFileFromIndex($submissionId);
    }

    /**
     * Convert localized array data to a single string
     *
     * @param array|null $localized The localized array data
     *
     * @return string The flattened string
     */
    private function implodeLocalized(?array $localized): string
    {
        return trim(implode(' ', array_filter(array_map('strip_tags', (array) $localized))));
    }

    /**
     * Flatten a nested localized array structure
     *
     * @param array|null $localized The nested localized array
     *
     * @return array The flattened array
     */
    private function flattenLocalizedArray(?array $localized): array
    {
        $out = [];
        foreach ((array) $localized as $arr) {
            $out = array_merge($out, (array) $arr);
        }
        return $out;
    }
}
