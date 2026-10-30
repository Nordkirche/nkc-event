<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Tests\Unit\Service;

use Nordkirche\Ndk\Domain\Query\EventQuery;
use Nordkirche\NkcBase\Napi\Traffic\TrafficStats;
use Nordkirche\NkcBase\Service\NapiSettings;
use Nordkirche\NkcBase\Service\NdkApiFactory;
use Nordkirche\NkcEvent\Domain\Dto\SearchRequest;
use Nordkirche\NkcEvent\Event\ModifyMapEventQueryEvent;
use Nordkirche\NkcEvent\Service\EventMapItemService;
use Nordkirche\NkcEvent\Service\EventMarkerService;
use Nordkirche\NkcEvent\Service\EventQueryService;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Registry;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The listener stops the request before the NAPI is called, so no network is needed.
 */
final class EventMapItemServiceTest extends UnitTestCase
{
    private ?ModifyMapEventQueryEvent $dispatched = null;

    #[Test]
    public function queryOfTheMapPluginsCanBeModified(): void
    {
        $replacement = new EventQuery();
        $subject = $this->createSubject(static function (ModifyMapEventQueryEvent $event) use ($replacement): void {
            $event->setEventQuery($replacement);
        });

        $this->callUntilNapi(static fn() => $subject->getMapItems(['flexform' => ['eventTypes' => 'music']]));

        self::assertSame(ModifyMapEventQueryEvent::CONTEXT_MAP, $this->dispatched?->getContext());
        self::assertSame(['flexform' => ['eventTypes' => 'music']], $this->dispatched->getSettings());
        self::assertNull($this->dispatched->getSearchRequest());
        self::assertSame($replacement, $this->dispatched->getEventQuery(), 'The query of the listener is used');
    }

    #[Test]
    public function queryOfTheListMapContainsTheSearchOfTheVisitor(): void
    {
        $searchRequest = new SearchRequest();
        $searchRequest->setSearch('Orgel');
        $subject = $this->createSubject();

        $this->callUntilNapi(static fn() => $subject->getListItems([], $searchRequest));

        self::assertSame(ModifyMapEventQueryEvent::CONTEXT_LIST, $this->dispatched?->getContext());
        self::assertSame($searchRequest, $this->dispatched->getSearchRequest());
        self::assertSame('Orgel', $this->dispatched->getEventQuery()->getQuery());
    }

    private function createSubject(?\Closure $listener = null): EventMapItemService
    {
        $dispatcher = self::createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $event) use ($listener): never {
            self::assertInstanceOf(ModifyMapEventQueryEvent::class, $event);
            $listener?->__invoke($event);
            $this->dispatched = $event;
            throw new \LogicException('stop before NAPI', 1760890000);
        });

        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);
        $ndkApiFactory = new NdkApiFactory(
            new NapiSettings($extensionConfiguration),
            new LogManager(),
            new TrafficStats(self::createStub(Registry::class))
        );

        return new EventMapItemService(
            $ndkApiFactory,
            new EventQueryService($ndkApiFactory),
            self::createStub(EventMarkerService::class),
            $dispatcher
        );
    }

    private function callUntilNapi(\Closure $call): void
    {
        try {
            $call();
            self::fail('The event was not dispatched');
        } catch (\LogicException $exception) {
            self::assertSame(1760890000, $exception->getCode());
        }
    }
}
