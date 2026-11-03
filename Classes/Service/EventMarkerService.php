<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Service;

use Nordkirche\Ndk\Domain\Model\Event\Event;
use Nordkirche\NkcBase\MapCache\GeoPosition;
use Nordkirche\NkcBase\Utility\PublicResource;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Map markers of events
 *
 * Marker: {object: "e", id, title, lat, lon, info, type, icon}. Without "info"
 * the map loads the info window via the marker info middleware.
 */
class EventMarkerService
{
    private const FALLBACK_ICON = 'gemeindeleben';

    /**
     * Event types sharing the label "Gemeindeleben": one filter option and one marker type for all
     */
    public const EVENT_TYPE_ALIASES = ['' => 'event', 'default' => 'event', 'base' => 'event'];

    /**
     * @param array $settings eventIconName, mapping.eventIcon, digitalEventCategoryId, hybridEventCategoryId
     */
    public function createMarker(Event $event, array $settings, string $info = ''): ?array
    {
        $position = GeoPosition::fromAddress($event->getAddress());
        if ($position === null) {
            return null;
        }

        $mappingType = $event->getEventType() ?: 'default';
        $prefix = match (true) {
            $this->isHybrid($event, $settings) => 'hybrid-',
            $this->isDigital($event, $settings) => 'digital-',
            default => '',
        };

        return [
            'object' => 'e',
            'id' => $event->getId(),
            'title' => (string)$event->getTitle(),
            'lat' => $position[0],
            'lon' => $position[1],
            'info' => $info,
            'type' => $prefix . 'event-' . self::normalizeEventType($mappingType),
            'icon' => $this->getIcon($prefix . $mappingType, $settings),
        ];
    }

    public static function normalizeEventType(string $type): string
    {
        return self::EVENT_TYPE_ALIASES[$type] ?? $type;
    }

    public function isDigital(Event $event, array $settings): bool
    {
        return $this->hasCategory($event, (string)($settings['digitalEventCategoryId'] ?? ''));
    }

    public function isHybrid(Event $event, array $settings): bool
    {
        return $this->hasCategory($event, (string)($settings['hybridEventCategoryId'] ?? ''));
    }

    public function getIcon(string $mappingType, array $settings): string
    {
        $pattern = (string)($settings['eventIconName'] ?? '') ?: EventSettings::DEFAULTS['plugin.tx_nkcevent.settings.eventIconName'];
        $mapping = (array)($settings['mapping']['eventIcon'] ?? EventSettings::EVENT_ICON_MAPPING);
        $icon = sprintf($pattern, $mapping[$mappingType] ?? self::FALLBACK_ICON);
        if (!PublicResource::exists($icon)) {
            $icon = sprintf($pattern, self::FALLBACK_ICON);
        }
        return PublicResource::uri($icon);
    }

    private function hasCategory(Event $event, string $categoryIds): bool
    {
        $ids = GeneralUtility::intExplode(',', $categoryIds, true);
        if ($ids === []) {
            return false;
        }
        foreach ($event->getCategories() ?: [] as $category) {
            if (in_array((int)$category->getId(), $ids, true)) {
                return true;
            }
        }
        return false;
    }
}
