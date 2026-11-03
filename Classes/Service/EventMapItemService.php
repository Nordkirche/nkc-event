<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Service;

use Nordkirche\Ndk\Domain\Model\Event\Event;
use Nordkirche\Ndk\Domain\Query\EventQuery;
use Nordkirche\Ndk\Domain\Repository\EventRepository;
use Nordkirche\Ndk\Service\Result;
use Nordkirche\NkcBase\Service\ApiService;
use Nordkirche\NkcBase\Service\NdkApiFactory;
use Nordkirche\NkcEvent\Domain\Dto\SearchRequest;
use Nordkirche\NkcEvent\Event\ModifyMapEventQueryEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Events of the map plugins and the map of the list plugin
 *
 * Used by the controllers, the backend preview and the map marker provider.
 */
class EventMapItemService
{
    public const MAP_PAGE_SIZE = 50;
    public const STREAM_PAGE_SIZE = 100;

    public function __construct(
        private readonly NdkApiFactory $ndkApiFactory,
        private readonly EventQueryService $queryService,
        private readonly EventMarkerService $markerService,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * Events of the plugins "Karte" and "Karte und Liste"
     *
     * @return array{limitExceeded: bool, items: iterable<Event>, pageCount: int, facets: array}
     */
    public function getMapItems(array $settings, bool $allItems = false, int $page = 1): array
    {
        $query = new EventQuery();
        $query->setPageSize(self::MAP_PAGE_SIZE);
        $query->setPageNumber(max($page, 1));
        $query->setInclude([Event::RELATION_ADDRESS, Event::RELATION_CATEGORY, Event::RELATION_CHIEF_ORGANIZER]);
        $query->setFacets([Event::FACET_EVENT_TYPE]);
        $this->queryService->applyMapFilters($query, (array)($settings['flexform'] ?? []), $settings);

        $query = $this->dispatchQueryEvent($query, $settings, ModifyMapEventQueryEvent::CONTEXT_MAP);
        return $this->fetch($query, $allItems);
    }

    /**
     * Events for the map of the list plugin
     *
     * @return array{limitExceeded: bool, items: iterable<Event>, pageCount: int, facets: array}
     */
    public function getListItems(array $settings, ?SearchRequest $searchRequest, bool $allItems = false, int $page = 1, int $pageSize = self::STREAM_PAGE_SIZE): array
    {
        $query = new EventQuery();
        $query->setPageSize($pageSize);
        $query->setPageNumber(max($page, 1));
        $query->setInclude([Event::RELATION_ADDRESS, Event::RELATION_CATEGORY]);
        $this->queryService->applyListFilters($query, (array)($settings['flexform'] ?? []), $settings);
        if ($searchRequest instanceof SearchRequest) {
            $this->queryService->applyUserFilters($query, $searchRequest);
        } else {
            // The NAPI delivers running events, too
            $query->setTimeFromStart(new \DateTime('today'));
        }

        $query = $this->dispatchQueryEvent($query, $settings, ModifyMapEventQueryEvent::CONTEXT_LIST, $searchRequest);
        return $this->fetch($query, $allItems);
    }

    /**
     * @param iterable<Event> $events
     */
    public function createMarkers(iterable $events, array $settings): array
    {
        $markers = [];
        foreach ($events as $event) {
            if ($event instanceof Event && ($marker = $this->markerService->createMarker($event, $settings)) !== null) {
                $markers[] = $marker;
            }
        }
        return $markers;
    }

    /**
     * Labels of the event type facets, e.g. ['event_types' => ['music' => 'Konzerte (12)']]
     */
    public function resolveFacets(array $facets, array $settings): array
    {
        $labels = (array)($settings['mapping']['eventType'] ?? []);
        $resolved = [];
        foreach ($facets as $facetType => $values) {
            // Aliases (e.g. default, event, base) are merged into one option
            $counts = [];
            foreach ((array)$values as $value) {
                $id = EventMarkerService::normalizeEventType((string)key($value));
                $counts[$id] = ($counts[$id] ?? 0) + (int)current($value);
            }
            foreach ($counts as $id => $count) {
                $resolved[$facetType][$id] = sprintf('%s (%d)', $labels[$id] ?? $id, $count);
            }
            if (isset($resolved[$facetType])) {
                asort($resolved[$facetType]);
            }
        }
        return $resolved;
    }

    private function dispatchQueryEvent(EventQuery $query, array $settings, string $context, ?SearchRequest $searchRequest = null): EventQuery
    {
        /** @var ModifyMapEventQueryEvent $event */
        $event = $this->eventDispatcher->dispatch(new ModifyMapEventQueryEvent($query, $settings, $context, $searchRequest));
        return $event->getEventQuery();
    }

    private function fetch(EventQuery $query, bool $allItems): array
    {
        $repository = $this->ndkApiFactory->getApi()->factory(EventRepository::class);
        if ($allItems) {
            $items = ApiService::getAllItems($repository, $query);
            return ['limitExceeded' => false, 'items' => $items, 'pageCount' => 1, 'facets' => []];
        }

        /** @var Result $result */
        $result = $repository->get($query);
        return [
            'limitExceeded' => $result->getPageCount() > 1,
            'items' => $result,
            'pageCount' => (int)$result->getPageCount(),
            'facets' => (array)($result->getFacets() ?: []),
        ];
    }
}
