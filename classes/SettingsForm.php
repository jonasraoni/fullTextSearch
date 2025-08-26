<?php

/**
 * @file classes/SettingsForm.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SettingsForm
 *
 * @ingroup plugins_generic_fullTextSearch
 *
 * @brief Settings form for the Full Text Search plugin
 */

namespace APP\plugins\generic\fullTextSearch\classes;

use APP\core\Application;
use APP\notification\Notification;
use APP\notification\NotificationManager;
use APP\plugins\generic\fullTextSearch\FullTextSearchPlugin;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

class SettingsForm extends Form
{
    private Dao $dao;

    /**
     * @copydoc Form::__construct
     */
    public function __construct(public FullTextSearchPlugin $plugin)
    {
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        $this->dao = new Dao();
    }

    /**
     * @copydoc Form::initData
     */
    public function initData(): void
    {
        $this->setData('contexts', $this->dao->getAllContexts());
        $this->setData('useFullTextSearch', $this->plugin->getSetting(Application::CONTEXT_SITE, 'useFullTextSearch'));
        $this->setData('disableStandardIndexing', $this->plugin->getSetting(Application::CONTEXT_SITE, 'disableStandardIndexing'));
        parent::initData();
    }

    /**
     * @copydoc Form::readInputData
     */
    public function readInputData(): void
    {
        $vars = ['selectedContexts', 'clearStandardSearch', 'useFullTextSearch', 'disableStandardIndexing'];
        $this->readUserVars($vars);
        parent::readInputData();
    }

    /**
     * @copydoc Form::fetch
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false): string
    {
        $templateManager = TemplateManager::getManager($request);
        $templateManager->assign('pluginName', $this->plugin->getName());
        return parent::fetch($request, $template);
    }

    /**
     * @copydoc Form::execute
     */
    public function execute(...$functionArgs)
    {
        $selectedContexts = (array) $this->getData('selectedContexts');
        $clearStandardSearch = (bool) $this->getData('clearStandardSearch');
        $useFullTextSearch = (bool) $this->getData('useFullTextSearch');
        $disableStandardIndexing = (bool) $this->getData('disableStandardIndexing');

        // Save the new settings
        $this->plugin->updateSetting(Application::CONTEXT_SITE, 'useFullTextSearch', $useFullTextSearch);
        $this->plugin->updateSetting(Application::CONTEXT_SITE, 'disableStandardIndexing', $disableStandardIndexing);

        if ($clearStandardSearch) {
            $this->dao->clearStandardSearchTables();
        }

        if (!empty($selectedContexts)) {
            $this->dao->rebuildSearchIndex($selectedContexts, false, ['--skip-standard-index']);

            $notificationManager = new NotificationManager();
            $notificationManager->createTrivialNotification(
                Application::get()->getRequest()->getUser()->getId(),
                Notification::NOTIFICATION_TYPE_SUCCESS,
                ['contents' => __('plugins.generic.fullTextSearch.rebuildComplete')]
            );
        }

        return parent::execute(...$functionArgs);
    }
}
