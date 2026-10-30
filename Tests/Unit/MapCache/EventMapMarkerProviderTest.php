<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Tests\Unit\MapCache;

use Nordkirche\NkcBase\MapCache\Exception\FrontendRequiredException;
use Nordkirche\NkcBase\Napi\Traffic\TrafficStats;
use Nordkirche\NkcBase\Service\NapiSettings;
use Nordkirche\NkcBase\Service\NdkApiFactory;
use Nordkirche\NkcEvent\MapCache\EventMapMarkerProvider;
use Nordkirche\NkcEvent\Service\EventMapItemService;
use Nordkirche\NkcEvent\Service\EventQueryService;
use Nordkirche\NkcEvent\Service\EventSettings;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class EventMapMarkerProviderTest extends UnitTestCase
{
    #[Test]
    public function warmUpSkipsPluginsThatNeedTheSiteInstitution(): void
    {
        $mapItemService = $this->createMock(EventMapItemService::class);
        $mapItemService->expects(self::never())->method('getListItems');

        $this->expectException(FrontendRequiredException::class);
        $this->createSubject($mapItemService)->buildMarkers(
            ['CType' => 'nkcevent_list'],
            ['backendContext' => 1, 'flexform' => ['restrictToSiteInstitution' => '1']],
            $this->createSite()
        );
    }

    #[Test]
    public function withTheSiteSettingTheWarmUpWorks(): void
    {
        $mapItemService = $this->createMock(EventMapItemService::class);
        $mapItemService->expects(self::once())->method('getListItems')
            ->with(self::callback(static fn(array $settings): bool => $settings['siteInstitutions'] === '2658'))
            ->willReturn(['limitExceeded' => false, 'items' => [], 'pageCount' => 0, 'facets' => []]);
        $mapItemService->method('createMarkers')->willReturn([]);

        $markers = $this->createSubject($mapItemService)->buildMarkers(
            ['CType' => 'nkcevent_list'],
            ['backendContext' => 1, 'flexform' => ['churchAssociation' => 'site']],
            $this->createSite('2658')
        );

        self::assertSame([], $markers);
    }

    private function createSubject(EventMapItemService $mapItemService): EventMapMarkerProvider
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);
        $ndkApiFactory = new NdkApiFactory(new NapiSettings($extensionConfiguration), new LogManager(), new TrafficStats(self::createStub(Registry::class)));
        return new EventMapMarkerProvider($mapItemService, new EventSettings(), new EventQueryService($ndkApiFactory));
    }

    private function createSite(string $siteInstitutions = ''): Site
    {
        return new Site('main', 1, [], SiteSettings::createFromSettingsTree([
            'plugin' => ['tx_nkcevent' => ['settings' => ['siteInstitutions' => $siteInstitutions]]],
        ]));
    }
}
