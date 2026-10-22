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
 * Plugins "Veranstaltungen: Liste", "Detail", "Suchformular" and "Umleitung"
 */
class EventController extends BaseController
{
    /**
     * Up to this number of events the list map gets its markers directly
     */
    private const INLINE_MARKER_LIMIT = 25;

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
    ) {}

    public function initializeAction(): void
    {
        parent::initializeAction();
        $this->eventRepository = $this->api->factory(EventRepository::class);
        $this->institutionRepository = $this->api->factory(InstitutionRepository::class);

        if ($this->arguments->hasArgument('searchRequest')) {
            $this->arguments->getArgument('searchRequest')->getPropertyMappingConfiguration()
                ->allowProperties('search', 'city', 'location', 'dateFrom', 'dateTo', 'category', 'organizer');
        }
    }

    public function listAction(int $currentPage = 1, ?SearchRequest $searchRequest = null): ResponseInterface
    {
        $query = new EventQuery();
        $query->setInclude([Event::RELATION_ADDRESS, Event::RELATION_CHIEF_ORGANIZER, Event::RELATION_CATEGORY]);
        $this->queryService->applyListFilters($query, (array)($this->settings['flexform'] ?? []));

        if ($searchRequest instanceof SearchRequest) {
            $this->queryService->applyUserFilters($query, $searchRequest);
        } else {
            $searchRequest = new SearchRequest();
            // The NAPI delivers running events, too
            $query->setTimeFromStart(new \DateTime('today'));
        }

        $this->setPagination($query, $currentPage);

        /** @var ModifyEventQueryEvent $event */
        $event = $this->eventDispatcher->dispatch(new ModifyEventQueryEvent($this, $query, $this->request, $this->settings));
        $query = $event->getEventQuery();

        $events = $this->fetchList($this->eventRepository, $query);
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

        $this->view->assignMultiple([
            'searchPid' => (int)($this->settings['flexform']['pidList'] ?? 0) ?: $this->getCurrentPageId(),
            'filter' => $this->getFilterValues(),
            'searchRequest' => $searchRequest,
        ]);
        return $this->htmlResponse();
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
