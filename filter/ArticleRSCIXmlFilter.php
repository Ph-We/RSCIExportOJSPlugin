<?php

/**
 * @file plugins/importexport/rsciexport/filter/ArticleRSCIXmlFilter.php
 * @class ArticleRSCIXmlFilter
 * @ingroup plugins_importexport_rsciexport
 *
 * @brief Class that converts an Issue to a RSCI XML document.
 */


namespace APP\plugins\importexport\rsciexport\filter;

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\file\PublicFileManager;
use APP\issue\Issue;
use APP\journal\Journal;
use APP\notification\NotificationManager;
use APP\publication\Publication;
use APP\section\Section;
use APP\submission\Submission;
use DOMDocument;
use DOMElement;
use Matriphe\ISO639\ISO639;
use APP\author\Author;
use PKP\citation\Citation;
use PKP\citation\CitationDAO;
use PKP\config\Config;
use PKP\context\Context;
use PKP\core\Registry;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\galley\Galley;
use APP\notification\Notification;
use PKP\submission\PKPSubmission;
use PKP\filter\PersistableFilter;


class ArticleRSCIXmlFilter extends PersistableFilter {

	//
	// Implement template methods from Filter
	//
	/**
	 * @see Filter::process()
	 * @param $issue Issue
	 * @return DOMDocument|false
	 */
	function &process(&$issue)
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        /** @var Request $request */
        $request = Registry::get('request', false);
        $journal = $request ? $request->getJournal() : null;
        if (!$journal) {
            $journalDao = DAORegistry::getDAO('JournalDAO');
            $journal = $journalDao->getById($issue->getData('journalId'));
        }
        $this->_context = $journal;

        $langs = array();
        foreach ($journal->getSupportedLocales() as $locale)
        {
            $langs[] = $this->_convertLocaleToISO639($locale);
        }

        $doc->appendChild($this->_createJournalNode($doc, $journal, $issue, $langs));

        return $doc;
	}


    /**
     * Construct and return journal node and nested nodes.
     * @param $doc DOMDocument
     * @param $journal Journal
     * @param $issue Issue
     * @param $langs string[]
     * @return DOMElement|false
     */
    protected function _createJournalNode($doc, $journal, $issue, $langs)
    {
        $journalNode = $doc->createElement('journal');
        $journalNode->appendChild($doc->createElement('titleid', $this->_exportSettings['journalRSCITitleId']));

        $issn = $journal->getData('printIssn');
        if ($issn != '')
        $journalNode->appendChild($doc->createElement('issn', $issn));

        $eissn = $journal->getData('onlineIssn');
        if ($eissn != '')
            $journalNode->appendChild($doc->createElement('eissn', $eissn));

        $primaryLocale = $journal->getPrimaryLocale();
        $journalNode->appendChild($this->_createJournalInfoNode($doc, $journal, $primaryLocale));

        $journalNode->appendChild($this->_createIssueNode($doc, $issue, $langs));

        return $journalNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $journal Journal
     * @param $locale string
     * @return DOMElement|false
     */
    protected function _createJournalInfoNode($doc, $journal, $locale)
    {
        $journalInfoNode = $doc->createElement('journalInfo');
        $journalInfoNode->setAttribute('lang', $this->_convertLocaleToISO639($locale));
        $journalInfoNode->appendChild($doc->createElement('title', htmlentities($journal->getName($locale), ENT_XML1)));

        return $journalInfoNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $issue Issue
     * @param $langs string[]
     * @return DOMElement|false
     */
    protected function _createIssueNode($doc, $issue, $langs)
    {
        $issueNode = $doc->createElement('issue');
        $issueNode->appendChild($doc->createElement('volume', $issue->getVolume()));
        $issueNode->appendChild($doc->createElement('number', $issue->getNumber()));
        //$issueNode->appendChild($doc->createElement('altNumber', ''));
        $issueNode->appendChild($doc->createElement('pages', $this->_getIssuePages($issue)));
        $issueNode->appendChild($doc->createElement('dateUni', $issue->getYear()));

        // The cover goes into the archive as "cover.<ext>" (see getIssueCoverToRSCI()),
        // so the XML refers to that name. No cover - no <files> block for the issue.
        $cover = self::getIssueCoverToRSCI($issue);
        if ($cover)
        {
            $filesNode = $doc->createElement('files');
            $coverFileNode = $doc->createElement('file', $cover['name']);
            $coverFileNode->setAttribute('desc', 'cover');
            $filesNode->appendChild($coverFileNode);
            $issueNode->appendChild($filesNode);
        }
        $issueNode->appendChild($this->_createArticlesNode($doc, $issue, $langs));

        return $issueNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $issue Issue
     * @param $langs string[]
     * @return DOMElement|false
     */
    protected function _createArticlesNode($doc, $issue, $langs)
    {
        $articlesNode = $doc->createElement('articles');
        $publications = $this->_getPublications($issue);
        $publications = $this->_sortPublicationsByPages($publications);
        $lastSectionId = null;

        foreach ($publications as $publication)
        {
            if ($this->_exportSettings['isExportSections'] && ($lastSectionId !== $publication->getData('sectionId')))
            {
                $articlesNode->appendChild($this->_createSectionNode($doc, $publication, $langs));
            }

            $articlesNode->appendChild($this->_createArticleNode($doc, $publication, $langs));
            $lastSectionId = $publication->getData('sectionId');
        }

        return $articlesNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $publication Publication
     * @param $langs string[]
     * @return DOMElement|false
     */
    protected function _createArticleNode($doc, $publication, $langs)
    {
//        /** @var SubmissionDAO $submissionDAO */
//        $submissionDAO = DAORegistry::getDAO('SubmissionDAO');
//        $submission = $submissionDAO->getById($publication->getId());
        $articleNode = $doc->createElement('article');
        $articleNode->appendChild($doc->createElement('pages', $publication->getData('pages')));

        $section = Repo::section()->get($publication->getData('sectionId'));
        $sectionAbbrev = $section->getAbbrev(Locale::getPrimaryLocale());

        if ($this->_exportSettings['isExportArtTypeFromSectionAbbrev'] && in_array($sectionAbbrev, $this->_artTypes))
        {
            $articleNode->appendChild($doc->createElement('artType', $sectionAbbrev));
        }
        else
        {
            $articleNode->appendChild($doc->createElement('artType', 'RAR'));
        }

        // Language of the article (the submission language in OJS), e.g. <langPubl>RUS</langPubl>.
        if ($publication->getData('locale'))
        {
            $articleNode->appendChild($doc->createElement('langPubl', $this->_convertLocaleToISO639($publication->getData('locale'))));
        }

        $authorsNode = $doc->createElement('authors');
        $authors = $publication->getData('authors');
        $authorCount = 1;
        foreach ($authors as $author)
        {
            $authorsNode->appendChild($this->_createAuthorNode($doc, $author, $authorCount, $langs));
            $authorCount++;
        }
        $articleNode->appendChild($authorsNode);

        $articleNode->appendChild($this->_createArtTitlesNode($doc, $publication, $langs));
        $articleNode->appendChild($this->_createAbstractsNode($doc, $publication, $langs));

        //$articleNode->appendChild();    // TODO: create <text>

        $articleNode->appendChild($this->_createCodesNode($doc, $publication));
        if ($keywordsNode = $this->_createKeywordsNode($doc, $publication, $langs))
        {
            $articleNode->appendChild($keywordsNode);
        }
        if ($referencesNode = $this->_createReferencesNode($doc, $publication))
        {
            $articleNode->appendChild($referencesNode);
        }
        $articleNode->appendChild($this->_createFilesNode($doc, $publication));

        return $articleNode;
    }

    protected $_artTypes = array ('ABS', 'BRV', 'CNF', 'COR', 'EDI', 'MIS', 'PER', 'RAR', 'REP', 'REV', 'SCO', 'UNK');

    /**
     * @param $doc DOMDocument
     * @param $author Author
     * @param $authorNumber int Serial number of the author in the article authors list
     * @param $langs string[]
     * @return DOMElement|false
     */
    protected function _createAuthorNode($doc, $author, $authorNumber, $langs)
    {
        $authorNode = $doc->createElement('author');
        $authorNode->setAttribute('num', str_pad(strval($authorNumber), 3, '0', STR_PAD_LEFT));

        foreach ($langs as $lang)
        {
            $individInfoNode = $doc->createElement('individInfo');
            $individInfoNode->setAttribute('lang', $lang);

            $locale = $this->_convertISO639ToLocale($lang);

            $individInfoNode->appendChild($doc->createElement('surname', $author->getFamilyName($locale)));
            $individInfoNode->appendChild($doc->createElement('initials', $this->_createInitials($author->getGivenName($locale))));

            $affiliation = $this->_parseAffiliation($this->_getAuthorAffiliation($author, $locale));
            $individInfoNode->appendChild($doc->createElement('orgName', htmlentities($affiliation->Organization, ENT_XML1)));
            $individInfoNode->appendChild($doc->createElement('address', htmlentities($affiliation->Address, ENT_XML1)));

            if($author->getEmail() != "null@null.null")
                $individInfoNode->appendChild($doc->createElement('email', $author->getEmail()));

            $authorNode->appendChild($individInfoNode);
        }

        if($author->getOrcid() != '')
        {
            $authorCodesNode = $doc->createElement('authorCodes');
            $orcidParts = explode('/', $author->getOrcid());
            $orcid = end($orcidParts);
            $authorCodesNode->appendChild($doc->createElement('orcid', $orcid));
            $authorNode->appendChild($authorCodesNode);
        }

        return $authorNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $publication Publication
     * @param $langs string[]
     * @return DOMElement|false
     */
    protected function _createArtTitlesNode($doc, $publication, $langs)
    {
        $artTitlesNode = $doc->createElement('artTitles');

        foreach ($langs as $lang)
        {
            $locale = $this->_convertISO639ToLocale($lang);
            $artTitleNode = $doc->createElement('artTitle', htmlentities($publication->getData('title',  $locale), ENT_XML1));
            $artTitleNode->setAttribute('lang', $lang);
            $artTitlesNode->appendChild($artTitleNode);
        }

        return $artTitlesNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $publication Publication
     * @param $langs string[]
     * @return DOMElement|false
     */
    protected function _createAbstractsNode($doc, $publication, $langs)
    {
        $abstractsNode = $doc->createElement('abstracts');

        foreach($langs as $lang)
        {
            $locale = $this->_convertISO639ToLocale($lang);
            $abstractNode = $doc->createElement('abstract', htmlentities(strip_tags($publication->getData('abstract', $locale)), ENT_XML1));
            $abstractNode->setAttribute('lang', $lang);
            $abstractsNode->appendChild($abstractNode);
        }

        return $abstractsNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $publication Publication
     * @return DOMElement|false
     */
    protected function _createCodesNode($doc, $publication)
    {
        $codesNode = $doc->createElement('codes');
        $codesNode->appendChild($doc->createElement('doi', $publication->getStoredPubId('doi')));
        return $codesNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $publication Publication
     * @param $langs string[]
     * @return DOMElement|false
     */
    protected function _createKeywordsNode($doc, $publication, $langs)
    {
        $keywordsNode = $doc->createElement('keywords');

        foreach ($langs as $lang)
        {
            $kwdGroup = $doc->createElement('kwdGroup');
            $kwdGroup->setAttribute('lang', $lang);
            $locale = $this->_convertISO639ToLocale($lang);
            $keywords = $publication->getData('keywords', $locale) ?? array();

            foreach ($keywords as $keyword)
            {
                $keywordName = is_array($keyword) ? ($keyword['name'] ?? '') : $keyword;

                if ($keywordName === '')
                {
                    continue;
                }

                $keywordNode = $doc->createElement('keyword');
                $keywordNode->appendChild($doc->createTextNode($keywordName));
                $kwdGroup->appendChild($keywordNode);
            }
            // The RSCI schema does not allow an empty <kwdGroup>: skip a language without keywords.
            if ($kwdGroup->hasChildNodes())
            {
                $keywordsNode->appendChild($kwdGroup);
            }
        }
        // ...nor an empty <keywords>: no keywords at all - no element.
        return $keywordsNode->hasChildNodes() ? $keywordsNode : false;
    }

    /**
     * @param $doc DOMDocument
     * @param $publication Publication
     * @return DOMElement|false
     */
    protected function _createReferencesNode($doc, $publication)
    {
        $referencesNode = $doc->createElement('references');
        /** @var CitationDAO $citationDAO */
        $citationDAO = DAORegistry::getDAO('CitationDAO');
        $citationsQ = $citationDAO->getByPublicationId($publication->getId());
        $lang = $this->_convertLocaleToISO639(Locale::getPrimaryLocale());
        /** @var Citation[] $citations */
        $citations = $citationsQ->toArray();

        if (empty($citations))
        {
            return false;
        }

        foreach($citations as $citation)
        {
            $referenceNode = $doc->createElement('reference');
            $refInfoNode = $doc->createElement('refInfo');
            $refInfoNode->setAttribute('lang', $lang);
            $textNode = $doc->createElement('text', htmlentities($citation->getRawCitation(), ENT_XML1));
            $refInfoNode->appendChild($textNode);
            $referenceNode->appendChild($refInfoNode);
            $referencesNode->appendChild($referenceNode);
        }

        return $referencesNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $publication Publication
     * @return DOMElement|false
     */
    protected function _createFilesNode($doc, $publication)
    {
        $filesNode = $doc->createElement('files');

//        $galleys = Repo::galley()->dao->getByPublicationId($publication->getId());
//        $galley = array_shift($galleys);

        $context = $this->_getContext();
        /** @var Request $request */
        $request = Registry::get('request', false);
        $publicPublicationId = $publication->getStoredPubId('publisher-id');        // TODO: check.
        if (!empty($publicPublicationId))
            $bestId = $publicPublicationId;
        else
            $bestId = $publication->getId();

        $resourceURL = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $context->getPath(),
            'article',
            'view',
            [$publication->getId()],
            urlLocaleForPage: ''
        );
        $furlNode = $doc->createElement('furl', $resourceURL);
        $furlNode->setAttribute('location', 'publisher');
        $furlNode->setAttribute('version', 'published');
        $filesNode->appendChild($furlNode);

        // Full text: the same file names that go into the archive (see getPublicationFilesToRSCI()).
        // No PDF galley - no <file desc="fullText"> element.
        foreach (self::getPublicationFilesToRSCI($publication, $this->_exportSettings['galleySelection'] ?? null) as $file)
        {
            $fileNode = $doc->createElement('file', $file['name']);
            $fileNode->setAttribute('desc', 'fullText');
            // Language of this full text (the galley language in OJS), e.g. lang="RUS".
            if ($file['locale'] !== '')
            {
                $fileNode->setAttribute('lang', $this->_convertLocaleToISO639($file['locale']));
            }
            $filesNode->appendChild($fileNode);
        }

        return $filesNode;
    }

    /**
     * Locates the issue cover on disk and gives the name it gets in the archive.
     *
     * The file is read from the journal's public files directory instead of being
     * downloaded by its URL: a URL with spaces or Cyrillic letters in the file name
     * could not be downloaded, and the cover silently went missing from the archive.
     * The cover in the journal's primary language is preferred, so the result does not
     * depend on the interface language of the person running the export. In the archive
     * the cover is always called "cover.<original extension>".
     *
     * Used both for the XML (<file desc="cover">) and for building the archive,
     * so the two always agree.
     *
     * @param $issue Issue
     * @return array|null ['path' => file on disk, 'name' => name in the archive],
     *  or null if the issue has no cover or its file is not on disk.
     */
    static public function getIssueCoverToRSCI($issue)
    {
        $journal = Application::getContextDAO()->getById($issue->getJournalId());
        $coverImage = $issue->getLocalizedData('coverImage', $journal->getPrimaryLocale());
        if (empty($coverImage))
        {
            return null;
        }

        $publicFileManager = new PublicFileManager();
        $path = $publicFileManager->getContextFilesPath($issue->getJournalId()) . '/' . $coverImage;
        if (!is_file($path))
        {
            return null;
        }

        $extension = strtolower(pathinfo($coverImage, PATHINFO_EXTENSION));
        return array(
            'path' => $path,
            'name' => 'cover' . ($extension !== '' ? '.' . $extension : ''),
        );
    }

    /**
     * Chooses the article's full text for the archive and gives the name it gets there.
     *
     * Only PDF galleys that have a file are considered (PDF by file type, or by the .pdf
     * extension for files migrated from old OJS versions with a generic type). HTML
     * galleys and galleys that are only a link to another site are skipped: previously
     * the first galley of any kind was taken, so an HTML file went to the archive instead
     * of the PDF, and a link galley broke the whole export.
     *
     * Which PDF galleys are taken depends on the plugin setting "Full texts in the archive"
     * ($selection):
     * - "article" (default, also when the setting was never saved): one PDF in the language
     *   of the article;
     * - a language code, e.g. "en": one PDF in that language; if there is none, in the
     *   language of the article;
     * - "all": one PDF for every language (if there are two PDFs in the same language,
     *   the first one).
     * When no PDF is in the wanted language, the first PDF galley in the order set in OJS
     * is taken, so an article with a PDF always gets a full text.
     *
     * The name in the archive is "<pages>_<galley language>.pdf", e.g. "4-33_ru.pdf", or
     * "<article ID>_<galley language>.pdf" if the pages are not filled in. Original file
     * names are not used: different articles could have the same one (the files overwrote
     * each other and an article got someone else's full text), and they contained the
     * uploader's login, spaces and Cyrillic letters.
     *
     * Used both for the XML (<file desc="fullText">) and for building the archive, so the
     * two always agree.
     *
     * @param $publication Publication
     * @param $selection string|null "article", a language code (e.g. "en") or "all"
     * A PDF galley whose file is missing on the server is skipped (like a missing cover),
     * so the XML never refers to a file that is not in the archive.
     *
     * @param $publication Publication
     * @param $selection string|null "article", a language code (e.g. "en") or "all"
     * @param $problems array|null if given, gets what the export report should mention:
     *  ['type' => 'noPdf'], ['type' => 'fileMissing', 'path' => file on disk],
     *  ['type' => 'otherLanguage', 'wanted' => [locales], 'taken' => locale],
     *  ['type' => 'duplicateLanguage', 'locale' => locale]
     * @return array list of ['path' => file on disk, 'name' => name in the archive,
     *  'locale' => galley language]; empty if the article has no usable PDF galley.
     */
    static public function getPublicationFilesToRSCI($publication, $selection = null, &$problems = null)
    {
        $pdfGalleys = array();
        $hasMissingFiles = false;
        /** @var Galley $galley */
        foreach (Repo::galley()->dao->getByPublicationId($publication->getId()) as $galley)
        {
            $file = $galley->getFile();
            if ($file && ($file->getData('mimetype') === 'application/pdf'
                    || strtolower(pathinfo($file->getData('path'), PATHINFO_EXTENSION)) === 'pdf'))
            {
                $path = Config::getVar('files', 'files_dir') . '/' . $file->getData('path');
                if (!is_file($path))
                {
                    $problems[] = array('type' => 'fileMissing', 'path' => $path);
                    $hasMissingFiles = true;
                    continue;
                }
                $pdfGalleys[] = $galley;
            }
        }
        if (empty($pdfGalleys))
        {
            if (!$hasMissingFiles)
            {
                $problems[] = array('type' => 'noPdf');
            }
            return array();
        }

        // How many PDF galleys each language has: two PDFs in one language are worth reporting.
        $pdfCountByLocale = array_count_values(array_map(fn ($galley) => (string) $galley->getLocale(), $pdfGalleys));

        if ($selection === 'all')
        {
            // One PDF per language: the first one in the order set in OJS.
            $chosen = array();
            foreach ($pdfGalleys as $galley)
            {
                $locale = (string) $galley->getLocale();
                if (!isset($chosen[$locale]))
                {
                    $chosen[$locale] = $galley;
                }
            }
            $chosen = array_values($chosen);
            foreach ($pdfCountByLocale as $locale => $count)
            {
                if ($count > 1)
                {
                    $problems[] = array('type' => 'duplicateLanguage', 'locale' => (string) $locale);
                }
            }
        }
        else
        {
            // One PDF: in the selected language (if a language is selected), otherwise in the
            // language of the article; if there is no such PDF, the first one.
            $wantedLocales = array($publication->getData('locale'));
            if (!empty($selection) && $selection !== 'article')
            {
                array_unshift($wantedLocales, $selection);
            }
            $chosenGalley = null;
            foreach ($wantedLocales as $wantedLocale)
            {
                foreach ($pdfGalleys as $galley)
                {
                    if ($galley->getLocale() === $wantedLocale)
                    {
                        $chosenGalley = $galley;
                        break 2;
                    }
                }
            }
            if (!$chosenGalley)
            {
                $chosenGalley = $pdfGalleys[0];
                $problems[] = array('type' => 'otherLanguage', 'wanted' => $wantedLocales, 'taken' => (string) $chosenGalley->getLocale());
            }
            elseif ($pdfCountByLocale[(string) $chosenGalley->getLocale()] > 1)
            {
                $problems[] = array('type' => 'duplicateLanguage', 'locale' => (string) $chosenGalley->getLocale());
            }
            $chosen = array($chosenGalley);
        }

        // "4-33" stays as is; anything that is not a Latin letter or digit becomes "-"
        // (e.g. an en dash in "4–33").
        $baseName = trim(preg_replace('/[^0-9A-Za-z]+/', '-', (string) $publication->getData('pages')), '-');
        if ($baseName === '')
        {
            $baseName = (string) $publication->getData('submissionId');
        }

        $files = array();
        foreach ($chosen as $galley)
        {
            $locale = (string) $galley->getLocale();
            $files[] = array(
                'path' => Config::getVar('files', 'files_dir') . '/' . $galley->getFile()->getData('path'),
                'name' => $baseName . ($locale !== '' ? '_' . $locale : '') . '.pdf',
                'locale' => $locale,
            );
        }
        return $files;
    }

    /**
     * @param $author Author
     * @param $locale string
     * @return string
     */
    protected function _getAuthorAffiliation($author, $locale)
    {
        $affiliations = array();

        foreach ($author->getAffiliations() as $affiliation)
        {
            $affiliationName = $affiliation->getAffiliationName($locale);

            if (empty($affiliationName))
            {
                $affiliationName = $affiliation->getLocalizedName($locale);
            }

            if (!empty($affiliationName))
            {
                $affiliations[] = $affiliationName;
            }
        }

        return implode('; ', $affiliations);
    }

    /**
     * @param $affiliationStr string
     * @return Affiliation
     */
    protected function _parseAffiliation($affiliationStr)
    {
        $affiliationsStr = explode(';', $affiliationStr);

        $organizations = array();
        $addresses = array();
        foreach ($affiliationsStr as $affiliation)
        {
            $affiliation = trim($affiliation);
            $address = '';
            $organization = $affiliation;

            $lastComma = mb_strpos($affiliation, ',');
            if ($lastComma !== false) {
                $organization = mb_substr($affiliation, 0, $lastComma);
                $address = mb_substr($affiliation, $lastComma);
                $address = ltrim($address, ", ");
            }

            $organizations[] = $organization;

            if (!in_array($address, $addresses))
            {
                $addresses[] = $address;
            }
        }

        $affiliation = new Affiliation();

        foreach ($organizations as $organization)
        {
            $affiliation->Organization .= '; ' .  $organization;
        }
        $affiliation->Organization = ltrim($affiliation->Organization, '; ');

        foreach ($addresses as $address)
        {
            $affiliation->Address .= '; ' . $address;
        }
        $affiliation->Address = ltrim($affiliation->Address, '; ');

        return $affiliation;
    }

    /**
     * @param $givenName string
     * @return string Initials with dots and whitespace between
     */
    protected function _createInitials($givenName)
    {
        $names = explode(' ', $givenName);
        $initialsArray = array();

        foreach ($names as $name)
        {
            $initial = mb_substr($name, 0, 1);
            if (mb_strtoupper($initial) === $initial)
            {
                $initialsArray[] = mb_strtoupper($initial);
            }
        }

        $initials = '';
        foreach ($initialsArray as $initial)
        {
            $initials = $initials . $initial . '. ';
        }
        $initials = rtrim($initials, " ");

        return $initials;
    }
    /**
     * @param $issue Issue
     * @return string Starting and ending pages string (start-end).
     */
    protected function _getIssuePages($issue)
    {
        $publications = $this->_getPublications($issue);
//        $submissionDao = Application::getSubmissionDAO();
//        $publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO');
//
//        $publishedArticles = $publishedArticleDao->getPublishedArticles($issue->getId());

        $startingPages = array();
        $endingPages = array();

        foreach ($publications as $publication)
        {
            //$submission = $submissionDao->getById($publishedArticle->getId());
            $startingPages[] = intval($publication->getStartingPage());
            $endingPages[] = intval($publication->getEndingPage());
        }

        $issueStartingPage = strval(min($startingPages));
        $issueEndingPage = strval(max($endingPages));

        return $issueStartingPage . '-' . $issueEndingPage;
    }

    /**
     * @param $issue Issue
     * @return Publication[]
     */
    protected function _getPublications($issue)
    {
        $submissionsIterator = Repo::submission()->getCollector()
            ->filterByContextIds([$issue->getData('journalId')])
            ->filterByIssueIds([$issue->getId()])
            ->filterByStatus([PKPSubmission::STATUS_PUBLISHED, PKPSubmission::STATUS_SCHEDULED])
            ->GetMany();

        /** @var Submission[] $publiations */
        $submissions = iterator_to_array($submissionsIterator);

        $publications = array();

        foreach ($submissions as $submission)
        {
            $publications []= $submission->getCurrentPublication();
        }

        return $publications;
    }

    /**
     * @return Context
     */
    protected function _getContext()
    {
        $request = Registry::get('request', false);
        return $request && $request->getContext() ? $request->getContext() : $this->_context;
    }

    /**
     * @param $locale string
     * @return string
     */
    protected function _convertLocaleToISO639($locale)
    {
        $lang2chars = substr($locale, 0, 2);
        $iso = $this->_getISO639();
        $language = $iso->languageByCode1($lang2chars);
        $lang3chars = $iso->code3ByLanguage($language);

        if ($lang3chars == '')
        {
            $request = Registry::get('request', false);
            $user = $request ? $request->getUser() : null;
            if ($user) {
                $notificationManager = new NotificationManager();
                $notificationManager->createTrivialNotification($user->getId(),
                    Notification::NOTIFICATION_TYPE_WARNING,
                    array('pluginName' => __('plugins.importexport.rsciexport.displayName'),
                        'contents' => "Error in converting UNIX locale (" . $locale . ") to ISO639 language! Empty language string will be used."));
            }
        }

        return strtoupper($lang3chars);
    }

    /**
     * @param $lang3chars string
     * @return string
     */
    protected function _convertISO639ToLocale($lang3chars)
    {
        $lang3chars = strtolower($lang3chars);
        $iso = $this->_getISO639();
        $language = $iso->languageByCode3($lang3chars);
        $lang2chars = $iso->code1ByLanguage($language);
        $locales = $this->_getContext()->getSupportedLocales();

        foreach ($locales as $locale)
        {
            $lang2charsFromLocale = substr($locale, 0, 2);
            if (str_contains($lang2charsFromLocale, $lang2chars))
            {
                return $locale;
            }
        }

        // If can't convert ISO639 to UNIX locale:
        $request = Registry::get('request', false);
        $user = $request ? $request->getUser() : null;
        if ($user) {
            $notificationManager = new NotificationManager();
            $notificationManager->createTrivialNotification($user->getId(),
                                                Notification::NOTIFICATION_TYPE_WARNING,
                                                            array('pluginName' => __('plugins.importexport.rsciexport.displayName'),
                                                                  'contents' => "Error in converting ISO639 language (" . $lang3chars . ") to UNIX locale! Primary locale will be used."));
        }
        return $this->_getContext()->getPrimaryLocale();
    }

    /**
     * @var ISO639
     */
    protected $_iso;

    /**
     * @var Context
     */
    protected $_context;

    /**
     * @return ISO639
     */
    protected function _getISO639()
    {
        if (!isset($this->_iso))
        {
            require_once('php-iso-639/src/ISO639.php');
            $this->_iso = new ISO639();
        }
        return $this->_iso;
    }

    protected $_exportSettings;

    /**
     * @param $exportSettings array
     */
    public function SetExportSettings($exportSettings)
    {
        $this->_exportSettings = $exportSettings;
    }


    /**
     * @param DOMDocument $doc
     * @param $publication Publication
     * @param string[] $langs
     * @return DOMElement|false
     */
    protected function _createSectionNode($doc, $publication, $langs)
    {
        // SectionDAO no longer exists in OJS 3.4+; sections are read through the repository,
        // as in _createArticleNode().
        /** @var Section $section */
        $section = Repo::section()->get($publication->getData('sectionId'));
        $sectionNode = $doc->createElement('section');

        foreach ($langs as $lang)
        {
            $locale = $this->_convertISO639ToLocale($lang);
            $secTitleNode = $doc->createElement('secTitle', $section->getTitle($locale));
            $secTitleNode->setAttribute('lang', $lang);
            $sectionNode->appendChild($secTitleNode);
        }
        return $sectionNode;
    }

    /**
     * Sorts articles by their starting page.
     *
     * Articles with the same starting page, or without pages (they all count as page 0),
     * are all kept, in their original order. Previously the starting page was used as an
     * array key, so all but one of such articles silently disappeared from the XML.
     *
     * @param $publications Publication[]
     * @return Publication[]
     */
    protected function _sortPublicationsByPages($publications)
    {
        usort($publications, fn ($a, $b) => intval($a->getStartingPage()) <=> intval($b->getStartingPage()));
        return $publications;
    }
}

class Affiliation
{
    public $Organization = '';
    public $Address = '';
}


