<?php

/**
 * @file plugins/importexport/rsciexport/classes/form/RSCIExportSettingsForm.inc.php
 * @class RSCISettingsForm
 * @ingroup plugins_importexport_rsciexport
 *
 * @brief Form for journal managers to setup RSCI plugin
 */


namespace APP\plugins\importexport\rsciexport\classes\form;

use APP\plugins\importexport\rsciexport\RSCIExportPlugin;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorCSRF;

class RSCIExportSettingsForm extends Form {

    //
    // Private properties
    //
    /** @var integer */
    var $_contextId;

    /**
     * Get the context ID.
     * @return integer
     */
    function _getContextId() {
        return $this->_contextId;
    }

    /** @var RSCIExportPlugin */
    var $_plugin;

    /**
     * Get the plugin.
     * @return RSCIExportPlugin
     */
    function _getPlugin() {
        return $this->_plugin;
    }


    //
    // Constructor
    //
    /**
     * Constructor
     * @param $plugin RSCIExportPlugin
     * @param $contextId integer
     */
    function __construct($plugin, $contextId) {
        $this->_contextId = $contextId;
        $this->_plugin = $plugin;

        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        // Add form validation checks.
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }


    //
    // Implement template methods from Form
    //
    /**
     * @copydoc Form::initData()
     */
    function initData() {
        $contextId = $this->_getContextId();
        $plugin = $this->_getPlugin();
        foreach($this->getFormFields() as $fieldName => $fieldType) {
            $this->setData($fieldName, $plugin->getSetting($contextId, $fieldName));
        }
    }

    /**
     * @copydoc Form::fetch()
     *
     * Adds the choices for "Full texts in the archive": in the language of the article,
     * each submission language of the journal, or all language versions.
     */
    function fetch($request, $template = null, $display = false) {
        $options = array('article' => __('plugins.importexport.rsciexport.settings.form.galleySelection.article'));
        foreach ($request->getContext()->getSupportedSubmissionLocaleNames() as $locale => $name) {
            $options[$locale] = $name;
        }
        $options['all'] = __('plugins.importexport.rsciexport.settings.form.galleySelection.all');

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('galleySelectionOptions', $options);
        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::readInputData()
     */
    function readInputData() {
        $this->readUserVars(array_keys($this->getFormFields()));
    }

    /**
     * Execute the form.
     */
    function execute(...$functionArgs) {
        $plugin = $this->_getPlugin();
        $contextId = $this->_getContextId();
        foreach($this->getFormFields() as $fieldName => $fieldType) {
            $plugin->updateSetting($contextId, $fieldName, $this->getData($fieldName), $fieldType);
        }
        parent::execute(...$functionArgs);
    }


    //
    // Public helper methods
    //
    /**
     * Get form fields
     * @return array (field name => field type)
     */
    function getFormFields() {
        return array(
            'exportSections' => 'bool',
            'exportArtTypeFromSectionAbbrev' => 'bool',
            'journalRSCITitleId' => 'int',
            'galleySelection' => 'string'
        );
    }
}


