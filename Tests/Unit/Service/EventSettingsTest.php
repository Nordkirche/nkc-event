<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Tests\Unit\Service;

use Nordkirche\NkcEvent\Service\EventSettings;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class EventSettingsTest extends UnitTestCase
{
    #[Test]
    public function defaultsAreUsedWithoutSiteSettings(): void
    {
        $settings = new EventSettings();
        $info = $settings->forMapInfo($this->createSite([]));

        self::assertSame('/event/?nkce=', $info['shortLink']);
        self::assertSame(0, $info['flexform']['pidEventSingle']);
        self::assertSame('606,647', $info['digitalEventCategoryId']);
        self::assertSame('646', $info['hybridEventCategoryId']);
        self::assertSame(EventSettings::EVENT_ICON_MAPPING, $info['mapping']['eventIcon']);
    }

    #[Test]
    public function siteSettingsOverrideTheDefaults(): void
    {
        $info = (new EventSettings())->forMapInfo($this->createSite([
            'pidEventSingle' => '11',
            'eventShortLink' => '/veranstaltung-kurzlink?nkce=',
            'digitalEventCategoryId' => '700',
        ]));

        self::assertSame(11, $info['flexform']['pidEventSingle']);
        self::assertSame('/veranstaltung-kurzlink?nkce=', $info['shortLink']);
        self::assertSame('700', $info['digitalEventCategoryId']);
    }

    #[Test]
    public function everyEventTypeHasDigitalAndHybridIcons(): void
    {
        $mapping = EventSettings::EVENT_ICON_MAPPING;
        foreach (array_filter(array_keys($mapping), static fn(string $type): bool => !str_contains($type, '-')) as $type) {
            self::assertSame('digital-' . $mapping[$type], $mapping['digital-' . $type] ?? null, 'digital-' . $type);
            self::assertSame('hybrid-' . $mapping[$type], $mapping['hybrid-' . $type] ?? null, 'hybrid-' . $type);
        }
    }

    private function createSite(array $settings): Site
    {
        $map = [];
        foreach ($settings as $key => $value) {
            $map['plugin.tx_nkcevent.settings.' . $key] = $value;
        }
        return new Site('main', 1, ['base' => 'https://example.org/'], SiteSettings::create(new Settings($map)));
    }
}
