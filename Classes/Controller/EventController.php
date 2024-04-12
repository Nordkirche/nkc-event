<?php

namespace Nordkirche\NkcEvent\Controller;

use Nordkirche\Ndk\Domain\Interfaces\ModelInterface;
use Nordkirche\Ndk\Domain\Model\Address;
use Nordkirche\Ndk\Domain\Model\Event\Event;
use Nordkirche\Ndk\Domain\Model\Geocode;
use Nordkirche\Ndk\Domain\Model\Institution\Institution;
use Nordkirche\Ndk\Domain\Query\EventQuery;
use Nordkirche\Ndk\Domain\Query\InstitutionQuery;
use Nordkirche\Ndk\Domain\Repository\EventRepository;
use Nordkirche\Ndk\Domain\Repository\InstitutionRepository;
use Nordkirche\Ndk\Domain\Repository\PersonRepository;
use Nordkirche\Ndk\Service\Interfaces\QueryInterface;
use Nordkirche\Ndk\Service\NapiService;
use Nordkirche\Ndk\Service\Result;
use Nordkirche\NkcBase\Controller\BaseController;
use Nordkirche\NkcBase\Domain\Repository\CategoryRepository;
use Nordkirche\NkcBase\Exception\ApiException;
use Nordkirche\NkcBase\Service\ApiService;
use Nordkirche\NkcEvent\Domain\Dto\SearchRequest;
use Nordkirche\NkcEvent\Domain\Model\FilterDate;
use Nordkirche\NkcEvent\Domain\Repository\FilterDateRepository;
use Nordkirche\NkcEvent\Event\ModifyAssignedListValuesEvent;
use Nordkirche\NkcEvent\Event\ModifyAssignedValuesEvent;
use Nordkirche\NkcEvent\Event\ModifyEventQueryEvent;
use Nordkirche\NkcEvent\Service\ExportService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Domain\Model\Category;
use TYPO3\CMS\Extbase\Http\ForwardResponse;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

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

    /**
     * @var ServerRequestInterface
     */
    protected $middleWareRequest;

    /**
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\NoSuchArgumentException
     */
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
        $requestUri = '';

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

        /** @var ModifyEventQueryEvent $event */
        $event = $this->eventDispatcher->dispatch(
            new ModifyEventQueryEvent($this, $query, $this->request, $this->settings)
        );
        $query = $event->getEventQuery();

        // Get events
        $events = $this->eventRepository->get($query);

        // Get current cObj
        $cObj = $this->request->getAttribute('currentContentObject');

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
        } else {
            $mapMarkers = [];

            if (empty($this->settings['flexform']['stream'])) {
                $this->uriBuilder->reset()
                    ->setTargetPageUid($GLOBALS['TSFE']->id)
                    ->setTargetPageType($this->settings['ajaxTypeNum'])
                    ->setArguments([
                        'tx_nkcevent_list' => [
                            'action' => 'data',
                            'searchRequest' => $searchRequest->toArray(),
                        ],
                        'uid' => $cObj->data['uid'],
                    ]);

                $requestUri = $this->uriBuilder->build();
            } else {
                $this->uriBuilder->reset()
                    ->setTargetPageUid($GLOBALS['TSFE']->id)
                    ->setTargetPageType($this->settings['ajaxTypeNum'])
                    ->setArguments([
                        'tx_nkcevent_list' => [
                            'action' => 'paginatedData',
                            'searchRequest' => $searchRequest->toArray(),
                        ],
                        'uid' => $cObj->data['uid'],
                    ]);

                $this->view->assign('streamUri', $this->uriBuilder->build());
            }
        }

        $assignedListValues = [
            'query' => $query,
            'events' => $events,
            'content' => $cObj->data,
            'searchPid' => $GLOBALS['TSFE']->id,
            'searchRequest' => $searchRequest,
            'filter' => ($GLOBALS['TSFE']->type === 0) ? $this->getFilterValues() : [],
            'mapMarkers' => $mapMarkers,
            'requestUri' => $requestUri,
            'organizer' => $organizer,
            'pagination' => $this->getPagination($events, $currentPage),
        ];

        /** @var ModifyAssignedListValuesEvent $event */
        $event = $this->eventDispatcher->dispatch(
            new ModifyAssignedListValuesEvent($this, $assignedListValues, $this->request)
        );

        $assignedListValues = $event->getAssignedListValues();

        $this->view->assignMultiple($assignedListValues);

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
        $this->setOrganizersFilter($query, !empty($flexform['institutionCollection']) ? $flexform['institutionCollection'] : null);

        // Filter by target group
        $this->setTargetGroupFilter($query, !empty($flexform['targetGroupCollection']) ? $flexform['targetGroupCollection'] : null);

        if (!empty($flexform['eventTypes'])) {
            $eventTypes = GeneralUtility::trimExplode(',', $flexform['eventTypes']);
            $query->setEventType($eventTypes[0]);
        }

        if (!empty($flexform['eventLocation'])) {
            if ($this->napiService === null) {
                $this->api = ApiService::get();
                $this->napiService = $this->api->factory(NapiService::class);
            }

            $eventLocation = $this->napiService->resolveUrl($flexform['eventLocation']);
            if ($eventLocation->getName()) {
                $query->setLocation($eventLocation->getName());
            }
        }

        if (!empty($flexform['categories'])) {
            $categories = GeneralUtility::trimExplode(',', $flexform['categories']);
            if ($flexform['categoryOperator'] == QueryInterface::OPERATOR_AND) {
                $query->setCategoriesAnd($categories);
            } else {
                $query->setCategoriesOr($categories);
            }
        }

        if (!empty($flexform['dateFrom'])) {
            $query->setTimeFrom(new \DateTime(date('d.m.Y', $flexform['dateFrom'])));
        }

        if (!empty($flexform['dateTo'])) {
            $query->setTimeTo(new \DateTime(date('d.m.Y', $flexform['dateTo'])));
        }

        if (!empty($flexform['numDays']) && ((int)($flexform['numDays']) > 1)) {
            try {
                $date = new \DateTime();
                $interval = new \DateInterval('P' . (int)($flexform['numDays']) . 'D');
                $date->add($interval);
                $query->setTimeTo($date);
            } catch (\Exception $e) {
                // Invalid data: do nothing
            }
        }

        if (!empty($flexform['geosearch'])) {
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
    protected function setUserFilters($query, $searchRequest)
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
    protected function getOrganizer($id)
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
     * @param bool $asyncInfo
     */
    public function createMarker(&$mapMarkers, $event, $asyncInfo = true)
    {
        // Get type of event
        $mappingType = ($event->getEventType() ? $event->getEventType() : 'default');
        $type = 'event-' . $mappingType;
        $address = $event->getAddress();

        $digitalEvent = false;
        $hybridEvent = false;

        foreach ($event->getCategories() as $category) {
            if (in_array((int)($category->getId()), GeneralUtility::intExplode(',', $this->settings['hybridEventCategoryId']))) {
                $hybridEvent = true;
            }
            if (in_array((int)($category->getId()), GeneralUtility::intExplode(',', $this->settings['digitalEventCategoryId']))) {
                $digitalEvent = true;
            }
        }

        if ($hybridEvent) {
            $type = 'hybrid-' . $type;
            $mappingType = 'hybrid-' . $mappingType;
        } elseif ($digitalEvent) {
            $type = 'digital-' . $type;
            $mappingType = 'digital-' . $mappingType;
        }

        if ($address instanceof Address) {
            // Check geo coordinates
            if ($address->getLatitude() && $address->getLongitude()) {
                $marker = [

                    'lat' 	=> $address->getLatitude(),
                    'lon' 	=> $address->getLongitude(),
                    'info' 	=> $asyncInfo ? '' : $this->renderMapInfo($event, ['digitalEvent' => $digitalEvent, 'hybridEvent' => $hybridEvent]),
                    'type'  => $type,
                    'object' => 'e',
                    'id'    => $event->getId(),
                    'icon' 	=> PathUtility::getPublicResourceWebPath(sprintf(!empty($this->settings['eventIconName']) ? $this->settings['eventIconName'] : '%s', !empty($this->settings['mapping']['eventIcon'][$mappingType]) ? $this->settings['mapping']['eventIcon'][$mappingType] : 'gemeindeleben')),

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

            $config = $this->configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK);

            $this->standaloneView = GeneralUtility::makeInstance(StandaloneView::class);

            $this->standaloneView->setLayoutRootPaths(
                $this->getPath($config, 'layout', 'nkc_event')
            );
            $this->standaloneView->setPartialRootPaths(
                $this->getPath($config, 'partial', 'nkc_event')
            );
            $this->standaloneView->setTemplateRootPaths(
                $this->getPath($config, 'template', 'nkc_event')
            );

            $this->standaloneView->setTemplate($template);
        }

        $this->standaloneView->assignMultiple([
                                                'event' 	    => $event,
                                                'addConfig'     => $addConfig,
                                                'settings'	    => $this->settings]);

        $this->standaloneView->setRequest($this->middleWareRequest);

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
        $this->configurationManager = GeneralUtility::makeInstance(ConfigurationManager::class);

        $this->settings = $config['plugin']['tx_nkcevent_list']['settings'];

        $this->settings['flexform'] = $this->settings['flexformDefault'];

        $query = new EventQuery();

        $query->setInclude([Event::RELATION_ADDRESS, Event::RELATION_CHIEF_ORGANIZER, Event::RELATION_CATEGORY]);

        $query->setEvents($eventList);

        $events = $this->eventRepository->get($query);

        $result = '';

        foreach ($events  as $event) {
            $digitalEvent = false;
            $hybridEvent = false;

            foreach ($event->getCategories() as $category) {
                if (in_array((int)($category->getId()), GeneralUtility::intExplode(',', $this->settings['hybridEventCategoryId']))) {
                    $hybridEvent = true;
                }
                if (in_array((int)($category->getId()), GeneralUtility::intExplode(',', $this->settings['digitalEventCategoryId']))) {
                    $digitalEvent = true;
                }
            }
            $result .= $this->renderMapInfo($event, ['digitalEvent' => $digitalEvent, 'hybridEvent' => $hybridEvent], 'Event/AsyncMapInfo');
        }

        return $result;
    }

    /**
     * @return ResponseInterface
     */
    public function redirectAction()
    {
        $nkce = $this->request->getParsedBody()['nkce'] ?? $this->request->getQueryParams()['nkce'] ?? null;
        if ((int)$nkce) {
            $this->uriBuilder->reset()->setTargetPageUid($this->settings['flexform']['pidSingle']);
            $uri = $this->uriBuilder->uriFor('show', ['uid' => $nkce]);
        } else {
            $uri = '/';
        }
        return $this->redirectToURI($uri);
    }

    /**
     * @return array
     */
    private function getFilterValues()
    {
        $filter = [];

        $filter['dates'] = $this->filterDateRepository->findDatesByStoragePid(!empty($this->settings['filter']['pidDateFilter']) ? $this->settings['filter']['pidDateFilter'] : 0)->toArray();

        foreach ($this->settings['relativeFilterDates'] as $filterDateData) {
            /** @var FilterDate $filterDate */
            $filterDate = GeneralUtility::makeInstance(FilterDate::class);
            $filterDate->setName(LocalizationUtility::translate($filterDateData['label'], 'NkcEvent'));
            $filterDate->setDateFrom(new \DateTime());
            $filterDate->setDateTo(new \DateTime(date('d.m.Y H:i', time() + $filterDateData['time'])));
            $filter['dates'][] = $filterDate;
        }

        if (!empty($this->settings['filter']['cityCollection'])) {
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
                                    'id'    => $institution->getId(),
                                ];
                            }
                        }
                    }
                    $index++;
                }
            }
        }

        if (!empty($this->settings['filter']['categoryCollection'])) {
            $categories = GeneralUtility::trimExplode(',', $this->settings['filter']['categoryCollection']);
            foreach ($categories as $categoryUid) {
                $category = $this->categoryRepository->findByUid($categoryUid);
                if ($category instanceof Category) {
                    $filter['categories'][] = [
                                                'uid'   => $category->getUid(),
                                                'label' => $category->getTitle(),
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
        if (!empty($this->settings['filter']['institutionCollection'])) {
            $napiService = $this->api->factory()->get(NapiService::class);

            $idList = [];

            foreach (GeneralUtility::trimExplode(',', $institutionCollection) as $url) {
                $urlParts = parse_url($url);
                [$type, $id] = $napiService::parseResourceUrl($urlParts);
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

    /**
     * @param SearchRequest $searchRequest
     * @return ResponseInterface
     */
    public function searchFormAction(SearchRequest $searchRequest): ResponseInterface
    {
        if (!($searchRequest instanceof \Nordkirche\NkcEvent\Domain\Dto\SearchRequest)) {
            $searchRequest = GeneralUtility::makeInstance(SearchRequest::class);
            $searchRequest->decorate($this->request->getParsedBody()['tx_nkcevent_list']['searchRequest'] ?? $this->request->getQueryParams()['tx_nkcevent_list']['searchRequest'] ?? null);
        }

        $this->view->assignMultiple([
            'searchPid' => $this->settings['flexform']['pidList'] ?: $GLOBALS['TSFE']->id,
            'filter' => $this->getFilterValues(),
            'searchRequest' => $searchRequest,
        ]);
        return $this->htmlResponse();
    }

    /**
     * @param SearchRequest $searchRequest
     */
    public function searchAction(SearchRequest $searchRequest = null)
    {
        if (!($searchRequest instanceof \Nordkirche\NkcEvent\Domain\Dto\SearchRequest)) {
            $searchRequest = GeneralUtility::makeInstance(SearchRequest::class);
        }

        // Forward to list view
        $response = new ForwardResponse('list');
        return $response->withArguments(['searchRequest' => $searchRequest->toArray()]);
    }

    /**
     * @param SearchRequest|null $searchRequest
     * @param int $forceReload
     * @return ResponseInterface
     * @throws ApiException
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException
     */
    public function dataAction(SearchRequest $searchRequest = null, int $forceReload = 0): ResponseInterface
    {
        // Get current cObj
        $cObj = $this->request->getAttribute('currentContentObject');

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

        return $this->jsonResponse($mapMarkerJson);
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
     * @param SearchRequest $searchRequest
     * @param int $page
     * @param string $requestId
     * @return ResponseInterface
     * @throws ApiException
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException
     */
    public function paginatedDataAction(SearchRequest $searchRequest = null, int $page = 1, string $requestId = ''): ResponseInterface
    {
        if (!trim($requestId)) {
            return $this->htmlResponse('[]');
        }

        // Manually activation of pagination mode
        $this->settings['flexform']['paginate']['mode'] = 1;

        // Get current cObj
        $cObj = $this->request->getAttribute('currentContentObject');

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
            $result = json_decode($mapMarkerJson, true);
            $markerCounter = count($result['data']);
        }

        if ($markerCounter == 0) {
            // Try to get paginated cache
            $mapMarkerJson = $cacheInstance->get($this->getCacheKey($cObj, $searchRequest) . '-' . $requestId . '-' . $page);

            if (!trim($mapMarkerJson)) {
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

                if (count($mapMarkers) == 0) {
                    $this->cacheCleanGarbage($this->getCacheKey($cObj, $searchRequest), $requestId, $page);
                }

                $mapMarkerJson = json_encode(['crdate' => time(), 'data' => $mapMarkers]);

                $cacheInstance->set($this->getCacheKey($cObj, $searchRequest) . '-' . $requestId . '-' . $page, $mapMarkerJson);
            }
        }

        return $this->jsonResponse($mapMarkerJson);
    }

    /**
     * @param $cacheKey
     * @param $requestId
     * @param $numPages
     */
    private function cacheCleanGarbage($cacheKey, $requestId, $numPages)
    {
        $cacheInstance = GeneralUtility::makeInstance(CacheManager::class)->getCache('tx_nkgooglemaps');
        $markerArray = [];
        for ($page = 1; $page <= $numPages; $page++) {
            $mapMarkerJson = $cacheInstance->get($cacheKey . '-' . $requestId . '-' . $page);
            if ($mapMarkerJson) {
                $mapMarkers = json_decode($mapMarkerJson, true);
                if (count($mapMarkers)) {
                    $markerArray = array_merge($markerArray, $mapMarkers['data']);
                    $cacheInstance->set($cacheKey . '-' . $requestId . '-' . $page, '');
                }
            }
        }

        if (count($markerArray)) {
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
            $this->settings['eventIconName'] = $tsConfig['plugin']['tx_nkcevent_list']['settings']['eventIconName'];
            $this->settings['mapping'] = $tsConfig['plugin']['tx_nkcevent_list']['settings']['mapping'];

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

        if (!empty($this->settings['flexform']['singleEvent'])) {
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
        $cObj = $this->request->getAttribute('currentContentObject');

        $assignedValues = [
            'event' => $event,
            'mapMarker' => $mapMarker,
            'content' => $cObj->data,
        ];

        /** @var ModifyAssignedValuesEvent $event */
        $event = $this->eventDispatcher->dispatch(
            new ModifyAssignedValuesEvent($this, $assignedValues, $this->request)
        );

        $assignedValues = $event->getAssignedValues();

        $this->view->assignMultiple($assignedValues);

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
            if (str_contains($filter, ',')) {
                $resourceArray = GeneralUtility::trimExplode(',', $filter);
                $query->setChiefOrganizer($resourceArray[0]);
            } else {
                $query->setChiefOrganizer($filter);
            }
        }
    }

    /**
     * @param ServerRequestInterface $request
     */
    public function setMiddleWareRequest(ServerRequestInterface $request)
    {
        $this->middleWareRequest = $request;
    }

    /**
     * @param FilterDateRepository $filterDateRepository
     */
    public function injectFilterDateRepository(FilterDateRepository $filterDateRepository): void
    {
        $this->filterDateRepository = $filterDateRepository;
    }

    /**
     * @param CategoryRepository $categoryRepository
     */
    public function injectCategoryRepository(CategoryRepository $categoryRepository): void
    {
        $this->categoryRepository = $categoryRepository;
    }
}
