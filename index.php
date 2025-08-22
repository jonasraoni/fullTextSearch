<?php

/**
 * @file index.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Wrapper for the Full Text Search plugin
 *
 * @ingroup plugins_generic_fullTextSearch
 */

namespace APP\plugins\generic\fullTextSearch;

require_once 'FullTextSearchPlugin.inc.php';
return new FullTextSearchPlugin();
