<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Tests\Unit\Service;

use Nordkirche\Ndk\Domain\Query\EventQuery;
use Nordkirche\NkcBase\Napi\Traffic\TrafficStats;
use Nordkirche\NkcBase\Service\NapiSettings;
use Nordkirche\NkcBase\Service\NdkApiFactory;
use Nordkirche\NkcEvent\Domain\Dto\SearchRequest;
use Nordkirche\NkcEvent\Service\EventQueryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class EventQueryServiceTest extends UnitTestCase
{
    private EventQueryService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        // The NAPI is only needed to resolve event locations, which these tests don't use
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);
        $this->subject = new EventQueryService(new NdkApiFactory(
            new NapiSettings($extensionConfiguration),
            new LogManager(),
            new TrafficStats(self::createStub(FrontendInterface::class))
        ));
    }

    #[Test]
    public function listFiltersOfThePlugin(): void
    {
        $query = new EventQuery();
        $this->subject->applyListFilters($query, [
            'institutionCollection' => '12,13',
            'targetGroupCollection' => '4',
            'eventTypes' => 'music,edu',
            'categories' => '606,647',
            'categoryOperator' => 'AND',
        ]);

        self::assertSame(['12', '13'], $query->getOrganizers());
        self::assertSame(['4'], $query->getTargetGroups());
        self::assertSame(['music', 'edu'], $query->getEventTypes());
        self::assertSame(['606', '647'], $query->getCategoriesAnd());
        self::assertSame([], $query->getCategoriesOr());
    }

    #[Test]
    public function categoriesAreCombinedWithOrByDefault(): void
    {
        $query = new EventQuery();
        $this->subject->applyListFilters($query, ['categories' => '606,647']);

        self::assertSame(['606', '647'], $query->getCategoriesOr());
        self::assertSame([], $query->getCategoriesAnd());
    }

    public static function flexformDateDataProvider(): \Generator
    {
        $timestamp = (new \DateTime('2026-11-01 00:00:00'))->getTimestamp();
        yield 'timestamp (TYPO3 v13 datetime field)' => [$timestamp, '2026-11-01'];
        yield 'timestamp as string' => [(string)$timestamp, '2026-11-01'];
        yield 'date string' => ['2026-11-01', '2026-11-01'];
        yield 'date time string' => ['2026-11-01T15:30:00+01:00', '2026-11-01'];
    }

    #[Test]
    #[DataProvider('flexformDateDataProvider')]
    public function flexformDatesAreAcceptedInAllFormats(mixed $value, string $expectedDay): void
    {
        $query = new EventQuery();
        $this->subject->applyListFilters($query, ['dateFrom' => $value, 'dateTo' => $value]);

        self::assertSame($expectedDay . ' 00:00', $query->getTimeFrom()->format('Y-m-d H:i'));
        self::assertSame($expectedDay . ' 00:00', $query->getTimeTo()->format('Y-m-d H:i'));
    }

    public static function emptyDateDataProvider(): \Generator
    {
        yield 'empty string' => [''];
        yield 'zero' => [0];
        yield 'zero string' => ['0'];
        yield 'null' => [null];
    }

    #[Test]
    #[DataProvider('emptyDateDataProvider')]
    public function emptyFlexformDatesAreIgnored(mixed $value): void
    {
        $query = new EventQuery();
        $this->subject->applyListFilters($query, ['dateFrom' => $value, 'dateTo' => $value]);

        self::assertNull($this->read($query, 'timeFrom'));
        self::assertNull($this->read($query, 'timeTo'));
    }

    #[Test]
    public function numDaysLimitsListAndMapToTheSameDay(): void
    {
        $list = new EventQuery();
        $this->subject->applyListFilters($list, ['numDays' => '1']);
        $map = new EventQuery();
        $this->subject->applyMapFilters($map, ['numDays' => '1']);

        $tomorrow = (new \DateTime('tomorrow'))->format('Y-m-d H:i');
        self::assertSame($tomorrow, $list->getTimeTo()->format('Y-m-d H:i'));
        self::assertSame($tomorrow, $map->getTimeToEnd()->format('Y-m-d H:i'));
    }

    #[Test]
    public function mapShowsNoPastEventsByDefault(): void
    {
        $query = new EventQuery();
        $this->subject->applyMapFilters($query, ['eventCollection' => '1,2', 'cities' => 'Kiel', 'zipCodes' => '24103']);

        self::assertSame((new \DateTime('today'))->format('Y-m-d H:i'), $query->getTimeFromStart()->format('Y-m-d H:i'));
        self::assertSame(['1', '2'], $query->getEvents());
        self::assertSame(['Kiel'], $query->getCities());
        self::assertSame(['24103'], $query->getZipCodes());
    }

    #[Test]
    public function userSearchNeedsAtLeastThreeCharacters(): void
    {
        $short = new EventQuery();
        $this->subject->applyUserFilters($short, $this->searchRequest(['search' => 'ab']));
        $long = new EventQuery();
        $this->subject->applyUserFilters($long, $this->searchRequest(['search' => 'Orgel']));

        self::assertSame('', $short->getQuery());
        self::assertSame('Orgel', $long->getQuery());
    }

    #[Test]
    public function userSearchStartsTodayByDefault(): void
    {
        $query = new EventQuery();
        $this->subject->applyUserFilters($query, $this->searchRequest([]));

        self::assertSame((new \DateTime('today'))->format('Y-m-d'), $query->getTimeFromStart()->format('Y-m-d'));
        self::assertNull($this->read($query, 'timeToEnd'));
    }

    #[Test]
    public function zipCodesAndCitiesAreSeparated(): void
    {
        $query = new EventQuery();
        $this->subject->applyUserFilters($query, $this->searchRequest(['city' => '24103, Kiel,Lübeck']));

        self::assertSame(['24103'], $query->getZipCodes());
        self::assertSame(['Kiel', 'Lübeck'], $query->getCities());
    }

    #[Test]
    public function visitorsCanOnlyNarrowTheOrganizersOfThePlugin(): void
    {
        $allowed = new EventQuery();
        $allowed->setOrganizers([12, 13]);
        $this->subject->applyUserFilters($allowed, $this->searchRequest(['organizer' => '13']));

        $other = new EventQuery();
        $other->setOrganizers([12, 13]);
        $this->subject->applyUserFilters($other, $this->searchRequest(['organizer' => '99']));

        $unrestricted = new EventQuery();
        $this->subject->applyUserFilters($unrestricted, $this->searchRequest(['organizer' => '99']));

        self::assertSame([13], $allowed->getOrganizers());
        self::assertSame([12, 13], $other->getOrganizers(), 'Organizers outside the plugin selection are ignored');
        self::assertSame([99], $unrestricted->getOrganizers());
    }

    #[Test]
    public function visitorsCanOnlyNarrowTheCategoriesOfThePlugin(): void
    {
        $query = new EventQuery();
        $query->setCategoriesOr([606, 647]);
        $this->subject->applyUserFilters($query, $this->searchRequest(['category' => '1']));

        self::assertSame([606, 647], $query->getCategoriesOr());

        $this->subject->applyUserFilters($query, $this->searchRequest(['category' => '647']));
        self::assertSame([647], $query->getCategoriesOr());
    }

    private function searchRequest(array $data): SearchRequest
    {
        $searchRequest = new SearchRequest();
        $searchRequest->decorate($data);
        return $searchRequest;
    }

    /**
     * Values that are not set yet (the getters of the NDK don't allow null)
     */
    private function read(EventQuery $query, string $property): mixed
    {
        return (fn(): mixed => $this->{$property})->call($query);
    }
}
