<?php

namespace Nordkirche\NkcEvent\Controller;

use Nordkirche\Ndk\Domain\Model\Event\Event;
use Nordkirche\Ndk\Domain\Query\EventQuery;
use Nordkirche\Ndk\Domain\Repository\EventRepository;
use Nordkirche\Ndk\Service\Interfaces\QueryInterface;
use Nordkirche\Ndk\Service\NapiService;
use Nordkirche\Ndk\Service\Result;
use Nordkirche\NkcBase\Controller\BaseController;
use Nordkirche\NkcBase\Service\ApiService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\View\JsonView;
use TYPO3\CMS\Fluid\View\StandaloneView;

class MapController extends BaseController
{
    /**
     * @var EventRepository
     */
    protected $eventRepository;

    /**
     * @var EventController
     */
    protected $eventController;

    /**
     * @var NapiService
     */
    protected $napiService;

    /**
     * @var StandaloneView
     */
    protected $standaloneView;

    /**
     * @var array
     */
    protected $facets = [];

    public function initializeAction()
    {
        parent::initializeAction();

        $this->eventRepository = $this->api->factory(EventRepository::class);
        $this->napiService = $this->api->factory(NapiService::class);

        $this->eventController->initializeAction();
    }

    /**
     * Show map action
     */
    public function showAction(): ResponseInterface
    {
        $this->createView();
        return $this->htmlResponse();
    }

    /**
     * Show map and list action
     *
     * @param int $currentPage
     */
    public function listAction($currentPage = 1): ResponseInterface
    {
        $this->createView($currentPage);
        return $this->htmlResponse();
    }

    /**
     * @param $currentPage
     */
    private function createView($currentPage = 1)
    {
        // Get current cObj
        $cObj = $this->request->getAttribute('currentContentObject');

        /** @var EventQuery $query */
        $query = $this->getEventQuery($currentPage);

        list($limit, $mapItems) = $this->getMapItems($query, $this->settings, false);

        if ($limit) {
            // Too many objects: async loading

            $cObj = $this->request->getAttribute('currentContentObject');

            if (empty($this->settings['flexform']['stream'])) {
                // In einem Rutsch nachladen
                $this->uriBuilder->reset()
                    ->setTargetPageUid($GLOBALS['TSFE']->id)
                    ->setTargetPageType($this->settings['ajaxTypeNum'])
                    ->setArguments(['tx_nkcevent_map[action]' => 'data', 'uid' => $cObj->data['uid']]);

                $this->view->assign('requestUri', $this->uriBuilder->build());
            } else {
                // Sukzessive nachladen
                $this->uriBuilder->reset()
                    ->setTargetPageUid($GLOBALS['TSFE']->id)
                    ->setTargetPageType($this->settings['ajaxTypeNum'])
                    ->setArguments(['tx_nkcevent_map[action]' => 'paginatedData', 'uid' => $cObj->data['uid']]);

                $this->view->assign('streamUri', $this->uriBuilder->build());
            }
        } else {
            $mapMarkers = $this->createMarkers($mapItems);

            $this->view->assign('mapMarkers', $mapMarkers);
        }

        $this->view->assignMultiple(['events'   => $mapItems,
                                     'query'    => $query,
                                     'content'  => $cObj->data]);

        if (!empty($this->settings['flexform']['showFilter']) &&  ($this->settings['flexform']['showFilter'] == 1)) {
            $this->view->assign('facets', $this->getFacets());
        }
    }

    /**
     * @param int $forceReload
     * @return string
     */
    public function dataAction($forceReload = 0): ResponseInterface
    {
        // Get current cObj
        $cObj = $this->request->getAttribute('currentContentObject');

        $cacheInstance = GeneralUtility::makeInstance(CacheManager::class)->getCache('tx_nkgooglemaps');

        $mapMarkerJson = $cacheInstance->get($this->getCacheKey($cObj));

        // Check if new rendering is required
        if (($forceReload === 1) && trim($mapMarkerJson)) {
            try {
                $mapMarkers = json_decode($mapMarkerJson);
                if ($mapMarkers->crdate > time() - 3600) {
                    $forceReload = 0;
                }
            } catch (\Exception $e) {
                $forceReload = 1;
            }
        }

        if (!trim($mapMarkerJson) || $forceReload) {
            /** @var EventQuery $query */
            $query = $this->getEventQuery();

            list($limit, $mapItems) = $this->getMapItems($query, $this->settings, true);

            $mapMarkerJson = json_encode(['crdate' => time(), 'data' => $this->createMarkers($mapItems)]);

            $cacheInstance->set($this->getCacheKey($cObj), $mapMarkerJson);
        }

        return $this->jsonResponse($mapMarkerJson);
    }

    /**
     * @param $content
     * @param $forceReload
     */
    public function buildCache($content, $forceReload)
    {
        $cObj = new \StdClass();
        $cObj->data = $content;

        $cacheInstance = GeneralUtility::makeInstance(CacheManager::class)->getCache('tx_nkgooglemaps');

        $mapMarkerJson = $cacheInstance->get($this->getCacheKey($cObj));

        // Check if new rendering is required
        if (($forceReload === 1) && trim($mapMarkerJson)) {
            try {
                $mapMarkers = json_decode($mapMarkerJson);
                if ($mapMarkers->crdate > time() - 3600) {
                    $forceReload = 0;
                }
            } catch (\Exception $e) {
                $forceReload = 1;
            }
        }

        if (!trim($mapMarkerJson) || $forceReload) {
            // Get TS Config and add to local settings
            $tsConfig = $this->getTypoScriptConfiguration();
            $this->settings['eventIconName'] = $tsConfig['plugin']['tx_nkcevent_main']['settings']['eventIconName'];
            $this->settings['mapping'] = $tsConfig['plugin']['tx_nkcevent_main']['settings']['mapping'];

            $this->eventController->setSettings($this->settings);

            /** @var EventQuery $query */
            $query = $this->getEventQuery();
            // $query->setInclude(array(Event::RELATION_ADDRESS, Event::RELATION_CHIEF_ORGANIZER));
            $query->setInclude([Event::RELATION_ADDRESS, Event::RELATION_CATEGORY, Event::RELATION_CHIEF_ORGANIZER]);

            list($limit, $mapItems) = $this->getMapItems($query, $this->settings, true);

            $mapMarkerJson = json_encode(['crdate' => time(), 'data' => $this->createMarkers($mapItems)]);

            $cacheInstance->set($this->getCacheKey($cObj), $mapMarkerJson);
        }
    }

    /**
     * @param int $page
     * @return string
     */
    public function paginatedDataAction($page = 1): ResponseInterface
    {
        // Manually activation of pagination mode
        $this->settings['flexform']['paginate']['mode'] = 1;

        // Get current cObj
        $cObj = $this->request->getAttribute('currentContentObject');

        $cacheInstance = GeneralUtility::makeInstance(CacheManager::class)->getCache('tx_nkgooglemaps');

        $mapMarkerJson = $cacheInstance->get($this->getCacheKey($cObj));

        if (!trim($mapMarkerJson)) {
            /** @var EventQuery $query */
            $query = $this->getEventQuery($page);

            list($limit, $mapItems) = $this->getMapItems($query, $this->settings, false);

            $mapMarkerJson = json_encode(['crdate' => time(), 'data' => $this->createMarkers($mapItems)]);
        }

        return $this->jsonResponse($mapMarkerJson);
    }

    /**
     * @param $mapItems
     * @return array
     */
    private function createMarkers($mapItems)
    {
        $markers = [];

        foreach ($mapItems as $item) {
            $this->eventController->createMarker($markers, $item);
        }
        return $markers;
    }

    /**
     * @param EventQuery $query
     * @param array $settings
     * @param bool $allItems
     * @return array
     */
    public function getMapItems($query, $settings, $allItems = false)
    {
        $mapItems = [];

        // Selected Events
        if (!empty($settings['flexform']['eventCollection'])) {
            $query->setEvents(GeneralUtility::trimExplode(',', $settings['flexform']['eventCollection']));
        }
        // Filter by organizer
        if (!empty($settings['flexform']['institutionCollection'])) {
            $query->setOrganizers(GeneralUtility::trimExplode(',', $settings['flexform']['institutionCollection']));
        }

        // Categories
        if (!empty($settings['flexform']['categories'])) {
            $categories = GeneralUtility::trimExplode(',', $settings['flexform']['categories']);
            if ($settings['flexform']['selectCategoryOption'] == QueryInterface::OPERATOR_AND) {
                $query->setCategoriesAnd($categories);
            } else {
                $query->setCategoriesOr($categories);
            }
        }

        // Cities
        if (!empty($settings['flexform']['cities'])) {
            $query->setCities(GeneralUtility::trimExplode(',', $settings['flexform']['cities']));
        }

        // zipCodes
        if (!empty($settings['flexform']['zipCodes'])) {
            $query->setZipCodes(GeneralUtility::trimExplode(',', $settings['flexform']['zipCodes']));
        }

        // dateFrom
        if (!empty($settings['flexform']['dateFrom'])) {
            $dateFrom = new \DateTime(date('d.m.Y', $settings['flexform']['dateFrom']));
            $query->setTimeFromStart($dateFrom);
        } else {
            $query->setTimeFromStart(new \DateTime(date('Y-m-d')));
        }

        // dateTo
        if (!empty($settings['flexform']['dateTo'])) {
            $dateTo = new \DateTime(date('d.m.Y', $settings['flexform']['dateTo']));
            $query->setTimeToEnd($dateTo);
        }

        // num days
        if (!empty($settings['flexform']['numDays']) && ((int)$settings['flexform']['numDays'] > 0)) {
            $dateTo = new \DateTime(date('d.m.Y', time() + ((int)$settings['flexform']['numDays'] * 86400)));
            $query->setTimeToEnd($dateTo);
        }

        $query->setPageSize(50);

        if ($this->getEventsByQuery($query, $allItems, $mapItems) === false) {
            return [true, $mapItems];
        }

        return [false, $mapItems];
    }

    /**
     * @param int $currentPage
     * @return EventQuery
     */
    public function getEventQuery($currentPage = 1)
    {
        $query = new EventQuery();
        $this->setPagination($query, $currentPage);
        // $query->setInclude(array(Event::RELATION_ADDRESS, Event::RELATION_CHIEF_ORGANIZER));
        $query->setInclude([Event::RELATION_ADDRESS, Event::RELATION_CATEGORY, Event::RELATION_CHIEF_ORGANIZER]);
        $query->setFacets([Event::FACET_EVENT_TYPE]);
        return $query;
    }

    /**
     * @param $query
     * @param $allItems
     * @param $mapItems
     * @return bool
     */
    private function getEventsByQuery($query, $allItems, &$mapItems)
    {
        if ($allItems) {
            $result = ApiService::getAllItems($this->eventRepository, $query, [Event::RELATION_ADDRESS, Event::RELATION_CHIEF_ORGANIZER]);
        } else {
            $result = $this->eventRepository->get($query, [Event::RELATION_ADDRESS, Event::RELATION_CHIEF_ORGANIZER]);
        }

        if ($result instanceof Result && $result->getFacets()) {
            $this->addFacets($result->getFacets());
        }

        $mapItems = $result;

        if (!$allItems && ($result->getPageCount() > 1)) {
            return false;
        }

        return true;
    }

    /**
     * @param array $facets
     */
    private function addFacets($facets)
    {
        foreach ($facets as $key => $ids) {
            $this->facets[$key] = $ids;
        }
    }

    /**
     * @return array
     */
    private function getFacets()
    {
        $facetsArray = [];

        // Resolve facets
        foreach ($this->facets as $facet_type => $facets) {
            foreach ($facets as $facet) {
                list($id, $number) = each($facet);
                $facetsArray[$facet_type][$id] = sprintf('%s (%s)', $this->settings['mapping']['eventType'][$id], $number);
            }
            asort($facetsArray[$facet_type]);
        }

        return $facetsArray;
    }

    /**
     * @param $cObj
     * @return string
     */
    private function getCacheKey($cObj)
    {
        $key = 'tx_nkcevent_map--dataAction--' . serialize($this->settings['flexform']);
        return $cObj->data['uid'] . '--' . md5($key);
    }

    public function injectEventController(EventController $eventController): void
    {
        $this->eventController = $eventController;
    }
}
