<?php

/**
 * @file plugins/importexport/rsciexport/RSCIExportPlugin.php
 * @class RSCIExportPlugin
 * @ingroup plugins_importexport_rsci
 *
 * @brief RSCI XML export plugin.
 */

namespace APP\plugins\importexport\rsciexport;

use APP\facades\Repo;
use APP\journal\JournalDAO;
use APP\publication\Publication;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\config\Config;
use PKP\context\Context;
use PKP\core\Core;
use PKP\core\JSONMessage;
use PKP\core\PKPRequest;
use PKP\core\Registry;
use PKP\db\DAORegistry;
use PKP\file\FileManager;
use PKP\galley\Galley;
use APP\notification\Notification;
use PKP\plugins\ImportExportPlugin;
use APP\notification\NotificationManager;
use APP\plugins\importexport\rsciexport\classes\form\RSCIExportSettingsForm;
use APP\plugins\importexport\rsciexport\filter\ArticleRSCIXmlFilter;
use PKP\submission\PKPSubmission;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use ZipArchive;

class RSCIExportPlugin extends ImportExportPlugin
{
    /**
     * @copyDoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        $this->addLocaleData();
        return $success;
    }

    /**
     * Display the plugin.
     * @param $args array
     * @param $request PKPRequest
     */
    function display($args, $request)
    {
        parent::display($args, $request);
        $templateMgr = TemplateManager::getManager($request);
        $journal = $request->getJournal();

        switch (array_shift($args)) {
            case 'index':
            case '':
                // Report of the last export (see createIssueZip()), shown under the export form.
                $templateMgr->assign('lastExportReport', $this->getSetting($journal->getId(), 'lastExportReport'));
                $templateMgr->display($this->getTemplateResource('index.tpl'));
                break;
            case 'exportIssue':
                $issueIdsArr = (array) $request->getUserVar('selectedIssues');
                if (count($issueIdsArr) > 1 || count($issueIdsArr) < 1)
                {
                    $user = $request->getUser();
                    $notificationManager = new NotificationManager();
                    $notificationManager->createTrivialNotification($user->getId(), Notification::NOTIFICATION_TYPE_ERROR, array('pluginName' => $this->getDisplayName(), 'contents' => "Choose one issue."));
                    $request->redirectUrl(str_replace("exportIssue", "", $request->getRequestPath()));
                    break;
                }
                else {
                    $issueId = $issueIdsArr[0];
                    $exportXml = $this->exportIssue(
                        $issueId,
                        $request->getContext()
                    );
                    $this->_uploadZip($issueId, $exportXml);
                    break;
                }
            default:
                $dispatcher = $request->getDispatcher();
                throw new NotFoundHttpException();
        }
    }

    /**
     * @copydoc Plugin::manage()
     */
    function manage($args, $request) {
        $user = $request->getUser();

        $settingsForm = new RSCIExportSettingsForm($this, $request->getContext()->getId());
        $notificationManager = new NotificationManager();
        switch ($request->getUserVar('verb')) {
            case 'save':
                $settingsForm->readInputData();
                if ($settingsForm->validate()) {
                    $settingsForm->execute([]);
                    $notificationManager->createTrivialNotification($user->getId(), Notification::NOTIFICATION_TYPE_SUCCESS);
                    return new JSONMessage();
                } else {
                    return new JSONMessage(true, $settingsForm->fetch($request));
                }
            case 'index':
                $settingsForm->initData();
                return new JSONMessage(true, $settingsForm->fetch($request));
        }
        return parent::manage($args, $request);
    }

    /**
     * Get the zip with XML for an issue.
     * @param $issueId int
     * @param $context Context
     * @return string XML contents representing the supplied issue IDs.
     */
    function exportIssue($issueId, $context)
    {
        $issue = Repo::issue()->get($issueId);
        $filterDao = DAORegistry::getDAO('FilterDAO');
        $rsciExportFilters = $filterDao->getObjectsByGroup('issue=>rsci-xml');
        assert(count($rsciExportFilters) == 1); // Assert only a single serialization filter
        $exportFilter = array_shift($rsciExportFilters);
        $exportSettings = array ('isExportArtTypeFromSectionAbbrev' => $this->getSetting($context->getId(), 'exportArtTypeFromSectionAbbrev'),
                                    'isExportSections' => $this->getSetting($context->getId(), 'exportSections'),
                                    'journalRSCITitleId' => $this->getSetting($context->getId(), 'journalRSCITitleId'),
                                    'galleySelection' => $this->getSetting($context->getId(), 'galleySelection'));
        $exportFilter->SetExportSettings($exportSettings);
        libxml_use_internal_errors(true);
        $issueXml = $exportFilter->execute($issue, true);
        $xml = $issueXml->saveXml();
        return $xml;
    }

    /**
     * @param $issueId int
     * @param $xml string XML file content
     */
    protected function _uploadZip($issueId, $xml)
    {
        $fileManager = new FileManager();
        $zipPath = $this->createIssueZip($issueId, $xml);

        // A short pop-up note that the export had problems; the list itself is on the plugin page,
        // because pop-up notes disappear after a few seconds and show plain text only.
        if (!empty($this->_exportProblems))
        {
            $request = Registry::get('request');
            $notificationManager = new NotificationManager();
            $notificationManager->createTrivialNotification($request->getUser()->getId(), Notification::NOTIFICATION_TYPE_WARNING,
                array('contents' => __('plugins.importexport.rsciexport.report.notification', array('count' => count($this->_exportProblems)))));
        }

        // UPLOAD:
        $fileManager->downloadByPath($zipPath);
        $fileManager->rmtree($this->getExportPath());
    }

    /**
     * Create the zip with XML, cover image, and article files for an issue.
     * @param $issueId int
     * @param $xml string XML file content
     * @return string Path to the generated zip file.
     */
    protected function createIssueZip($issueId, $xml)
    {
        $fileManager = new FileManager();
        $xmlFileName = $this->getExportPath() . 'Markup_unicode.xml';
        $fileManager->writeFile($xmlFileName, $xml);

        $issue = Repo::issue()->get($issueId);
        $journal = DAORegistry::getDAO('JournalDAO')->getById($issue->getJournalId());
        // What did not get into the archive and why: shown on the plugin page and in the console.
        $problems = array();

        // Copy the cover from disk under the same name the XML refers to (see getIssueCoverToRSCI()).
        $cover = ArticleRSCIXmlFilter::getIssueCoverToRSCI($issue);
        $coverImage = $issue->getLocalizedData('coverImage', $journal->getPrimaryLocale());
        if ($cover)
            $this->_copyToArchive($cover['path'], $cover['name'], $fileManager, $problems);
        elseif ($coverImage)
            $problems[] = __('plugins.importexport.rsciexport.problem.coverFileMissing', array('file' => $coverImage));
        else
            $problems[] = __('plugins.importexport.rsciexport.problem.noCover');

        $submissionsIterator = Repo::submission()->getCollector()
            ->filterByContextIds([$issue->getData('journalId')])
            ->filterByIssueIds([$issue->getId()])
            ->filterByStatus([PKPSubmission::STATUS_PUBLISHED, PKPSubmission::STATUS_SCHEDULED])
            ->GetMany();
        /** @var Submission[] $publiations */
        $submissions = iterator_to_array($submissionsIterator);
//        $publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO');
//        $articles = $publishedArticleDao->getPublishedArticles($issue->getId());


        // Setting "Full texts in the archive": the same value the XML filter gets in exportIssue().
        $galleySelection = $this->getSetting($issue->getData('journalId'), 'galleySelection');
        $languageNames = $journal->getSupportedSubmissionLocaleNames();
        // In page order, as in the XML: the report reads top to bottom, and if two articles
        // have the same pages, the first one keeps its file.
        usort($submissions, fn ($a, $b) => (int) $a->getCurrentPublication()->getStartingPage() <=> (int) $b->getCurrentPublication()->getStartingPage());
        $articleByFileName = array();
        foreach ($submissions as $submission)
        {
            $publication = $submission->getCurrentPublication();
            $article = $this->_describeArticle($publication, $journal);
            // Copy the article's full texts from disk under the same names the XML refers to
            // (see getPublicationFilesToRSCI()).
            $fileProblems = array();
            foreach (ArticleRSCIXmlFilter::getPublicationFilesToRSCI($publication, $galleySelection, $fileProblems) as $file)
            {
                // Two articles with the same pages get the same file name: keep the first file
                // instead of silently replacing it, and report it.
                if (isset($articleByFileName[$file['name']]))
                {
                    $problems[] = __('plugins.importexport.rsciexport.problem.sameName',
                        array('article' => $article, 'file' => $file['name'], 'otherArticle' => $articleByFileName[$file['name']]));
                    continue;
                }
                $articleByFileName[$file['name']] = $article;
                $this->_copyToArchive($file['path'], $file['name'], $fileManager, $problems);
            }
            foreach ($fileProblems as $fileProblem)
            {
                $problems[] = $this->_describeFileProblem($fileProblem, $article, $languageNames);
            }

            // Pages are required by the RSCI schema, and Metafora rejects articles without them.
            if (trim((string) $publication->getData('pages')) === '')
            {
                $problems[] = __('plugins.importexport.rsciexport.problem.noPages', array('article' => $article));
            }

            // Article dates go into the XML (see _createDatesNode()); report what looks wrong.
            $dateSubmitted = $submission->getData('dateSubmitted');
            $datePublished = $publication->getData('datePublished');
            if (empty($dateSubmitted))
            {
                $problems[] = __('plugins.importexport.rsciexport.problem.noDateReceived', array('article' => $article));
            }
            elseif (!empty($datePublished) && substr($dateSubmitted, 0, 10) > $datePublished)
            {
                // Typical for articles moved from older OJS versions: the "submitted" date is the date of the move.
                $problems[] = __('plugins.importexport.rsciexport.problem.receivedAfterPublished', array(
                    'article' => $article,
                    'received' => date('d.m.Y', strtotime($dateSubmitted)),
                    'published' => date('d.m.Y', strtotime($datePublished)),
                ));
            }
        }

        // ZIP:
        $zip = new ZipArchive();
        $zipPath = $this->getExportPath().'issue-'.$issue->getNumber().'-'.$issue->getYear() . '.zip';
        if ($zip->open($zipPath, ZipArchive::CREATE)!==TRUE) {
            exit('Невозможно создать архив ZIP (' . $zipPath . '\n');
        }
        $filesToArchive = scandir($this->getExportPath());

        foreach($filesToArchive as $file) {
            if (is_file($this->getExportPath(). $file) && $this->getExportPath() . $file != $zipPath) {
                $zip->addFile($this->getExportPath() . $file, basename($file));
            }
        }
        $zip->close();

        // Keep the report: the web interface shows it on the plugin page, the CLI prints it.
        $this->_exportProblems = $problems;
        $this->updateSetting($issue->getJournalId(), 'lastExportReport', array(
            'issue' => $issue->getIssueIdentification(),
            'date' => Core::getCurrentDate(),
            'problems' => $problems,
        ), 'object');

        return $zipPath;
    }

    /**
     * Problems found by the last createIssueZip() call (messages for the export report).
     * @var string[]
     */
    protected $_exportProblems = array();

    /**
     * Copies a file into the export directory. If it fails, adds the reason to the report
     * instead of skipping the file silently.
     * @param $sourcePath string file on disk
     * @param $nameInArchive string
     * @param $fileManager FileManager
     * @param $problems string[] report messages
     */
    protected function _copyToArchive($sourcePath, $nameInArchive, $fileManager, &$problems)
    {
        if (!is_file($sourcePath))
        {
            $problems[] = __('plugins.importexport.rsciexport.problem.fileNotFound', array('file' => $nameInArchive, 'path' => $sourcePath));
        }
        elseif (!$fileManager->copyFile($sourcePath, $this->getExportPath() . $nameInArchive))
        {
            $error = error_get_last();
            $problems[] = __('plugins.importexport.rsciexport.problem.copyFailed', array('file' => $nameInArchive, 'reason' => $error['message'] ?? ''));
        }
    }

    /**
     * How an article is named in the report: its pages and the beginning of its title.
     * @param $publication Publication
     * @param $journal Context
     * @return string
     */
    protected function _describeArticle($publication, $journal)
    {
        $title = mb_strimwidth((string) $publication->getLocalizedTitle($journal->getPrimaryLocale()), 0, 60, '…');
        $pages = (string) $publication->getData('pages');
        return $pages !== ''
            ? __('plugins.importexport.rsciexport.problem.article', array('pages' => $pages, 'title' => $title))
            : __('plugins.importexport.rsciexport.problem.articleNoPages', array('id' => $publication->getData('submissionId'), 'title' => $title));
    }

    /**
     * Turns a problem found while choosing an article's full texts (see getPublicationFilesToRSCI())
     * into a report message.
     * @param $fileProblem array
     * @param $article string see _describeArticle()
     * @param $languageNames array locale => language name
     * @return string
     */
    protected function _describeFileProblem($fileProblem, $article, $languageNames)
    {
        $languageName = fn ($locale) => $languageNames[$locale] ?? $locale;
        switch ($fileProblem['type'])
        {
            case 'noPdf':
                return __('plugins.importexport.rsciexport.problem.noPdf', array('article' => $article));
            case 'otherLanguage':
                return __('plugins.importexport.rsciexport.problem.otherLanguage', array(
                    'article' => $article,
                    'wanted' => implode(' / ', array_map($languageName, array_unique(array_filter($fileProblem['wanted'])))),
                    'taken' => $languageName($fileProblem['taken']),
                ));
            case 'duplicateLanguage':
                return __('plugins.importexport.rsciexport.problem.duplicateLanguage', array('article' => $article, 'language' => $languageName($fileProblem['locale'])));
            case 'fileMissing':
                return __('plugins.importexport.rsciexport.problem.galleyFileMissing', array('article' => $article, 'path' => $fileProblem['path']));
        }
        return $article;
    }

    /**
     * @var string
     */
    protected $_generatedTempPath = '';

    /**
     * @var int
     */
    protected $_exportContextId = 0;

    /**
     * @copydoc ImportExportPlugin::getExportPath()
     */
    function getExportPath()
    {
        if ($this->_generatedTempPath === '')
        {
            $exportPath = parent::getExportPath();
            $request = Registry::get('request', false);
            $journal = $request ? $request->getJournal() : null;
            $contextId = $journal ? $journal->getId() : $this->_exportContextId;
            $this->_generatedTempPath =  $exportPath . $this->getPluginSettingsPrefix() . 'Temp-' . date('Ymd-His'). $contextId . '/';
        }
        return $this->_generatedTempPath;
    }

    /**
     * @copydoc ImportExportPlugin::getPluginSettingsPrefix()
     */
    function getPluginSettingsPrefix() {
        return 'rsciexport';
    }

    /**
     * Execute import/export tasks using the command-line interface.
     * @param $scriptName The name of the command-line script (displayed as usage info)
     * @param $args Parameters to the plugin
     */
    function executeCLI($scriptName, &$args)
    {
        $command = array_shift($args);

        if ($command == 'export') {
            $zipFile = array_shift($args);
        } else {
            $zipFile = $command;
        }

        $journalPath = array_shift($args);
        $issueArg = array_shift($args);
        $issueId = $issueArg == 'issue' ? array_shift($args) : $issueArg;

        $journalDao = DAORegistry::getDAO('JournalDAO'); /** @var JournalDAO $journalDao */
        $journal = $journalDao->getByPath($journalPath);

        if (!$journal) {
            if ($journalPath != '') {
                echo __('plugins.importexport.common.cliError') . "\n";
                echo __('plugins.importexport.common.error.unknownContext', ['contextPath' => $journalPath]) . "\n\n";
            }
            $this->usage($scriptName);
            return;
        }

        if (!$zipFile || !$issueId) {
            $this->usage($scriptName);
            return;
        }

        if ($this->isRelativePath($zipFile) && !preg_match('/^[A-Za-z]:[\/\\\\]/', $zipFile) && substr($zipFile, 0, 2) !== '\\\\') {
            $zipFile = PWD . '/' . $zipFile;
        }

        $outputDir = dirname($zipFile);
        if (!is_writable($outputDir) || (file_exists($zipFile) && !is_writable($zipFile))) {
            echo __('plugins.importexport.common.cliError') . "\n";
            echo __('plugins.importexport.common.export.error.outputFileNotWritable', ['param' => $zipFile]) . "\n\n";
            $this->usage($scriptName);
            return;
        }

        $issue = Repo::issue()->getByBestId($issueId, $journal->getId());
        if (!$issue) {
            echo __('plugins.importexport.common.cliError') . "\n";
            echo __('plugins.importexport.rsciexport.export.error.issueNotFound', ['issueId' => $issueId]) . "\n\n";
            return;
        }

        $this->_exportContextId = $journal->getId();
        $exportXml = $this->exportIssue($issue->getId(), $journal);
        $zipPath = $this->createIssueZip($issue->getId(), $exportXml);

        // Export report (see createIssueZip()).
        if (!empty($this->_exportProblems)) {
            echo __('plugins.importexport.rsciexport.report.title') . ":\n";
            foreach ($this->_exportProblems as $problem) {
                echo ' - ' . $problem . "\n";
            }
        }

        $fileManager = new FileManager();
        if (!$fileManager->copyFile($zipPath, $zipFile)) {
            echo __('plugins.importexport.common.cliError') . "\n";
            echo __('plugins.importexport.common.export.error.outputFileNotWritable', ['param' => $zipFile]) . "\n\n";
            $fileManager->rmtree($this->getExportPath());
            return;
        }
        $fileManager->rmtree($this->getExportPath());

        return true;
    }

    /**
     * Display the command-line usage information
     * @param $scriptName string
     */
    function usage($scriptName)
    {
        echo __('plugins.importexport.rsciexport.cliUsage', [
            'scriptName' => $scriptName,
            'pluginName' => $this->getName()
        ]) . "\n";
    }

    /**
     * Get the name of this plugin. The name must be unique within
     * its category, and should be suitable for part of a filename
     * (ie short, no spaces, and no dependencies on cases being unique).
     *
     * @return string name of plugin
     */
    function getName(): string
    {
        return "RSCIExportPlugin";
    }

    /**
     * Get the display name for this plugin.
     *
     * @return string
     */
    function getDisplayName(): string
    {
        return __('plugins.importexport.rsciexport.displayName');
    }

    /**
     * Get a description of this plugin.
     *
     * @return string
     */
    function getDescription(): string
    {
        return __('plugins.importexport.rsciexport.description');
    }
}
