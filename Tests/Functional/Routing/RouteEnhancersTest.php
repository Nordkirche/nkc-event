<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Tests\Functional\Routing;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The route enhancers of the site set (route-enhancers.yaml)
 */
final class RouteEnhancersTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['nordkirche/nkc-base', 'nordkirche/nk-google-map', 'nordkirche/nkc-event'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Pages.csv');
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'http://localhost/',
            'dependencies' => ['nordkirche/nkc-event'],
            'languages' => [['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/']],
        ]);
    }

    #[Test]
    public function eventLinksAreReadable(): void
    {
        $router = $this->getSite()->getRouter();

        self::assertSame('/veranstaltung/orgelkonzert', $router->generateUri(11, ['tx_nkcevent_show' => ['uid' => '4711']])->getPath());
        self::assertSame(
            '/veranstaltung/orgelkonzert/ical',
            $router->generateUri(11, ['tx_nkcevent_show' => ['controller' => 'Event', 'action' => 'export', 'uid' => '4711']])->getPath()
        );
        self::assertSame('/veranstaltungen/seite/3', $router->generateUri(10, ['tx_nkcevent_list' => ['currentPage' => '3']])->getPath());
    }

    #[Test]
    public function theCalendarExportIsResolved(): void
    {
        $arguments = $this->resolve('http://localhost/veranstaltung/orgelkonzert/ical')->getArguments()['tx_nkcevent_show'] ?? [];

        self::assertEquals(['uid' => '4711', 'controller' => 'Event', 'action' => 'export'], $arguments);
    }

    #[Test]
    public function listPagesBelongToTheirOwnPlugin(): void
    {
        $arguments = $this->resolve('http://localhost/veranstaltungen/seite/2')->getArguments();

        self::assertSame(['tx_nkcevent_list'], array_keys($arguments), 'Not the map list');
        self::assertSame('2', $arguments['tx_nkcevent_list']['currentPage'] ?? null);

        $mapArguments = $this->resolve('http://localhost/veranstaltungskarte/seite/2')->getArguments();
        self::assertSame(['tx_nkcevent_maplist'], array_keys($mapArguments), 'Not the event list');
    }

    #[Test]
    public function unknownEventsAreNotFound(): void
    {
        $this->expectException(\TYPO3\CMS\Core\Routing\RouteNotFoundException::class);
        $this->resolve('http://localhost/veranstaltung/gibt-es-nicht');
    }

    private function getSite(): Site
    {
        $site = $this->get(SiteConfiguration::class)->getAllExistingSites(false)['main'] ?? null;
        self::assertInstanceOf(Site::class, $site);
        return $site;
    }

    private function resolve(string $uri): PageArguments
    {
        $request = new ServerRequest($uri);
        $siteRouteResult = $this->get(SiteMatcher::class)->matchRequest($request);
        $pageArguments = $this->getSite()->getRouter()->matchRequest($request, $siteRouteResult);
        self::assertInstanceOf(PageArguments::class, $pageArguments);
        return $pageArguments;
    }
}
