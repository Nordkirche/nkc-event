<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Controller;

use Nordkirche\Ndk\Domain\Model\Event\Event;
use Nordkirche\Ndk\Domain\Query\EventQuery;
use Nordkirche\Ndk\Domain\Repository\EventRepository;
use Nordkirche\Ndk\Domain\Repository\InstitutionRepository;
use Nordkirche\NkcBase\Controller\BaseController;
use Nordkirche\NkcBase\MapCache\MapMarkerCache;
use Nordkirche\NkcBase\Service\TemplateRenderer;
use Nordkirche\NkcEvent\Domain\Dto\SearchRequest;
use Nordkirche\NkcEvent\Event\ModifyAssignedListValuesEvent;
use Nordkirche\NkcEvent\Event\ModifyAssignedValuesEvent;
use Nordkirche\NkcEvent\Event\ModifyEventQueryEvent;
use Nordkirche\NkcEvent\Service\CalendarBuilder;
use Nordkirche\NkcEvent\Service\DisplayedEvents;
use Nordkirche\NkcEvent\Service\EventMapItemService;
use Nordkirche\NkcEvent\Service\EventMarkerService;
use Nordkirche\NkcEvent\Service\EventQueryService;
use Nordkirche\NkcEvent\Service\ExportService;
use Nordkirche\NkcEvent\Service\SearchFilterService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\PropagateResponseException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Http\ForwardResponse;
use TYPO3\CMS\Frontend\Controller\ErrorController;
use TYPO3\CMS\Frontend\Page\PageAccessFailureReasons;

/**
 * Plugins "Veranstaltungen: Liste", "Kalender", "Detail", "Suchformular" and "Umleitung"
 */
class EventController extends BaseController
{
    /**
     * Up to this number of events the list map gets its markers directly
     */
    private const INLINE_MARKER_LIMIT = 25;

    /**
     * Suggestions of the search field
     */
    private const AUTOCOMPLETE_LIMIT = 8;

    /**
     * Events of a month in the calendar, at most (pages of 100)
     */
    private const CALENDAR_MAX_PAGES = 10;

    /**
     * @var EventRepository
     */
    protected $eventRepository;

    /**
     * @var InstitutionRepository
     */
    protected $institutionRepository;

    public function __construct(
        private readonly EventQueryService $queryService,
        private readonly EventMarkerService $markerService,
        private readonly EventMapItemService $mapItemService,
        private readonly MapMarkerCache $mapMarkerCache,
        private readonly TemplateRenderer $templateRenderer,
        private readonly ExportService $exportService,
        private readonly SearchFilterService $searchFilterService,
        private readonly DisplayedEvents $displayedEvents,
        private readonly CalendarBuilder $calendarBuilder,
    ) {}

    public function initializeAction(): void
    {
        parent::initializeAction();
        $this->eventRepository = $this->api->factory(EventRepository::class);
        $this->institutionRepository = $this->api->factory(InstitutionRepository::class);

        if ($this->arguments->hasArgument('searchRequest')) {
            $this->arguments->getArgument('searchRequest')->getPropertyMappingConfiguration()
                ->allowProperties('search', 'city', 'location', 'dateFrom', 'dateTo', 'category', 'eventType', 'organizer');
        }
    }

    public function listAction(int $currentPage = 1, ?SearchRequest $searchRequest = null): ResponseInterface
    {
        $query = new EventQuery();
        $query->setInclude([Event::RELATION_ADDRESS, Event::RELATION_CHIEF_ORGANIZER, Event::RELATION_CATEGORY]);
        $this->queryService->applyListFilters($query, (array)($this->settings['flexform'] ?? []), $this->settings);

        $searchRequest ??= $this->getPresetSearchRequest();
        if ($searchRequest instanceof SearchRequest) {
            $this->queryService->applyUserFilters($query, $searchRequest);
        } else {
            $searchRequest = new SearchRequest();
            // The NAPI delivers running events, too
            $query->setTimeFromStart(new \DateTime('today'));
        }

        $this->setPagination($query, $currentPage);

        // Leave out the events of the lists above (only without pagination: the pages would shift)
        $excludeDisplayed = !empty($this->settings['flexform']['excludeAlreadyDisplayed']) && !$this->isPaginationActive() && $this->displayedEvents->count() > 0;
        $limit = $query->getPageSize();
        if ($excludeDisplayed) {
            $query->setPageSize(min(100, $limit + $this->displayedEvents->count()));
        }

        /** @var ModifyEventQueryEvent $event */
        $event = $this->eventDispatcher->dispatch(new ModifyEventQueryEvent($this, $query, $this->request, $this->settings));
        $query = $event->getEventQuery();

        $events = $this->fetchList($this->eventRepository, $query);
        if ($excludeDisplayed) {
            $events = $this->displayedEvents->filter($events, $limit);
            $query->setPageSize($limit);
        }
        $this->displayedEvents->add($events);
        $content = $this->getContentRow();
        $isPageView = $this->isPageView();

        $mapMarkers = [];
        $requestUri = '';
        if ($events->getRecordCount() <= self::INLINE_MARKER_LIMIT) {
            if ($isPageView) {
                $mapMarkers = $this->mapItemService->createMarkers($events, $this->settings);
            }
        } else {
            // Too many events: the map loads the markers asynchronously
            $action = empty($this->settings['flexform']['stream']) ? 'data' : 'paginatedData';
            $uri = $this->uriBuilder->reset()
                ->setTargetPageUid($this->getCurrentPageId())
                ->setTargetPageType((int)($this->settings['ajaxTypeNum'] ?? 0))
                ->setArguments([
                    'tx_nkcevent_list' => ['action' => $action, 'searchRequest' => $searchRequest->toArray()],
                    'uid' => $content['uid'] ?? 0,
                ])
                ->build();
            if ($action === 'data') {
                $requestUri = $uri;
            } else {
                $this->view->assign('streamUri', $uri);
            }
        }

        $assignedListValues = [
            'query' => $query,
            'events' => $events,
            'content' => $content,
            'searchPid' => $this->getCurrentPageId(),
            'searchRequest' => $searchRequest,
            'filter' => $isPageView ? $this->getFilterValues() : [],
            'mapMarkers' => $mapMarkers,
            'requestUri' => $requestUri,
            'organizer' => $searchRequest->getOrganizer() ? $this->getOrganizer((int)$searchRequest->getOrganizer()) : false,
            'pagination' => $this->getPagination($events, $currentPage),
            'templateLayout' => $this->getTemplateLayout(),
            'teaserPartial' => $this->getLayoutPartial('Event/Teaser'),
            'autocompleteUri' => $this->getAutocompleteUri(),
            'autocompleteParam' => $this->getAutocompleteParameter(),
        ];

        /** @var ModifyAssignedListValuesEvent $event */
        $event = $this->eventDispatcher->dispatch(new ModifyAssignedListValuesEvent($this, $assignedListValues, $this->request));
        $this->view->assignMultiple($event->getAssignedListValues());

        return $this->htmlResponse();
    }

    /**
     * @deprecated Use EventQueryService::applyListFilters()
     */
    public function setFlexformFilters(EventQuery $query, array $flexform): void
    {
        $this->queryService->applyListFilters($query, $flexform);
    }

    public function searchFormAction(?SearchRequest $searchRequest = null): ResponseInterface
    {
        if (!$searchRequest instanceof SearchRequest) {
            $searchRequest = new SearchRequest();
            $searchRequest->decorate(
                $this->request->getParsedBody()['tx_nkcevent_list']['searchRequest']
                ?? $this->request->getQueryParams()['tx_nkcevent_list']['searchRequest']
                ?? null
            );
        }

        if ($searchRequest->toArray() === [] && ($preset = $this->getPresetSearchRequest()) instanceof SearchRequest) {
            $searchRequest = $preset;
        }

        $this->view->assignMultiple([
            'searchPid' => (int)($this->settings['flexform']['pidList'] ?? 0) ?: $this->getCurrentPageId(),
            'filter' => $this->getFilterValues(),
            'searchRequest' => $searchRequest,
            'autocompleteUri' => $this->getAutocompleteUri(),
            'autocompleteParam' => $this->getAutocompleteParameter(),
        ]);
        return $this->htmlResponse();
    }

    /**
     * Month sheet with the events of each day (plugin "Veranstaltungen: Kalender")
     */
    public function calendarAction(int $month = 0, int $year = 0): ResponseInterface
    {
        [$year, $month] = $this->calendarBuilder->normalize($year, $month);
        $start = new \DateTime(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $end = (clone $start)->modify('last day of this month')->setTime(23, 59, 59);

        $query = new EventQuery();
        $query->setInclude([Event::RELATION_ADDRESS, Event::RELATION_CATEGORY]);
        $this->queryService->applyListFilters($query, (array)($this->settings['flexform'] ?? []), $this->settings);
        // All events of the month, also those that began before
        $query->setTimeFromStart($start);
        $query->setTimeToEnd($end);
        $query->setPageSize(100);

        /** @var ModifyEventQueryEvent $event */
        $event = $this->eventDispatcher->dispatch(new ModifyEventQueryEvent($this, $query, $this->request, $this->settings));
        $query = $event->getEventQuery();

        $events = [];
        for ($page = 1; $page <= self::CALENDAR_MAX_PAGES; $page++) {
            $query->setPageNumber($page);
            $result = $this->fetchList($this->eventRepository, $query);
            foreach ($result as $item) {
                $events[] = $item;
            }
            if ($page >= $result->getPageCount()) {
                break;
            }
        }

        $content = $this->getContentRow();
        $this->view->assignMultiple([
            'calendar' => $this->calendarBuilder->build($year, $month, $events),
            'content' => $content,
            'ajaxTypeNum' => (int)($this->settings['ajaxListTypeNum'] ?? 0),
        ]);
        return $this->htmlResponse();
    }

    /**
     * Event titles for the search field (JSON), with the filters of the plugin
     */
    public function autocompleteAction(string $q = ''): ResponseInterface
    {
        $q = trim($q);
        if (mb_strlen($q) < 3) {
            return $this->jsonResponse('[]');
        }

        $query = new EventQuery();
        $query->setPageSize(self::AUTOCOMPLETE_LIMIT * 2);
        $this->queryService->applyListFilters($query, (array)($this->settings['flexform'] ?? []), $this->settings);
        $query->setQuery($q);
        $query->setTimeFromStart(new \DateTime('today'));

        $titles = [];
        foreach ($this->fetchList($this->eventRepository, $query) as $event) {
            $title = trim((string)$event->getTitle());
            if ($title !== '' && !in_array($title, $titles, true)) {
                $titles[] = $title;
            }
            if (count($titles) >= self::AUTOCOMPLETE_LIMIT) {
                break;
            }
        }
        return $this->jsonResponse((string)json_encode($titles, JSON_UNESCAPED_UNICODE));
    }

    public function searchAction(?SearchRequest $searchRequest = null): ResponseInterface
    {
        $searchRequest ??= new SearchRequest();
        return (new ForwardResponse('list'))->withArguments(['searchRequest' => $searchRequest->toArray()]);
    }

    /**
     * All markers of the list map as JSON. Without a search of the visitor the
     * markers come from the nkc_base map marker cache.
     */
    public function dataAction(?SearchRequest $searchRequest = null): ResponseInterface
    {
        if ($searchRequest instanceof SearchRequest && $this->hasSearch($searchRequest)) {
            $result = $this->mapItemService->getListItems($this->settings, $searchRequest, true);
            $entry = ['crdate' => time(), 'data' => $this->mapItemService->createMarkers($result['items'], $this->settings)];
        } else {
            $entry = $this->mapMarkerCache->get($this->getContentRow(), $this->settings, $this->request->getAttribute('site'));
        }
        return $this->jsonResponse((string)json_encode($entry + ['complete' => true]));
    }

    /**
     * Markers of the list map page by page
     */
    public function paginatedDataAction(?SearchRequest $searchRequest = null, int $page = 1): ResponseInterface
    {
        $row = $this->getContentRow();
        $hasSearch = $searchRequest instanceof SearchRequest && $this->hasSearch($searchRequest);

        if (!$hasSearch && $this->mapMarkerCache->has($row)) {
            $entry = $page === 1 ? $this->mapMarkerCache->get($row, $this->settings, $this->request->getAttribute('site')) : ['data' => []];
            return $this->jsonResponse((string)json_encode(['data' => $entry['data'], 'complete' => true]));
        }

        $result = $this->mapItemService->getListItems($this->settings, $hasSearch ? $searchRequest : null, false, $page);
        $markers = $this->mapItemService->createMarkers($result['items'], $this->settings);

        return $this->jsonResponse((string)json_encode([
            'data' => $markers,
            'complete' => $markers === [] || $page >= $result['pageCount'],
        ]));
    }

    /**
     * @throws PropagateResponseException
     */
    public function showAction(int $uid = 0): ResponseInterface
    {
        $includes = [Event::RELATION_CHIEF_ORGANIZER, Event::RELATION_CATEGORY, Event::RELATION_ADDRESS];
        $event = null;
        try {
            if (!empty($this->settings['flexform']['singleEvent'])) {
                $event = $this->napiService->resolveUrl((string)$this->settings['flexform']['singleEvent'], $includes);
            } elseif ($uid > 0) {
                $event = $this->eventRepository->getById($uid, $includes);
            }
        } catch (\Throwable) {
            $event = null;
        }

        if (!$event instanceof Event && $uid > 0) {
            $this->throwPageNotFound('Veranstaltung konnte nicht gefunden werden');
        }

        $this->settings['mapInfo']['recordUid'] = $event instanceof Event ? $event->getId() : 0;

        $assignedValues = [
            'event' => $event instanceof Event ? $event : false,
            'mapMarker' => $event instanceof Event ? ($this->markerService->createMarker($event, $this->settings) ?? []) : [],
            'content' => $this->getContentRow(),
        ];

        /** @var ModifyAssignedValuesEvent $modifyEvent */
        $modifyEvent = $this->eventDispatcher->dispatch(new ModifyAssignedValuesEvent($this, $assignedValues, $this->request));
        $this->view->assignMultiple($modifyEvent->getAssignedValues());

        return $this->htmlResponse();
    }

    /**
     * iCalendar file of an event
     *
     * @throws PropagateResponseException
     */
    public function exportAction(int $uid = 0): ResponseInterface
    {
        try {
            $event = $uid > 0 ? $this->eventRepository->getById($uid, [Event::RELATION_ADDRESS]) : null;
        } catch (\Throwable) {
            $event = null;
        }
        if (!$event instanceof Event) {
            $this->throwPageNotFound('Veranstaltung konnte nicht gefunden werden');
        }

        $export = $this->exportService->export($event, $this->request);

        $response = $this->responseFactory->createResponse()
            ->withHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $export['fileName'] . '"')
            ->withBody($this->streamFactory->createStream($export['calendar']));

        // Leave the page rendering, the file is the complete response
        throw new PropagateResponseException($response, 1760889800);
    }

    public function redirectAction(): ResponseInterface
    {
        $nkce = (int)($this->request->getParsedBody()['nkce'] ?? $this->request->getQueryParams()['nkce'] ?? 0);
        if ($nkce <= 0) {
            return $this->redirectToUri('/');
        }
        $uri = $this->uriBuilder->reset()
            ->setTargetPageUid((int)($this->settings['flexform']['pidSingle'] ?? 0))
            ->uriFor('show', ['uid' => $nkce], 'Event', 'NkcEvent', 'Show');
        return $this->redirectToUri($uri);
    }

    /**
     * Info window of an event marker, rendered with the plugin settings
     */
    public function renderMapInfo(Event $event, string $template = 'Event/MapInfo'): string
    {
        $configuration = $this->configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK);
        return $this->templateRenderer->render($template, [
            'event' => $event,
            'settings' => $this->settings,
            'addConfig' => [
                'digitalEvent' => $this->markerService->isDigital($event, $this->settings),
                'hybridEvent' => $this->markerService->isHybrid($event, $this->settings),
            ],
        ], TemplateRenderer::pathsFromConfiguration($configuration), $this->request);
    }

    protected function getOrganizer(int $id): mixed
    {
        try {
            return $this->institutionRepository->getById($id);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Search of the visitors preset in the plugin (settings.flexform.presets), if there is any
     */
    private function getPresetSearchRequest(): ?SearchRequest
    {
        $presets = array_filter(
            (array)($this->settings['flexform']['presets'] ?? []),
            static fn(mixed $value): bool => is_scalar($value) && (string)$value !== '' && (string)$value !== '0'
        );
        if ($presets === []) {
            return null;
        }
        $searchRequest = new SearchRequest();
        $searchRequest->decorate(array_intersect_key($presets, array_flip(['city', 'eventType', 'category', 'search'])));
        return $searchRequest->toArray() !== [] ? $searchRequest : null;
    }

    /**
     * Suggestions for the search field (settings.filter.autocomplete)
     */
    private function getAutocompleteUri(): string
    {
        $typeNum = (int)($this->settings['ajaxTypeNum'] ?? 0);
        if (empty($this->settings['filter']['autocomplete']) || $typeNum <= 0) {
            return '';
        }
        return $this->uriBuilder->reset()
            ->setTargetPageUid($this->getCurrentPageId())
            ->setTargetPageType($typeNum)
            ->setArguments(['uid' => $this->getContentRow()['uid'] ?? 0])
            ->uriFor('autocomplete');
    }

    /**
     * Query parameter of the search term for the autocomplete action
     */
    private function getAutocompleteParameter(): string
    {
        return 'tx_nkcevent_' . strtolower((string)$this->request->getPluginName()) . '[q]';
    }

    private function getFilterValues(): array
    {
        return $this->searchFilterService->getFilterValues($this->settings, $this->request);
    }

    private function hasSearch(SearchRequest $searchRequest): bool
    {
        return $searchRequest->toArray() !== [];
    }

    /**
     * Normal page view (not one of the AJAX page types)
     */
    private function isPageView(): bool
    {
        $routing = $this->request->getAttribute('routing');
        return !is_object($routing) || !method_exists($routing, 'getPageType') || in_array((string)$routing->getPageType(), ['', '0'], true);
    }

    private function getContentRow(): array
    {
        $contentObject = $this->request->getAttribute('currentContentObject');
        return is_object($contentObject) ? (array)$contentObject->data : [];
    }

    /**
     * @throws PropagateResponseException
     */
    private function throwPageNotFound(string $reason): never
    {
        $response = GeneralUtility::makeInstance(ErrorController::class)->pageNotFoundAction(
            $this->request,
            $reason,
            ['code' => PageAccessFailureReasons::PAGE_NOT_FOUND]
        );
        throw new PropagateResponseException($response, 1760889801);
    }
}
