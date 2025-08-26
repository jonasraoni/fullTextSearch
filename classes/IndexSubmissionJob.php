<?php

/**
 * @file classes/IndexSubmissionJob.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class IndexSubmissionJob
 *
 * @ingroup jobs
 *
 * @brief Class to handle the submission indexing
 */

namespace APP\plugins\generic\fullTextSearch\classes;

use APP\plugins\generic\fullTextSearch\FullTextSearchPlugin;
use PKP\jobs\submissions\UpdateSubmissionSearchJob;
use PKP\plugins\PluginRegistry;

class IndexSubmissionJob extends UpdateSubmissionSearchJob
{
    /**
     * Constructor
     */
    public function __construct(int $submissionId, private bool $disableStandardIndexing)
    {
        parent::__construct($submissionId);
    }

    /**
     * Index the submission, ensuring the proper plugin flag to enable/disable standard indexing is the same as the one used to create the job
     */
    public function handle(): void
    {
        /** @var FullTextSearchPlugin $plugin */
        $plugin = PluginRegistry::getPlugin('generic', 'FullTextSearchPlugin');
        if ($plugin) {
            $plugin->disableStandardIndexing = $this->disableStandardIndexing;
        }

        parent::handle();
    }
}
