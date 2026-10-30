<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Tests\Unit\Service;

use Nordkirche\Ndk\Domain\Query\EventQuery;
use Nordkirche\NkcBase\Napi\Traffic\TrafficStats;
use Nordkirche\NkcBase\Service\NapiSettings;
use Nordkirche\NkcBase\Service\NdkApiFactory;
use Nordkirche\NkcEvent\Domain\Dto\SearchRequest;
use Nordkirche\NkcEvent\Service\CategoryTree;
use Nordkirche\NkcEvent\Service\EventQueryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Registry;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class EventQueryServiceTest extends UnitTestCase
{
    private EventQueryService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        // The NAPI is only needed to resolve event locations, which these tests don't use
        $this->subject = new EventQueryService($this->createNdkApiFactory());
    }

    private function createNdkApiFactory(): NdkApiFactory
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);
        return new NdkApiFactory(
            new NapiSettings($extensionConfiguration),
            new LogManager(),
            new TrafficStats(self::createStub(Registry::class))
        );
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

    #[Test]
    public function siteInstitutionReplacesTheInstitutionsOfThePlugin(): void
    {
        $settings = ['siteInstitutions' => '2658, napi:resource/institutions/77'];

        $restricted = new EventQuery();
        $this->subject->applyListFilters($restricted, ['institutionCollection' => '12', 'restrictToSiteInstitution' => '1'], $settings);
        $withoutSite = new EventQuery();
        $this->subject->applyListFilters($withoutSite, ['institutionCollection' => '12', 'restrictToSiteInstitution' => '1']);
        $notRestricted = new EventQuery();
        $this->subject->applyListFilters($notRestricted, ['institutionCollection' => '12'], $settings);

        self::assertSame(['2658', '77'], $restricted->getOrganizers());
        self::assertSame(['12'], $withoutSite->getOrganizers(), 'Without an institution of the site the plugin selection applies');
        self::assertSame(['12'], $notRestricted->getOrganizers());
    }

    #[Test]
    public function subInstitutionsUseTheNapiFilterWithParents(): void
    {
        $list = new EventQuery();
        $this->subject->applyListFilters($list, ['restrictToSiteInstitution' => '1', 'includeChildInstitutions' => '1'], ['siteInstitutions' => '2658']);
        $map = new EventQuery();
        $this->subject->applyMapFilters($map, ['institutionCollection' => '5', 'includeChildInstitutions' => '1']);

        self::assertSame(['2658'], $list->getOrganizersWithParents());
        self::assertSame([], $list->getOrganizers());
        self::assertSame(['5'], $map->getOrganizersWithParents());
        self::assertSame('2658', $list->returnUrlParameters()['filter']['organizers_with_parents'] ?? null);
    }

    #[Test]
    public function citiesAndZipCodesOfThePlugin(): void
    {
        $query = new EventQuery();
        $this->subject->applyListFilters($query, ['cities' => 'Kappeln, 24376, Schleswig', 'zipCodes' => '24837']);

        self::assertSame(['Kappeln', 'Schleswig'], $query->getCities());
        self::assertSame(['24837'], $query->getZipCodes(), 'The zip code field wins over numbers in the cities');
    }

    #[Test]
    public function subCategoriesAreAddedOnRequest(): void
    {
        $categoryTree = self::createStub(CategoryTree::class);
        $categoryTree->method('withSubCategories')->willReturn(['218', '221', '222']);
        $subject = new EventQueryService($this->createNdkApiFactory(), $categoryTree);

        $with = new EventQuery();
        $subject->applyListFilters($with, ['categories' => '218', 'includeSubCategories' => '1']);
        $without = new EventQuery();
        $subject->applyListFilters($without, ['categories' => '218']);

        self::assertSame(['218', '221', '222'], $with->getCategoriesOr());
        self::assertSame(['218'], $without->getCategoriesOr());
    }

    #[Test]
    public function churchAssociationOfThePluginOrOfTheSite(): void
    {
        $subject = new class ($this->createNdkApiFactory()) extends EventQueryService {
            public array $siteAssociations = [];

            protected function getChurchAssociationsOfSite(array $settings): array
            {
                return $this->siteAssociations;
            }
        };

        $fixed = new EventQuery();
        $subject->applyListFilters($fixed, ['churchAssociation' => '546']);
        $subject->siteAssociations = ['547'];
        $ofSite = new EventQuery();
        $subject->applyListFilters($ofSite, ['churchAssociation' => 'site'], ['siteInstitutions' => '1']);
        $subject->siteAssociations = [];
        $unknown = new EventQuery();
        $subject->applyListFilters($unknown, ['churchAssociation' => 'site']);

        self::assertSame(['546'], $fixed->getChurchAssociations());
        self::assertSame(['547'], $ofSite->getChurchAssociations());
        self::assertSame(['0'], $unknown->getChurchAssociations(), 'Like nk_event: no events without a church association of the site');
    }

    #[Test]
    public function visitorsCanOnlyNarrowTheEventTypesOfThePlugin(): void
    {
        $query = new EventQuery();
        $query->setEventTypes(['music', 'church_service']);
        $this->subject->applyUserFilters($query, $this->searchRequest(['eventType' => 'leisure']));
        self::assertSame(['music', 'church_service'], $query->getEventTypes());

        $this->subject->applyUserFilters($query, $this->searchRequest(['eventType' => 'music']));
        self::assertSame(['music'], $query->getEventTypes());

        $open = new EventQuery();
        $this->subject->applyUserFilters($open, $this->searchRequest(['eventType' => 'Leisure<script>']));
        self::assertSame(['leisurescript'], $open->getEventTypes(), 'Only the characters of event types');
    }

    #[Test]
    public function siteInstitutionsAreIds(): void
    {
        self::assertSame(['1', '22'], $this->subject->getSiteInstitutions(['siteInstitutions' => 'napi:resource/institutions/1, 22, , 1']));
        self::assertSame([], $this->subject->getSiteInstitutions(['siteInstitutions' => '{rootline:field}']), 'Unresolved placeholders are no institution');
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
