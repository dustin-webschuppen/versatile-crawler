<?php

namespace WEBcoast\VersatileCrawler\Crawler;


use Doctrine\DBAL\DBALException;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Frontend\Page\PageRepository;
use WEBcoast\VersatileCrawler\Controller\QueueController;
use WEBcoast\VersatileCrawler\Domain\Model\Item;
use WEBcoast\VersatileCrawler\Exception\PageNotAvailableForIndexingException;
use WEBcoast\VersatileCrawler\Queue\Manager;

class PageTree extends FrontendRequestCrawler
{
    /**
     * @var PageRepository
     */
    protected $pageRepository;

    /**
     * PageTree constructor.
     */
    public function __construct()
    {
        $context = GeneralUtility::makeInstance(Context::class);
        // Set new visibility aspect to ignore hidden pages
        $context->setAspect('visibility', new VisibilityAspect());
        $this->pageRepository = GeneralUtility::makeInstance(PageRepository::class);
        // fake the frontend group list
        if (!isset($GLOBALS['TSFE'])) {
            $GLOBALS['TSFE'] = new \stdClass();
            $GLOBALS['TSFE']->gr_list = '0,-1';
        }
    }

    /**
     * @param array      $configuration
     * @param array|null $rootConfiguration
     *
     * @throws DBALException
     *
     * @return boolean
     */
    public function fillQueue(array $configuration, array $rootConfiguration = null)
    {
        if ($rootConfiguration === null) {
            $rootConfiguration = $configuration;
        }

        $rootPage = $this->pageRepository->getPage($configuration['pid']);
        if (!is_array($rootPage)) {
            throw new \RuntimeException('The page that contains the configuration is not accessible');
        }
        $pages = [$rootPage];
        if ($configuration['levels'] === 0) {
            $this->getPagesRecursively($rootPage, $pages, null);
        } else {
            $this->getPagesRecursively($rootPage, $pages, $configuration['levels']);
        }

        $result = true;
        $queueManager = GeneralUtility::makeInstance(Manager::class);
        $languages = GeneralUtility::intExplode(',', $configuration['languages']);
        foreach ($pages as $page) {
            if (is_array($this->pageRepository->getPage($page['uid']))) {
                // get the page from the page repository
                if ((int)$configuration['exclude_pages_with_configuration'] === 1) {
                    $query = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(QueueController::CONFIGURATION_TABLE);
                    $query->count('*')
                        ->from(QueueController::CONFIGURATION_TABLE)
                        ->where(
                            'pid=' . (int)$page['uid'],
                            'uid!=' . $configuration['uid'],
                            'uid!=' . $rootConfiguration['uid']
                        );
                    if ($query->execute()->fetchColumn(0) > 0) {
                        continue;
                    }
                }
                if (in_array(0, $languages)) {
                    if ((int)$page['no_search'] === 0 && (int)$page['doktype'] === 1) {
                        $data = [
                            'page' => $page['uid'],
                            'sys_language_uid' => 0,
                            'rootConfigurationId' => $rootConfiguration['uid']
                        ];
                        // add an item for the default language
                        $item = new Item(
                            $configuration['uid'],
                            md5(serialize($data)),
                            Item::STATE_PENDING,
                            '',
                            $data
                        );
                        $result = $result && $queueManager->addOrUpdateItem($item);
                    }
                }
                // check other languages than 0
                foreach ($languages as $language) {
                    if ((int)$language !== 0) {
                        $overlay = $this->pageRepository->getPageOverlay($page, $language);
                        if (is_array($overlay) && isset($overlay['_PAGES_OVERLAY'])) {
                            if ((int)$overlay['no_search'] === 0 && (int)$overlay['doktype'] === 1) {
                                $data = [
                                    'page' => $page['uid'],
                                    'sys_language_uid' => $language,
                                    'rootConfigurationId' => $rootConfiguration['uid']
                                ];
                                // add an item for the default language
                                $item = new Item(
                                    $configuration['uid'],
                                    md5(serialize($data)),
                                    Item::STATE_PENDING,
                                    '',
                                    $data
                                );
                                $result = $result && $queueManager->addOrUpdateItem($item);
                            }
                        }
                    }
                }
            }
        }

        return $result;
    }

    protected function getPagesRecursively($page, &$pages, $level)
    {
        $subPages = $this->pageRepository->getMenu($page['uid']);
        foreach ($subPages as $subPage) {
            $pages[] = $subPage;
            if ($level === null || $level > 0) {
                $this->getPagesRecursively($subPage, $pages, ($level === null ? null : $level - 1));
            }
        }
    }

    /**
     * @param \WEBcoast\VersatileCrawler\Domain\Model\Item $item
     * @param array                                        $configuration
     *
     * @throws PageNotAvailableForIndexingException
     *
     * @return string
     */
    protected function buildQueryString(Item $item, array $configuration)
    {
        $data = $item->getData();
        $page = $this->pageRepository->getPage($data['page']);
        if (empty($page)) {
            // The page may be hidden, so we can not crawl it
            throw new PageNotAvailableForIndexingException();
        }
        return 'id=' . $data['page'] . '&L=' . $data['sys_language_uid'];
    }

    /**
     * @param \WEBcoast\VersatileCrawler\Domain\Model\Item                $item
     * @param \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController $typoScriptFrontendController
     *
     * @return boolean
     */
    public function isIndexingAllowed(Item $item, TypoScriptFrontendController $typoScriptFrontendController)
    {
        $data = $item->getData();
        return ($data['page'] === (int)$typoScriptFrontendController->id && $data['sys_language_uid'] === GeneralUtility::makeInstance(Context::class)->getAspect('language')->getId());
    }
}
