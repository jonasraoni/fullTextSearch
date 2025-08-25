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

use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use PKP\context\Context;
use PKP\core\PKPString;
use PKP\search\SearchFileParser;
use PKP\submissionFile\SubmissionFile;

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

    public function sanitize(?string $text): string
    {
        // Attempts to fix bad UTF-8 characters
        $previous = mb_substitute_character();
        mb_substitute_character('none');
        $text = mb_convert_encoding($text ?? '', 'UTF-8', 'UTF-8');
        mb_substitute_character($previous);

        // Remove punctuation
        return preg_replace('/[\\p{C}\\p{M}\\p{P}\\p{S}\\p{Z}]+/u', ' ', $text);
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
            'title' => $this->sanitize($this->implodeLocalized($publication->getFullTitles())),
            'abstract' => $this->sanitize($this->implodeLocalized($publication->getData('abstract'))),
            'authors' => $this->sanitize($this->implodeLocalized($authors)),
            'keywords' => $this->sanitize($this->implodeLocalized($this->flattenLocalizedArray($publication->getData('keywords')))),
            'subjects' => $this->sanitize($this->implodeLocalized($this->flattenLocalizedArray($publication->getData('subjects')))),
            'disciplines' => $this->sanitize($this->implodeLocalized($this->flattenLocalizedArray($publication->getData('disciplines')))),
            'coverage' => $this->sanitize($this->implodeLocalized((array) $publication->getData('coverage'))),
            'type' => $this->sanitize($this->implodeLocalized((array) $publication->getData('type'))),
            // The metadata hook is called before the files hook, so even though it sounds risky, it's ok to clear the galley_text here, as this code is unlikely to be changed
            'galley_text' => '',
        ];

        $this->dao->upsert($submissionId, $contextId, $fields);
    }

    /**
     * Index a submission file by extracting text content and updating the search index
     */
    public function indexSubmissionFile(Submission $submission, SubmissionFile $submissionFile): void
    {
        set_time_limit(0);
        $parser = SearchFileParser::fromFile($submissionFile);
        $texts = [];
        if ($parser?->open()) {
            while (($text = $parser->read()) !== false) {
                $texts[] = $text;
            }
            $parser->close();
        }

        $galleyText = DB::connection()->getPdo()->quote($this->sanitize($this->implodeLocalized($texts)));
        $this->dao->upsert(
            $submission->getId(),
            (int) $submission->getData('contextId'),
            ['galley_text' => DB::raw("CONCAT(COALESCE(galley_text, ''), ' ', {$galleyText})")]
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

    /**
     * Rebuild the index
     *
     * @param ?Context $context The context
     * @param ?bool $log Whether to log the rebuild process
     * @param ?array $switches The switches to use for the rebuild process
     */
    public function rebuildIndex(?Context $context = null, ?bool $log = false, ?array $switches = []): void
    {
        $this->dao->rebuildIndex($context, $log, $switches);
    }
}
