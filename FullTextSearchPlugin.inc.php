<?php

/**
 * @file FullTextSearchPlugin.inc.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class FullTextSearchPlugin
 * @ingroup plugins_generic_fullTextSearch
 *
 * @brief Full-text search plugin that provides database-backed indexing for OJS/OMP/OPS submissions
 */

namespace APP\plugins\generic\fullTextSearch;

use APP\plugins\generic\fullTextSearch\classes\Indexer;
use APP\plugins\generic\fullTextSearch\classes\Migration;
use APP\plugins\generic\fullTextSearch\classes\SearchService;
use APP\plugins\generic\fullTextSearch\classes\SettingsForm;
use Application;
use Config;
use Exception;
use GenericPlugin;
use HookRegistry;
use JSONMessage;
use LinkAction;
use AjaxModal;
use NotificationManager;
use Services;
use Submission;
use SubmissionFile;

import('lib.pkp.classes.plugins.GenericPlugin');

class FullTextSearchPlugin extends GenericPlugin
{
    /** @var bool */
    private $autoLoaderRegistered = false;
    /** @var bool */
    private $disableStandardIndexing = false;
    /** @var bool */
    private $useFullTextSearch = false;

    /**
     * @copydoc Plugin::register
     * @param null|int $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }

        if (!$this->getEnabled() || !Config::getVar('general', 'installed')) {
            return true;
        }

        $this->disableStandardIndexing = (bool) $this->getSetting(CONTEXT_SITE, 'disableStandardIndexing');
        $this->useFullTextSearch = (bool) $this->getSetting(CONTEXT_SITE, 'useFullTextSearch');
        $this->useAutoLoader();
        $this->registerIndexingHooks();
        if ($this->useFullTextSearch) {
            $this->registerSearchHook();
        }

        return true;
    }

    /**
     * Registers a custom autoloader to handle the plugin namespace
     */
    private function useAutoLoader(): void
    {
        if ($this->autoLoaderRegistered) {
            return;
        }
        $this->autoLoaderRegistered = true;

        spl_autoload_register(function ($className) {
            $path = explode(__NAMESPACE__ . '\\', $className, 2);
            if (reset($path)) {
                return;
            }

            $path = explode('\\', end($path));
            $class = array_pop($path);
            $path = array_map(function ($name) {
                return strtolower($name[0]) . substr($name, 1);
            }, $path);
            $path[] = $class;
            $this->import(implode('.', $path));
        });
    }

    /**
     * @copydoc Plugin::getInstallMigration()
     */
    public function getInstallMigration()
    {
        $this->useAutoLoader();
        return new Migration($this);
    }

    /**
     * Register hooks for indexing
     */
    private function registerIndexingHooks(): void
    {
        // Metadata
        HookRegistry::register('ArticleSearchIndex::articleMetadataChanged', [$this, 'articleMetadataChanged']);
        HookRegistry::register('MonographSearchIndex::submissionMetadataChanged', [$this, 'articleMetadataChanged']);
        HookRegistry::register('MonographSearchIndex::monographMetadataChanged', [$this, 'articleMetadataChanged']);
        HookRegistry::register('PreprintSearchIndex::preprintMetadataChanged', [$this, 'articleMetadataChanged']);
        // Files
        HookRegistry::register('ArticleSearchIndex::submissionFilesChanged', [$this, 'submissionFilesChanged']);
        HookRegistry::register('MonographSearchIndex::submissionFilesChanged', [$this, 'submissionFilesChanged']);
        HookRegistry::register('PreprintSearchIndex::submissionFilesChanged', [$this, 'submissionFilesChanged']);
        // Submission deleted
        HookRegistry::register('ArticleSearchIndex::articleDeleted', [$this, 'articleDeleted']);
        HookRegistry::register('MonographSearchIndex::submissionDeleted', [$this, 'articleDeleted']);
        HookRegistry::register('PreprintSearchIndex::preprintDeleted', [$this, 'articleDeleted']);
        // Remove unpublished submission from index
        HookRegistry::register('Publication::unpublish', [$this, 'publicationUnpublished']);
        // Rebuild index
        HookRegistry::register('ArticleSearchIndex::rebuildIndex', [$this, 'rebuildIndex']);
        HookRegistry::register('MonographSearchIndex::rebuildIndex', [$this, 'rebuildIndex']);
        HookRegistry::register('PreprintSearchIndex::rebuildIndex', [$this, 'rebuildIndex']);
    }

    /**
     * Hook handler for rebuilding the index
     */
    public function rebuildIndex(string $hookName, array $args): bool
    {
        [$log, $context, $switches] = $args + [false, null, []];
        $indexer = new Indexer();
        $this->disableStandardIndexing = in_array('--skip-standard-index', $switches);
        if (!$this->disableStandardIndexing) {
            // As we're overriding the rebuildSearchIndex tool, we need to clear the standard index manually to mimic its behavior
            (new Dao())->clearStandardSearchTables();
        }

        $indexer->rebuildIndex($context, $log, $switches);
        return true;
    }

    /**
     * Hook handler for article metadata changes
     */
    public function articleMetadataChanged(string $hookName, array $args): bool
    {
        /** @var Submission $submission */
        [$submission] = $args;
        $indexer = new Indexer();
        try {
            $indexer->indexSubmission($submission);
        } catch(Exception $e) {
            error_log("Failed to index submission {$submission->getId()}\n{$e}");
        }

        return $this->disableStandardIndexing;
    }

    /**
     * Hook handler for submission file changes
     */
    public function submissionFilesChanged(string $hookName, array $args): bool
    {
        [$submission] = $args;
        import('lib.pkp.classes.submission.SubmissionFile'); // Load constant
        $submissionFilesIterator = Services::get('submissionFile')->getMany([
            'submissionIds' => [$submission->getId()],
            'fileStages' => [SUBMISSION_FILE_PROOF],
        ]);
        $indexer = new Indexer();
        /** @var SubmissionFile $submissionFile */
        foreach ($submissionFilesIterator as $submissionFile) {
            try {
                $indexer->indexSubmissionFile($submission, $submissionFile);
            } catch(Exception $e) {
                error_log("Failed to index submission file {$submissionFile->getId()}\n{$e}");
            }
            $dependentFilesIterator = Services::get('submissionFile')->getMany([
                'assocTypes' => [ASSOC_TYPE_SUBMISSION_FILE],
                'assocIds' => [$submissionFile->getId()],
                'submissionIds' => [$submission->getId()],
                'fileStages' => [SUBMISSION_FILE_DEPENDENT],
                'includeDependentFiles' => true,
            ]);
            foreach ($dependentFilesIterator as $dependentFile) {
                try {
                    $indexer->indexSubmissionFile($submission, $dependentFile);
                } catch(Exception $e) {
                    error_log("Failed to index submission file {$dependentFile->getId()}\n{$e}");
                }
            }
        }

        return $this->disableStandardIndexing;
    }

    /**
     * Hook handler for article deletion
     */
    public function articleDeleted(string $hookName, array $args): bool
    {
        [$submissionId] = $args;
        $indexer = new Indexer();
        $indexer->deleteSubmission((int) $submissionId);
        return $this->disableStandardIndexing;
    }

    /**
     * Hook handler for publication unpublishing
     */
    public function publicationUnpublished(string $hookName, array $args): bool
    {
        [$newPublication, $publication, $submission] = $args;
        $indexer = new Indexer();
        $indexer->deleteSubmission($submission->getId());
        return $this->disableStandardIndexing;
    }

    /**
     * Register hook to provide ranked search results from the plugin table
     */
    private function registerSearchHook(): void
    {
        HookRegistry::register('SubmissionSearch::retrieveResults', function (string $hookName, array $args): bool {
            [$context, $keywords, $publishedFrom, $publishedTo, $orderBy, $orderDir, $exclude, $page, $itemsPerPage, &$totalResults, &$error, &$results] = $args;
            try {
                $service = new SearchService();
                [$ids, $total] = $service->search($context, (array) $keywords, (string) $orderBy, (string) $orderDir, (array) $exclude, (int) $page, (int) $itemsPerPage, $publishedFrom, $publishedTo);
                $totalResults = $total;
                $results = $ids;
            } catch (Exception $e) {
                $error = __('plugins.generic.fullTextSearch.search.error');
            }
            return true;
        });
    }

    /**
     * @copydoc Plugin::getName()
     */
    public function getName(): string
    {
        $class = explode('\\', __CLASS__);
        return end($class);
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.fullTextSearch.name');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.generic.fullTextSearch.description');
    }

    /**
     * @copydoc Plugin::isSitePlugin()
     */
    public function isSitePlugin(): bool
    {
        return true;
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs): array
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        array_unshift(
            $actions,
            new LinkAction(
                'settings',
                new AjaxModal($router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']), $this->getDisplayName()),
                __('manager.plugins.settings'),
                null
            )
        );
        return $actions;
    }

    /**
     * Generate a JSONMessage response to display the settings
     */
    private function displaySettings(): JSONMessage
    {
        $form = new SettingsForm($this);
        $request = Application::get()->getRequest();
        if ($request->getUserVar('save')) {
            $form->readInputData();
            if ($form->validate()) {
                $form->execute();
                $notificationManager = new NotificationManager();
                $notificationManager->createTrivialNotification($request->getUser()->getId());
                return new JSONMessage(true);
            }
        } else {
            $form->initData();
        }

        return new JSONMessage(true, $form->fetch($request));
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') === 'settings') {
            return $this->displaySettings();
        }

        return parent::manage($args, $request);
    }

    /**
     * @copydoc Plugin::getInstallSitePluginSettingsFile()
     */
    public function getInstallSitePluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }
}
