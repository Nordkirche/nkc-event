<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Service;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Settings for contexts without TypoScript (map marker cache warm-up on the CLI,
 * info windows of map markers). The keys are the same as the TypoScript constants.
 */
final class EventSettings
{
    public const DEFAULTS = [
        'plugin.tx_nkcevent.settings.pidEventSingle' => 0,
        'plugin.tx_nkcevent.settings.pidEventList' => 0,
        'plugin.tx_nkcevent.settings.eventShortLink' => '/event/?nkce=',
        'plugin.tx_nkcevent.settings.eventIconName' => 'EXT:nkc_base/Resources/Public/MapIcons/event_%s.svg',
        'plugin.tx_nkcevent.settings.digitalEventCategoryId' => '606,647',
        'plugin.tx_nkcevent.settings.hybridEventCategoryId' => '646',
        'plugin.tx_nkcevent.settings.siteInstitutions' => '',
        'plugin.tx_nkcevent_main.view.templateRootPath' => 'EXT:nkc_event/Resources/Private/Templates/',
        'plugin.tx_nkcevent_main.view.partialRootPath' => 'EXT:nkc_event/Resources/Private/Partials/',
        'plugin.tx_nkcevent_main.view.layoutRootPath' => 'EXT:nkc_event/Resources/Private/Layouts/',
    ];

    /**
     * Icons of the event types (NAPI event type => icon name), see TypoScript settings.mapping.eventIcon
     */
    public const EVENT_ICON_MAPPING = [
        'church_service' => 'gottesdienst',
        'committee' => 'gremien',
        'default' => 'gemeindeleben',
        'edu' => 'bildung',
        'event' => 'gemeindeleben',
        'leisure' => 'freizeit',
        'music' => 'konzerte',
        'spirituality' => 'spiritualitaet',
        'base' => 'gemeindeleben',
        'digital-church_service' => 'digital-gottesdienst',
        'digital-committee' => 'digital-gremien',
        'digital-default' => 'digital-gemeindeleben',
        'digital-edu' => 'digital-bildung',
        'digital-event' => 'digital-gemeindeleben',
        'digital-leisure' => 'digital-freizeit',
        'digital-music' => 'digital-konzerte',
        'digital-spirituality' => 'digital-spiritualitaet',
        'digital-base' => 'digital-gemeindeleben',
        'hybrid-church_service' => 'hybrid-gottesdienst',
        'hybrid-committee' => 'hybrid-gremien',
        'hybrid-default' => 'hybrid-gemeindeleben',
        'hybrid-edu' => 'hybrid-bildung',
        'hybrid-event' => 'hybrid-gemeindeleben',
        'hybrid-leisure' => 'hybrid-freizeit',
        'hybrid-music' => 'hybrid-konzerte',
        'hybrid-spirituality' => 'hybrid-spiritualitaet',
        'hybrid-base' => 'hybrid-gemeindeleben',
    ];

    public function get(Site $site, string $key): mixed
    {
        return $site->getSettings()->get($key, self::DEFAULTS[$key] ?? null);
    }

    /**
     * Settings like plugin.tx_nkcevent_map.settings, for the map marker provider
     */
    public function forMap(Site $site): array
    {
        return [
            'eventIconName' => (string)$this->get($site, 'plugin.tx_nkcevent.settings.eventIconName'),
            'digitalEventCategoryId' => (string)$this->get($site, 'plugin.tx_nkcevent.settings.digitalEventCategoryId'),
            'hybridEventCategoryId' => (string)$this->get($site, 'plugin.tx_nkcevent.settings.hybridEventCategoryId'),
            'siteInstitutions' => (string)$this->get($site, 'plugin.tx_nkcevent.settings.siteInstitutions'),
            'mapping' => ['eventIcon' => self::EVENT_ICON_MAPPING],
        ];
    }

    /**
     * Settings for the info windows of events ("e")
     */
    public function forMapInfo(Site $site): array
    {
        return $this->forMap($site) + [
            'shortLink' => (string)$this->get($site, 'plugin.tx_nkcevent.settings.eventShortLink'),
            'flexform' => [
                'pidEventSingle' => (int)$this->get($site, 'plugin.tx_nkcevent.settings.pidEventSingle'),
            ],
        ];
    }

    /**
     * @return array{templateRootPaths: string[], partialRootPaths: string[], layoutRootPaths: string[]}
     */
    public function getViewPaths(Site $site): array
    {
        $configured = fn(string $type): string => (string)$this->get($site, 'plugin.tx_nkcevent_main.view.' . $type . 'RootPath');
        $unique = static fn(array $paths): array => array_values(array_unique(array_filter($paths)));

        return [
            'templateRootPaths' => $unique(['EXT:nkc_event/Resources/Private/Templates/', $configured('template')]),
            'partialRootPaths' => $unique(['EXT:nkc_base/Resources/Private/Partials/', 'EXT:nkc_event/Resources/Private/Partials/', $configured('partial')]),
            'layoutRootPaths' => $unique(['EXT:nkc_event/Resources/Private/Layouts/', $configured('layout')]),
        ];
    }
}
