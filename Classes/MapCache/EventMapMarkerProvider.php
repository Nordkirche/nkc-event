<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\MapCache;

use Nordkirche\NkcBase\MapCache\MapMarkerCache;
use Nordkirche\NkcBase\MapCache\MapMarkerProviderInterface;
use Nordkirche\NkcEvent\Service\EventMapItemService;
use Nordkirche\NkcEvent\Service\EventSettings;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Markers of the event maps for the nkc_base map marker cache.
 * The list plugin is cached without the search of visitors only.
 */
final readonly class EventMapMarkerProvider implements MapMarkerProviderInterface
{
    public function __construct(
        private EventMapItemService $mapItemService,
        private EventSettings $eventSettings,
    ) {}

    public function getSupportedPluginTypes(): array
    {
        return ['nkcevent_map', 'nkcevent_maplist', 'nkcevent_list'];
    }

    public function buildMarkers(array $contentRow, array $settings, Site $site): array
    {
        $settings = array_replace_recursive($this->eventSettings->forMap($site), $settings);
        $result = MapMarkerCache::getPluginType($contentRow) === 'nkcevent_list'
            ? $this->mapItemService->getListItems($settings, null, true)
            : $this->mapItemService->getMapItems($settings, true);
        return $this->mapItemService->createMarkers($result['items'], $settings);
    }
}
