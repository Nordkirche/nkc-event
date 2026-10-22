<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Event;

use Nordkirche\Ndk\Domain\Query\EventQuery;
use Nordkirche\NkcEvent\Domain\Dto\SearchRequest;

/**
 * Query of the map markers, before it is sent to the NAPI
 *
 * Dispatched for the plugins "Karte" and "Karte und Liste" (context "map") and the map
 * of the list plugin (context "list"), also when the marker cache is built on the
 * command line. To keep list and map in sync, listen to this event and to
 * ModifyEventQueryEvent.
 */
final class ModifyMapEventQueryEvent
{
    public const CONTEXT_MAP = 'map';
    public const CONTEXT_LIST = 'list';

    public function __construct(
        private EventQuery $eventQuery,
        private readonly array $settings,
        private readonly string $context,
        private readonly ?SearchRequest $searchRequest = null
    ) {}

    public function getEventQuery(): EventQuery
    {
        return $this->eventQuery;
    }

    public function setEventQuery(EventQuery $eventQuery): void
    {
        $this->eventQuery = $eventQuery;
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * "map" or "list"
     */
    public function getContext(): string
    {
        return $this->context;
    }

    /**
     * Search of the visitor (only for the map of the list plugin)
     */
    public function getSearchRequest(): ?SearchRequest
    {
        return $this->searchRequest;
    }
}
