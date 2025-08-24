<?php

/**
 * @file FullTextSearchPlugin.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class FullTextSearchPlugin
 *
 * @ingroup plugins_generic_fullTextSearch
 *
 * @brief Full-text search plugin that provides database-backed indexing for OJS submissions
 */

namespace APP\plugins\generic\fullTextSearch;

use APP\core\Application;
use APP\notification\NotificationManager;
use APP\plugins\generic\fullTextSearch\classes\Dao;
use APP\plugins\generic\fullTextSearch\classes\Indexer;
use APP\plugins\generic\fullTextSearch\classes\SearchService;
use APP\plugins\generic\fullTextSearch\classes\SettingsForm;
use Exception;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use PKP\config\Config;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class FullTextSearchPlugin extends GenericPlugin
{
    private bool $installed = false;

    /**
     * @copydoc Plugin::register
     *
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

        $this->ensureSchema();
        $this->registerIndexingHooks();
        $this->registerSearchHook();
        return true;
    }

    /**
     * Create the index entity if missing
     */
    private function ensureSchema(): void
    {
        try {
            $table = Dao::TABLE_NAME;
            if (DB::schema()->hasTable($table)) {
                $this->installed = true;
                return;
            }

            DB::schema()->create($table, function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->bigInteger('context_id');
                $table->bigInteger('submission_id')->unique();
                $table->text('title')->nullable();
                $table->text('abstract')->nullable();
                $table->text('authors')->nullable();
                $table->text('keywords')->nullable();
                $table->text('subjects')->nullable();
                $table->text('disciplines')->nullable();
                $table->text('coverage')->nullable();
                $table->longText('galley_text')->nullable();
                $table->text('type')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });

            $indexFormat = DB::connection() instanceof PostgresConnection
                ? "CREATE INDEX {$table}_%s ON {$table} USING GIN (to_tsvector('simple', coalesce(%s,'')))"
                : "ALTER TABLE {$table} ADD FULLTEXT {$table}_%s (%s)";

            // Add full-text indexes for individual fields
            foreach (['title', 'abstract', 'authors', 'keywords', 'subjects', 'disciplines', 'coverage', 'galley_text', 'type'] as $field) {
                DB::statement(sprintf($indexFormat, $field, $field));
            }

            $this->installed = true;
        } catch (Exception $e) {
            error_log("Failed to create the database entities\n{$e}");
        }
    }

    /**
     * Register hooks for indexing
     */
    private function registerIndexingHooks(): void
    {
        Hook::add('ArticleSearchIndex::articleMetadataChanged', [$this, 'articleMetadataChanged']);
        Hook::add('ArticleSearchIndex::submissionFileChanged', [$this, 'submissionFileChanged']);
        Hook::add('ArticleSearchIndex::submissionFileDeleted', [$this, 'submissionFileDeleted']);
        Hook::add('ArticleSearchIndex::articleDeleted', [$this, 'articleDeleted']);
        Hook::add('Publication::unpublish', [$this, 'publicationUnpublished']);
    }

    /**
     * Hook handler for article metadata changes
     */
    public function articleMetadataChanged(string $hookName, array $args): bool
    {
        [$submission] = $args;
        $indexer = new Indexer();
        $indexer->indexSubmission($submission);
        return true;
    }

    /**
     * Hook handler for submission file changes
     */
    public function submissionFileChanged(string $hookName, array $args): bool
    {
        [$submissionId, $type, $submissionFileId] = $args;
        $indexer = new Indexer();
        $indexer->indexSubmissionFile((int) $submissionId, (int) $submissionFileId);
        return true;
    }

    /**
     * Hook handler for submission file deletion
     */
    public function submissionFileDeleted(string $hookName, array $args): bool
    {
        [$submissionId] = $args;
        $indexer = new Indexer();
        $indexer->removeFileFromIndex((int) $submissionId);
        return true;
    }

    /**
     * Hook handler for article deletion
     */
    public function articleDeleted(string $hookName, array $args): bool
    {
        [$submissionId] = $args;
        $indexer = new Indexer();
        $indexer->deleteSubmission((int) $submissionId);
        return true;
    }

    /**
     * Hook handler for publication unpublishing
     */
    public function publicationUnpublished(string $hookName, array $args): bool
    {
        [$newPublication, $publication, $submission] = $args;
        $indexer = new Indexer();
        $indexer->deleteSubmission($submission->getId());
        return true;
    }

    /**
     * Register hook to provide ranked search results from the plugin table
     */
    private function registerSearchHook(): void
    {
        Hook::add('SubmissionSearch::retrieveResults', function (string $hookName, array $args): bool {
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
}
