<?php

namespace Nordkirche\NkcEvent\Controller;

use Nordkirche\NkcBase\Controller\BaseController;
use Nordkirche\NkcEvent\Domain\Repository\FilterDateRepository;
use TYPO3\CMS\Extbase\Domain\Repository\CategoryRepository;
use Nordkirche\NkcBase\Exception\ApiException;
use Psr\Http\Message\ResponseInterface;
use Nordkirche\Ndk\Domain\Query\EventQuery;
use Nordkirche\Ndk\Domain\Model\Geocode;
use Nordkirche\Ndk\Domain\Interfaces\ModelInterface;
use Nordkirche\Ndk\Domain\Model\Address;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Mvc\Exception\StopActionException;
use Nordkirche\NkcEvent\Domain\Model\FilterDate;
use TYPO3\CMS\Extbase\Domain\Model\Category;
use Nordkirche\Ndk\Service\Result;
use Nordkirche\Ndk\Domain\Query\InstitutionQuery;
use TYPO3\CMS\Core\Cache\CacheManager;
use Nordkirche\Ndk\Domain\Model\Event\Event;
use Nordkirche\Ndk\Domain\Model\Institution\Institution;
use Nordkirche\Ndk\Domain\Repository\EventRepository;
use Nordkirche\Ndk\Domain\Repository\InstitutionRepository;
use Nordkirche\Ndk\Domain\Repository\PersonRepository;
use Nordkirche\Ndk\Service\Interfaces\QueryInterface;
use Nordkirche\Ndk\Service\NapiService;
use Nordkirche\NkcBase\Service\ApiService;
use Nordkirche\NkcEvent\Domain\Dto\SearchRequest;
use Nordkirche\NkcEvent\Service\ExportService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Mvc\View\JsonView;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\CMS\Form\Domain\Model\FormElements\Date;

class EventController extends BaseController
{

    /**
     * @var EventRepository
     */
    protected $eventRepository;

    /**
     * @var InstitutionRepository
     */
    protected $institutionRepository;

    /**
     * @var PersonRepository
     */
    protected $personRepository;

    /**
     * @var FilterDateRepository
     */
    protected $filterDateRepository;

    /**
     * @var CategoryRepository
     */
    protected $categoryRepository;

    /**
     * @var StandaloneView
     */
    protected $standaloneView;

    public function initializeAction()
    {
        parent::initializeAction();
        $this->eventRepository = $this->api->factory(EventRepository::class);
        $this->institutionRepository = $this->api->factory(InstitutionRepository::class);
        $this->personRepository = $this->api->factory(PersonRepository::class);

        if ($this->arguments->hasArgument('searchRequest')) {
            $this->arguments->getArgument('searchRequest')->getPropertyMappingConfiguration()->allowProperties('search');
            $this->arguments->getArgument('searchRequest')->getPropertyMappingConfiguration()->allowProperties('city');
            $this->arguments->getArgument('searchRequest')->getPropertyMappingConfiguration()->allowProperties('location');
            $this->arguments->getArgument('searchRequest')->getPropertyMappingConfiguration()->allowProperties('dateFrom');
            $this->arguments->getArgument('searchRequest')->getPropertyMappingConfiguration()->allowProperties('dateTo');
            $this->arguments->getArgument('searchRequest')->getPropertyMappingConfiguration()->allowProperties('category');
            $this->arguments->getArgument('searchRequest')->getPropertyMappingConfiguration()->allowProperties('organizer');
        }
    }

    /**
     * @param int $currentPage
     * @param SearchRequest $searchRequest
     * @throws ApiException
     */
    public function listAction($currentPage = 1, $searchRequest = null): ResponseInterface
    {
        $query = new EventQuery();

        $query->setInclude([Event::RELATION_ADDRESS, Event::RELATION_CHIEF_ORGANIZER, Event::RELATION_CATEGORY]);

        // $query->setFacets([Event::FACET_EVENT_TYPE]);

        $this->setFlexformFilters($query, $this->settings['flexform']);

        if ($searchRequest instanceof SearchRequest) {
            $this->setUserFilters($query, $searchRequest);
        } else {
            $searchRequest = new SearchRequest();

            // Set start date - napi delivers running events
            $query->setTimeFromStart(new \DateTime(date('Y-m-d')));
        }

        // Set pagination parameters
        $this->setPagination($query, $currentPage);

        // Get events
        $events = $this->eventRepository->get($query);

        // Get current cObj
        $cObj = $this->configurationManager->getContentObject();

        // Check organizer
        if ($searchRequest->getOrganizer()) {
            $organizer = $this->getOrganizer($searchRequest->getOrganizer());
        } else {
            $organizer = false;
        }

        if ($events->getRecordCount() <= 25) {
            if ($GLOBALS['TSFE']->type === 0) {
                $mapMarkers = $this->getMapMarkers($events);
            } else {
                $mapMarkers = [];
            }
            $requestUri = '';
        } else {
            $mapMarkers = [];

            if (!$this->settings['flexform']['stream']) {

                $this->uriBuilder->reset()
                    ->setTargetPageUid($GLOBALS['TSFE']->id)
                    ->setTargetPageType($this->settings['ajaxTypeNum'])
                    ->setArguments([
                        'tx_nkcevent_main' => [
                            'action' => 'data',
                            'searchRequest' => $searchRequest->toArray()
                        ],
                        'uid' => $cObj->data['uid']
                    ]);

                $requestUri = $this->uriBuilder->build();

            } else {
                $this->uriBuilder->reset()
                    ->setTargetPageUid($GLOBALS['TSFE']->id)
                    ->setTargetPageType($this->settings['ajaxTypeNum'])
                    ->setUseCacheHash(false)
                    ->setArguments([
                        'tx_nkcevent_main' => [
                            'action' => 'paginatedData',
                            'searchRequest' => $searchRequest->toArray()
                        ],
                        'uid' => $cObj->data['uid']
                    ]);

                $this->view->assign('streamUri', $this->uriBuilder->build());
            }
        }

        $this->view->assignMultiple([
            'query' => $query,
            'events' => $events,
            'content' => $cObj->data,
            'searchPid' => $GLOBALS['TSFE']->id,
            'searchRequest' => $searchRequest,
            'filter' => ($GLOBALS['TSFE']->type === 0) ? $this->getFilterValues() : [],
            'mapMarkers' => $mapMarkers,
            'requestUri' => $requestUri,
            'organizer' => $organizer
        ]);
        return $this->htmlResponse();
    }

    /**
     * @param EventQuery $query
     * @param array $flexform
     * @throws ApiException
     */
    public function setFlexformFilters($query, $flexform)
    {

        // Filter by organizer
        $this->setOrganizersFilter($query, $flexform['institutionCollection']);

        // Filter by target group
        $this->setTargetGroupFilter($query, $flexform['targetGroupCollection']);

        if ($flexform['eventTypes']) {
            $eventTypes = GeneralUtility::trimExplode(',', $flexform['eventTypes']);
            $query->setEventType($eventTypes[0]);
        }

        if ($flexform['eventLocation']) {

            if ($this->napiService === null) {
                $this->api = ApiService::get();
                $this->napiService = $this->api->factory(NapiService::class);
            }

            $eventLocation = $this->napiService->resolveUrl($flexform['eventLocation']);
            if ($eventLocation->getName()) {
                $query->setLocation($eventLocation->getName());
            }
        }

        if ($flexform['categories']) {
            $categories = GeneralUtility::trimExplode(',', $flexform['categories']);
            if ($flexform['categoryOperator'] == QueryInterface::OPERATOR_AND) {
                $query->setCategoriesAnd($categories);
            } else {
                $query->setCategoriesOr($categories);
            }
        }

        if ($flexform['dateFrom']) {
            $query->setTimeFrom(new \DateTime(date('d.m.Y', $flexform['dateFrom'])));
        }

        if ($flexform['dateTo']) {
            $query->setTimeTo(new \DateTime(date('d.m.Y', $flexform['dateTo'])));
        }

        if (intval($flexform['numDays']) > 1) {
            try {
                $date = new \DateTime();
                $interval = new \DateInterval('P' . intval($flexform['numDays']). 'D');
                $date->add($interval);
                $query->setTimeTo($date);
            } catch (\Exception $e) {
                // Invalid data: do nothing
            }
        }

        if ($flexform['geosearch']) {
            $geocode = new Geocode($flexform['latitude'], $flexform['longitude'], $flexform['radius']);
            $query->setGeocode($geocode);
        }
    }

    /**
     * @param $query
     * @param $filter
     */
    private function setOrganizersFilter($query, $filter)
    {
        if ($filter) {
            $resourceArray = GeneralUtility::trimExplode(',', $filter);
            $query->setOrganizers($resourceArray);
        }
    }

    /**
     * @param $query
     * @param $filter
     */
    private function setTargetGroupFilter($query, $filter)
    {
        if ($filter) {
            $resourceArray = GeneralUtility::trimExplode(',', $filter);
            $query->setTargetGroups($resourceArray);
        }
    }


    /**
     * @param EventQuery $query
     * @param SearchRequest $searchRequest
     */
    private function setUserFilters($query, $searchRequest)
    {
        if (($searchRequest->getSearch() != '') && (strlen($searchRequest->getSearch()) > 2)) {
            $query->setQuery($searchRequest->getSearch());
        }

        if ($searchRequest->getOrganizer() != 0) {
            if ($organizers = $query->getOrganizers()) {
                if (in_array($searchRequest->getOrganizer(), $organizers)) {
                    $query->setOrganizers([$searchRequest->getOrganizer()]);
                }
            } else {
                $query->setOrganizers([$searchRequest->getOrganizer()]);
            }
        }

        if ($searchRequest->getDateFrom() != null) {
            $query->setTimeFromStart($searchRequest->getDateFrom());
        } else {
            $query->setTimeFromStart(new \DateTime(date('Y-m-d')));
        }

        if ($searchRequest->getDateTo() != null) {
            $query->setTimeToEnd($searchRequest->getDateTo());
        }

        if ($searchRequest->getLocation() != '') {
            $query->setLocation($searchRequest->getLocation());
        }

        if (($searchRequest->getCity() != '') && (strlen($searchRequest->getCity()) > 2)) {
            $zipList = [];
            $cityList = [];

            foreach (GeneralUtility::trimExplode(',', $searchRequest->getCity()) as $city) {
                if (is_numeric($city)) {
                    $zipList[] = $city;
                } else {
                    $cityList[] = $city;
                }
            }
            if (count($zipList)) {
                $query->setZipCodes($zipList);
            }
            if (count($cityList)) {
                $query->setCities($cityList);
            }
        }

        if ($searchRequest->getCategory() > 0) {
            if ($categories = $query->getCategoriesOr()) {
                if (in_array($searchRequest->getCategory(), $categories)) {
                    $query->setCategoriesOr([$searchRequest->getCategory()]);
                }
            } else {
                $query->setCategoriesOr([$searchRequest->getCategory()]);
            }
        }
    }

    /**
     * @param $id
     * @return bool|ModelInterface
     */
    private function getOrganizer($id)
    {
        try {
            $organizer = $this->institutionRepository->getById($id);
            return $organizer;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param $events
     * @return array|mixed
     */
    private function getMapMarkers($events)
    {
        $mapMarkers = [];

        foreach ($events as $event) {
            $this->createMarker($mapMarkers, $event);
        }

        return $mapMarkers;
    }

    /**
     * Add a marker, when geo-coordinates are available
     *
     * @param array $mapMarkers
     * @param \Nordkirche\Ndk\Domain\Model\Event\Event
     * @param boolean $asyncInfo
     */
    public function createMarker(&$mapMarkers, $event, $asyncInfo = TRUE)
    {
        // Get type of event
        $mappingType = ($event->getEventType() ? $event->getEventType() : 'default');
        $type = 'event-' . $mappingType;
        $address = $event->getAddress();
        $digitalEvent = false;

        foreach ($event->getCategories() as $category) {
            if ((int)($category->getId()) == (int)($this->settings['digitalEventCategoryId'])) {
                $digitalEvent = true;
                break;
            }
        }

        if ($digitalEvent) {
            $type = 'digital-' . $type;
            $mappingType = 'digital-' . $mappingType;
        }

        if ($address instanceof Address) {
            // Check geo coordinates
            if ($address->getLatitude() && $address->getLongitude()) {
                $marker = [

                    'lat' 	=> $address->getLatitude(),
                    'lon' 	=> $address->getLongitude(),
                    'info' 	=> $asyncInfo ? '' : $this->renderMapInfo($event, ['digitalEvent' => $digitalEvent]),
                    'type'  => $type,
                    'object' => 'e',
                    'id'    => $event->getId(),
                    'icon' 	=> sprintf($this->settings['eventIconName'], $this->settings['mapping']['eventIcon'][$mappingType] ? $this->settings['mapping']['eventIcon'][$mappingType] : 'gemeindeleben')

                ];
                $mapMarkers[] = $marker;
            }
        }
    }

    /**
     * @param Event $event
     * @param array
     * @param string $template
     * @return string
     */
    public function renderMapInfo($event, $addConfig = [], $template = 'Event/MapInfo')
    {
        if ($this->standaloneView == false) {
            // Init standalone view

            $config= $this->configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK);

            $absTemplatePaths = [];
            if (is_array($config['view']['templateRootPaths'])) {
                foreach ($config['view']['templateRootPaths'] as $path) {
                    $absTemplatePaths[] = GeneralUtility::getFileAbsFileName($path);
                }
            }
            if (count($absTemplatePaths) == 0) {
                $absTemplatePaths[] =  GeneralUtility::getFileAbsFileName('EXT:nkc_event/Resources/Private/Templates/');
            }

            $absLayoutPaths = [];
            if (is_array($config['view']['layoutRootPaths'])) {
                foreach ($config['view']['layoutRootPaths'] as $path) {
                    $absLayoutPaths[] = GeneralUtility::getFileAbsFileName($path);
                }
            }
            if (count($absLayoutPaths) == 0) {
                $absLayoutPaths[] = GeneralUtility::getFileAbsFileName('EXT:nkc_event/Resources/Private/Layouts/');
            }

            $absPartialPaths = [];
            if (is_array($config['view']['partialRootPaths'])) {
                foreach ($config['view']['partialRootPaths'] as $path) {
                    $absPartialPaths[] = GeneralUtility::getFileAbsFileName($path);
                }
            }
            if (count($absPartialPaths) == 0) {
                $absPartialPaths[] = GeneralUtility::getFileAbsFileName('EXT:nkc_event/Resources/Private/Partials/');
            }

            $this->standaloneView = GeneralUtility::makeInstance(StandaloneView::class);

            $this->standaloneView->setLayoutRootPaths(
                $absLayoutPaths
            );
            $this->standaloneView->setPartialRootPaths(
                $absPartialPaths
            );
            $this->standaloneView->setTemplateRootPaths(
                $absTemplatePaths
            );

            $this->standaloneView->setTemplate($template);
        }

        $this->standaloneView->assignMultiple(['event' 	    => $event,
                                                    'addConfig'     => $addConfig,
                                                    'settings'	    => $this->settings]);

        return $this->standaloneView->render();
    }
    /**
     * @param array $eventList
     * @param array $config
     * @return string
     * @return array|mixed
     */
    public function retrieveMarkerInfo($eventList, $config)
    {
        parent::initializeAction();

        $this->eventRepository = $this->api->factory(EventRepository::class);
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $this->configurationManager = $objectManager->get(ConfigurationManager::class);

        $this->settings = $config['plugin']['tx_nkcevent_main']['settings'];

        $this->settings['flexform'] = $this->settings['flexformDefault'];

        $query = new EventQuery();

        $query->setInclude([Event::RELATION_ADDRESS, Event::RELATION_CHIEF_ORGANIZER, Event::RELATION_CATEGORY]);

        $query->setEvents($eventList);

        $events = $this->eventRepository->get($query);

        $result = '';

        foreach($events  as $event) {
            $digitalEvent = false;
            foreach($event->getCategories() as $category) {
                if (intval($category->getId()) == intval($this->settings['digitalEventCategoryId'])) {
                    $digitalEvent = true;
                    break;
                }
            }
            $result .= $this->renderMapInfo($event, ['digitalEvent' => $digitalEvent], 'Event/AsyncMapInfo');
        }

        return $result;
    }

    /**
     * @throws StopActionException
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\UnsupportedRequestTypeException
     */
    public function redirectAction()
    {
        if ($nkci = intval(GeneralUtility::_GP('nkce'))) {

            $this->uriBuilder->reset()->setTargetPageUid($this->settings['flexform']['pidSingle']);
            $uri = $this->uriBuilder->uriFor('show', ['uid' => $nkci]);
            $this->redirectToURI($uri);
        }  else {
            $this->redirectToURI('/');
        }
    }

    /**
     * @return array
     */
    private function getFilterValues()
    {
        $filter = [];

        $filter['dates'] = $this->filterDateRepository->findDatesByStoragePid($this->settings['filter']['pidDateFilter'])->toArray();

        foreach ($this->settings['relativeFilterDates'] as $filterDateData) {
            /** @var FilterDate $filterDate */
            $filterDate = GeneralUtility::makeInstance(FilterDate::class);
            $filterDate->setName(LocalizationUtility::translate($filterDateData['label'], 'NkcEvent'));
            $filterDate->setDateFrom(new \DateTime());
            $filterDate->setDateTo(new \DateTime(date('d.m.Y H:i', time() + $filterDateData['time'])));
            $filter['dates'][] = $filterDate;
        }

        if ($this->settings['filter']['cityCollection']) {
            $cities = GeneralUtility::trimExplode(',', $this->settings['filter']['cityCollection']);

            $index = 0;

            if (count($cities)) {
                $institutionCollection = $this->getFilterInstitutions($this->settings['filter']['institutionCollection']);

                foreach ($cities as $city) {
                    $filter['cities'][$index] = [];
                    $filter['cities'][$index]['name'] = $city;

                    /** @var Institution $institution */
                    if ($institutionCollection && $institutionCollection->getRecordCount() > 0) {
                        foreach ($institutionCollection as $institution) {
                            /** @var Address $address */
                            $address = $institution->getAddress();
                            if (($address instanceof Address) && ($address->getCity() == $city)) {
                                $filter['cities'][$index]['locations'][] = [
                                    'name'  => $institution->getOfficialName() ?: $institution->getName(),
                                    'id'    => $institution->getId()
                                ];
                            }
                        }
                    }
                    $index++;
                }
            }
        }

        if ($this->settings['filter']['categoryCollection']) {
            $categories = GeneralUtility::trimExplode(',', $this->settings['filter']['categoryCollection']);
            foreach ($categories as $categoryUid) {
                $category = $this->categoryRepository->findByUid($categoryUid);
                if ($category instanceof Category) {
                    $filter['categories'][] = [
                                                'uid'   => $category->getUid(),
                                                'label' => $category->getTitle()
                    ];
                }
            }
        }

        return $filter;
    }

    /**
     * @param $institutionCollection
     * @return bool|Result
     */
    private function getFilterInstitutions($institutionCollection)
    {
        if ($this->settings['filter']['institutionCollection']) {
            $napiService = $this->api->factory()->get(NapiService::class);

            $idList = [];

            foreach (GeneralUtility::trimExplode(',', $institutionCollection) as $url) {
                $urlParts = parse_url($url);
                list($type, $id) = $napiService::parseResourceUrl($urlParts);
                $idList[] = $id;
            }

            if (count($idList)) {
                $query = new InstitutionQuery();
                $query->setPageSize(99);
                $query->setInstitutions($idList);
                $query->setInclude([Institution::RELATION_ADDRESS]);

                $institutions = $this->institutionRepository->get($query);
            } else {
                $institutions = false;
            }
        } else {
            $institutions = false;
        }
        return $institutions;
    }

    public function searchFormAction(): ResponseInterface
    {
        $this->view->assignMultiple([
            'searchPid' => $this->settings['flexform']['pidList'] ? $this->settings['flexform']['pidList'] : $GLOBALS['TSFE']->id,
            'filter' => $this->getFilterValues()
        ]);
        return $this->htmlResponse();
    }

    /**
     * @param SearchRequest $searchRequest
     * @throws StopActionException
     */
    public function searchAction($searchRequest = null)
    {
        if (!($searchRequest instanceof SearchRequest)) {
            $searchRequest = GeneralUtility::makeInstance(SearchRequest::class);
        }

        // $this->forward('list', NULL, NULL, ['searchRequest' => $searchRequest->toArray()]);
        $this->uriBuilder->setRequest($this->request);
        $uri = $this->uriBuilder->uriFor('list', ['searchRequest' => $searchRequest->toArray()]);
        $this->redirectToURI($uri);
    }

    /**
     * initializeDataAction
     */
    public function initializeDataAction()
    {
        $this->defaultViewObjectName = JsonView::class;
    }

    /**
     * @param SearchRequest $searchRequest
     * @param int $forceReload
     */
    public function dataAction($searchRequest = null, $forceReload = 0): ResponseInterface
    {

        $this->view->setVariablesToRender(['json']);

        // Get current cObj
        $cObj = $this->configurationManager->getContentObject();

        $query = new EventQuery();

        $query->setPageSize(50);

        $query->setInclude([Event::RELATION_ADDRESS, Event::RELATION_CATEGORY]);

        if ($searchRequest instanceof SearchRequest) {
            $this->setUserFilters($query, $searchRequest);
        } else {
            $searchRequest = new SearchRequest();

            // Set start date - napi delivers running events
            $query->setTimeFromStart(new \DateTime(date('Y-m-d')));
        }

        $cacheInstance = GeneralUtility::makeInstance(CacheManager::class)->getCache('tx_nkgooglemaps');

        $mapMarkerJson = $cacheInstance->get($this->getCacheKey($cObj, $searchRequest));

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
            $this->setFlexformFilters($query, $this->settings['flexform']);

            $events = ApiService::getAllItems($this->eventRepository, $query, [Event::RELATION_ADDRESS, Event::RELATION_CATEGORY]);

            $mapMarkerJson = json_encode(['crdate' => time(), 'data' => $this->getMapMarkers($events)]);

            $cacheInstance->set($this->getCacheKey($cObj, $searchRequest), $mapMarkerJson);
        }

        $this->view->assignMultiple(['json' => json_decode($mapMarkerJson, TRUE)]);
        return $this->htmlResponse();
    }

    /**
     * @param $cObj
     * @param $searchRequest
     * @return string
     */
    private function getCacheKey($cObj, $searchRequest)
    {
        $searchQuery = ($searchRequest instanceof SearchRequest) ? serialize($searchRequest->toArray()) : '';
        $key = 'tx_nkcevent_map--dataAction--' . $cObj->data['tstamp'] . $searchQuery;
        return $cObj->data['uid'] . '--' . md5($key);
    }

    /**
     * initializePaginatedDataAction
     */
    public function initializePaginatedDataAction()
    {
        $this->defaultViewObjectName = JsonView::class;
    }

    /**
     * @param SearchRequest $searchRequest
     * @param int $page
     * @param string $requestId
     * @return string
     */
    public function paginatedDataAction($searchRequest = NULL, $page = 1, $requestId = ''): ResponseInterface
    {

        if (!trim($requestId)) {
            return $this->htmlResponse('[]');
        }

        $result = [];

        $this->view->setVariablesToRender(['json']);

        // Manually activation of pagination mode
        $this->settings['flexform']['paginate']['mode'] = 1;

        // Get current cObj
        $cObj = $this->configurationManager->getContentObject();

        /** @var EventQuery $query */
        $query = new EventQuery();

        $query->setInclude([Event::RELATION_ADDRESS, Event::RELATION_CATEGORY]);

        $query->setPageSize(100);

        if ($searchRequest instanceof SearchRequest) {
            $this->setUserFilters($query, $searchRequest);
        } else {
            $searchRequest = new SearchRequest();

            // Set start date - napi delivers running events
            $query->setTimeFromStart(new \DateTime(date('Y-m-d')));
        }

        $cacheInstance = GeneralUtility::makeInstance(CacheManager::class)->getCache('tx_nkgooglemaps');

        $mapMarkerJson = $cacheInstance->get($this->getCacheKey($cObj, $searchRequest));

        $markerCounter = 0;

        if (trim($mapMarkerJson)) {
            $result = json_decode($mapMarkerJson, TRUE);
            $markerCounter = sizeof($result['data']);
        }

        if ($markerCounter > 0) {
            $this->view->assign('json', $result);
        } else {

            // Try to get paginated cache
            $mapMarkerJson = $cacheInstance->get($this->getCacheKey($cObj, $searchRequest).'-'.$requestId.'-'.$page);

            if (trim($mapMarkerJson)) {
                $result = json_decode($mapMarkerJson, TRUE);
                $this->view->assign('json', $result);
            } else {

                // Set pagination parameters
                $query->setPageNumber($page);

                $this->setFlexformFilters($query, $this->settings['flexform']);

                if ($searchRequest instanceof SearchRequest) {
                    $this->setUserFilters($query, $searchRequest);
                } else {
                    $searchRequest = new SearchRequest();

                    // Set start date - napi delivers running events
                    $query->setTimeFromStart(new \DateTime(date('Y-m-d')));
                }

                // Get events
                $events = $this->eventRepository->get($query);

                $mapMarkers = $this->getMapMarkers($events);

                if (sizeof($mapMarkers) == 0) {
                    $this->cacheCleanGarbage($this->getCacheKey($cObj, $searchRequest), $requestId, $page);
                }

                $mapMarkerJson = json_encode(['crdate' => time(), 'data' => $mapMarkers]);

                $cacheInstance->set($this->getCacheKey($cObj, $searchRequest).'-'.$requestId.'-'.$page, $mapMarkerJson);

                $this->view->assign('json', ['data' => $mapMarkers]);
            }
        }
        return $this->htmlResponse();
    }

    /**
     * @param $cacheKey
     * @param $requestId
     * @param $numPages
     */
    private function cacheCleanGarbage($cacheKey, $requestId, $numPages) {
        $cacheInstance = GeneralUtility::makeInstance(CacheManager::class)->getCache('tx_nkgooglemaps');
        $markerArray = [];
        for($page=1; $page <= $numPages; $page++) {
            $mapMarkerJson = $cacheInstance->get($cacheKey.'-'.$requestId.'-'.$page);
            if ($mapMarkerJson) {
                $mapMarkers = json_decode($mapMarkerJson, TRUE);
                if (sizeof($mapMarkers)) {
                    $markerArray = array_merge($markerArray, $mapMarkers['data']);
                    $cacheInstance->set($cacheKey.'-'.$requestId.'-'.$page, '');
                }
            }
        }

        if (sizeof($markerArray)) {
            $mapMarkerJson = json_encode(['crdate' => time(), 'data' => $markerArray]);
            $cacheInstance->set($cacheKey, $mapMarkerJson);
        }
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

        $mapMarkerJson = $cacheInstance->get($this->getCacheKey($cObj, false));

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

            $query = new EventQuery();

            $includes = [Event::RELATION_CATEGORY, Event::RELATION_ADDRESS];

            $this->setFlexformFilters($query, $this->settings['flexform']);

            // Set start date - napi delivers running events
            $query->setTimeFromStart(new \DateTime(date('Y-m-d')));

            $events = ApiService::getAllItems($this->eventRepository, $query, $includes);

            $mapMarkerJson = json_encode(['crdate' => time(), 'data' => $this->getMapMarkers($events)]);

            $cacheInstance->set($this->getCacheKey($cObj, null), $mapMarkerJson);
        }
    }

    /**
     * @param int $uid
     */
    public function showAction($uid = null): ResponseInterface
    {
        $includes = [Event::RELATION_CHIEF_ORGANIZER, Event::RELATION_CATEGORY, Event::RELATION_ADDRESS];

        if ($this->settings['flexform']['singleEvent']) {
            // Event is selected in flexform
            try {
                $event = $this->napiService->resolveUrl($this->settings['flexform']['singleEvent'], $includes);
            } catch (\Exception $e) {
                $event = false;
            }
        } elseif ((int)$uid) {
            // Find by uid
            try {
                $event = $this->eventRepository->getById($uid, $includes);
            } catch (\Exception $e) {
                $event = false;
            }
        } else {
            $event = false;
        }

        $this->settings['mapInfo']['recordUid'] = $event ? $event->getId() : 0;

        // Get map markers for all functions
        $mapMarker = $event ? $this->getMapMarker($event) : [];

        // Get current cObj
        $cObj = $this->configurationManager->getContentObject();

        $this->view->assignMultiple([
            'event' => $event,
            'mapMarker' => $mapMarker,
            'content' => $cObj->data
        ]);
        return $this->htmlResponse();
    }

    /**
     * @param $event
     * @return array|mixed
     */
    private function getMapMarker($event)
    {
        $mapMarkers = [];

        $this->createMarker($mapMarkers, $event);

        if (count($mapMarkers)) {
            return array_pop($mapMarkers);
        }

        return [];
    }

    /**
     * @param int $uid
     */
    public function exportAction($uid): ResponseInterface
    {
        // Find by uid
        $event = $this->eventRepository->getById($uid);

        if ($event instanceof Event) {
            ExportService::renderCalendar([$event]);
        }
        return $this->htmlResponse();
    }

    /**
     * @param $query
     * @param $filter
     */
    private function setChiefOrganizerFilter($query, $filter)
    {
        if ($filter) {
            if (is_array($filter)) {
                $resourceArray = GeneralUtility::trimExplode(',', $filter);
                $query->setChiefOrganizer($resourceArray[0]);
            } else {
                $query->setChiefOrganizer($filter);
            }
        }
    }

    public function injectFilterDateRepository(FilterDateRepository $filterDateRepository): void
    {
        $this->filterDateRepository = $filterDateRepository;
    }

    public function injectCategoryRepository(CategoryRepository $categoryRepository): void
    {
        $this->categoryRepository = $categoryRepository;
    }
}
