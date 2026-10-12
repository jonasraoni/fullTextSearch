<?php

/**
 * @file classes/Indexer.inc.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Indexer
 * @ingroup plugins_generic_fullTextSearch
 *
 * @brief Handles indexing of submission data into the full-text search table
 */

namespace APP\plugins\generic\fullTextSearch\classes;

use Context;
use PKPString;
use SearchFileParser;
use Submission;
use SubmissionFile;

class Indexer
{
    /**
     * Postgres lexemes longer than this are rejected. Cut them before they reach the index.
     */
    private const MAX_TOKEN_BYTES = 2000;

    private Dao $dao;

    /** @var int Original byte length of galley tokens cut during the current file */
    private int $truncatedFrom = 0;

    /** @var int Stored byte length of galley tokens cut during the current file */
    private int $truncatedTo = 0;

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
        return PKPString::regexp_replace('/[\\p{C}\\p{M}\\p{P}\\p{S}\\p{Z}]+/', ' ', $text);
    }

    /**
     * Index a submission by extracting relevant text fields and storing them in the search index
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
            'title' => $this->fit($this->sanitize($this->implodeLocalized($publication->getFullTitles())), 'title', $submissionId),
            'abstract' => $this->fit($this->sanitize($this->implodeLocalized($publication->getData('abstract'))), 'abstract', $submissionId),
            'authors' => $this->fit($this->sanitize($this->implodeLocalized($authors)), 'authors', $submissionId),
            'keywords' => $this->fit($this->sanitize($this->implodeLocalized($this->flattenLocalizedArray($publication->getData('keywords')))), 'keywords', $submissionId),
            'subjects' => $this->fit($this->sanitize($this->implodeLocalized($this->flattenLocalizedArray($publication->getData('subjects')))), 'subjects', $submissionId),
            'disciplines' => $this->fit($this->sanitize($this->implodeLocalized($this->flattenLocalizedArray($publication->getData('disciplines')))), 'disciplines', $submissionId),
            'coverage' => $this->fit($this->sanitize($this->implodeLocalized((array) $publication->getData('coverage'))), 'coverage', $submissionId),
            'type' => $this->fit($this->sanitize($this->implodeLocalized((array) $publication->getData('type'))), 'type', $submissionId),
            // The metadata hook is called before the files hook, so even though it sounds risky, it's ok to clear the galley text here, as this code is unlikely to be changed
            'galley_text' => '',
        ];

        $this->dao->deleteChunks($submissionId);
        $this->dao->upsert($submissionId, $contextId, $fields);
    }

    /**
     * Index a submission file by extracting text content and updating the search index
     */
    public function indexSubmissionFile(Submission $submission, SubmissionFile $submissionFile): void
    {
        set_time_limit(0);
        $parser = SearchFileParser::fromFile($submissionFile);
        if (!$parser || !$parser->open()) {
            return;
        }

        $submissionId = (int) $submission->getId();
        $this->truncatedFrom = 0;
        $this->truncatedTo = 0;
        $this->dao->ensureSubmission($submissionId, (int) $submission->getData('contextId'));

        $buffer = '';
        while (($text = $parser->read()) !== false) {
            $buffer = $this->queueGalleyText($submissionId, $buffer, $this->sanitize((string) $text));
        }
        $parser->close();
        $this->flushGalleyChunk($submissionId, $buffer);

        if ($this->truncatedFrom > $this->truncatedTo) {
            error_log(sprintf(
                'fullTextSearch: submission %d field galley_text truncated from %d to %d bytes',
                $submissionId,
                $this->truncatedFrom,
                $this->truncatedTo
            ));
        }
    }

    /**
     * Keep a metadata field inside the chunk limit and warn when it is cut.
     */
    private function fit(string $text, string $field, int $submissionId): string
    {
        $originalBytes = strlen($text);
        $text = $this->cutLongTokens($text, false);
        if (mb_strlen($text) > Dao::CHUNK_CHARACTERS) {
            $text = mb_substr($text, 0, Dao::CHUNK_CHARACTERS);
        }
        $storedBytes = strlen($text);
        if ($storedBytes < $originalBytes) {
            error_log(sprintf(
                'fullTextSearch: submission %d field %s truncated from %d to %d bytes',
                $submissionId,
                $field,
                $originalBytes,
                $storedBytes
            ));
        }
        return $text;
    }

    /**
     * Sanitize is already applied. Flush a chunk once the buffer reaches the limit.
     */
    private function queueGalleyText(int $submissionId, string $buffer, string $piece): string
    {
        $piece = trim($this->cutLongTokens($piece, true));
        if ($piece === '') {
            return $buffer;
        }

        $pending = $buffer === '' ? $piece : $buffer . ' ' . $piece;
        $limit = Dao::CHUNK_CHARACTERS;
        while (mb_strlen($pending) >= $limit) {
            $window = mb_substr($pending, 0, $limit);
            $breakAt = mb_strrpos($window, ' ');
            if ($breakAt === false || $breakAt < 1) {
                $chunk = $window;
                $pending = ltrim(mb_substr($pending, $limit));
            } else {
                $chunk = mb_substr($window, 0, $breakAt);
                $pending = ltrim(mb_substr($pending, $breakAt + 1));
            }
            $this->flushGalleyChunk($submissionId, $chunk);
        }
        return $pending;
    }

    /**
     * Persist one non-empty galley chunk.
     */
    private function flushGalleyChunk(int $submissionId, string $buffer): void
    {
        $buffer = trim($buffer);
        if ($buffer === '') {
            return;
        }
        $this->dao->insertChunk($submissionId, $buffer);
    }

    /**
     * Cut tokens that Postgres would reject as lexemes.
     */
    private function cutLongTokens(string $text, bool $track): string
    {
        $cut = preg_replace_callback('/\S+/u', function (array $matches) use ($track): string {
            $token = $matches[0];
            if (strlen($token) <= self::MAX_TOKEN_BYTES) {
                return $token;
            }
            $short = mb_strcut($token, 0, self::MAX_TOKEN_BYTES, 'UTF-8');
            if ($track) {
                $this->truncatedFrom += strlen($token);
                $this->truncatedTo += strlen($short);
            }
            return $short;
        }, $text);

        return $cut === null ? $text : $cut;
    }

    /**
     * Delete a submission from the search index
     * @param int $submissionId The submission ID to delete
     */
    public function deleteSubmission(int $submissionId): void
    {
        $this->dao->deleteBySubmission($submissionId);
    }

    /**
     * Convert localized array data to a single string
     * @param array|null $localized The localized array data
     * @return string The flattened string
     */
    private function implodeLocalized(?array $localized): string
    {
        return trim(implode(' ', array_filter(array_map('strip_tags', (array) $localized))));
    }

    /**
     * Flatten a nested localized array structure
     * @param array|null $localized The nested localized array
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
     * @param ?Context $context The context
     * @param ?bool $log Whether to log the rebuild process
     * @param ?array $switches The switches to use for the rebuild process
     */
    public function rebuildIndex(?Context $context = null, ?bool $log = false, ?array $switches = []): void
    {
        $this->dao->rebuildIndex($context, $log, $switches);
    }
}
