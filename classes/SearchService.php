<?php

/**
 * @file classes/SearchService.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SearchService
 *
 * @ingroup plugins_generic_fullTextSearch
 *
 * @brief Service layer for full-text search operations
 */

namespace APP\plugins\generic\fullTextSearch\classes;

class SearchService
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
     * Perform a search using the full-text index
     *
     * @param mixed $context The context object or null for all contexts
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
    public function search($context, array $keywords, string $orderBy, string $orderDirection, array $exclude, int $page, int $perPage, $publishedFrom = null, $publishedTo = null): array
    {
        return $this->dao->search($context, $keywords, $orderBy, $orderDirection, $exclude, $page, $perPage, $publishedFrom, $publishedTo);
    }
}
