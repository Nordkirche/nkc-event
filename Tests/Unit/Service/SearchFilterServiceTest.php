<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Tests\Unit\Service;

use Nordkirche\Ndk\Domain\Model\Address;
use Nordkirche\Ndk\Domain\Model\Institution\Institution;
use Nordkirche\NkcBase\Domain\Repository\CategoryRepository;
use Nordkirche\NkcBase\Napi\Traffic\TrafficStats;
use Nordkirche\NkcBase\Service\NapiSettings;
use Nordkirche\NkcBase\Service\NdkApiFactory;
use Nordkirche\NkcEvent\Domain\Model\FilterDate;
use Nordkirche\NkcEvent\Domain\Repository\FilterDateRepository;
use Nordkirche\NkcEvent\Event\ModifySearchFilterValuesEvent;
use Nordkirche\NkcEvent\Service\SearchFilterService;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Extbase\Domain\Model\Category;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SearchFilterServiceTest extends UnitTestCase
{
    private const SETTINGS = [
        'filter' => [
            'pidDateFilter' => '42',
            'cityCollection' => 'Kiel, Lübeck',
            'institutionCollection' => 'napi://resource/institution/1, napi://resource/institution/2',
            'categoryCollection' => '7,8',
        ],
    ];

    private ?ModifySearchFilterValuesEvent $dispatched = null;

    /**
     * @var list<string>
     */
    private array $requestedInstitutions = [];

    #[Test]
    public function filterValuesAreCollected(): void
    {
        $filter = $this->createSubject()->getFilterValues(self::SETTINGS, self::createStub(RequestInterface::class));

        self::assertCount(1, $filter['dates'] ?? []);
        self::assertSame('Ostern', $filter['dates'][0]->getName());

        self::assertSame(['1', '2'], $this->requestedInstitutions);
        self::assertSame([
            ['name' => 'Kiel', 'locations' => [['name' => 'Ev.-Luth. Kirchengemeinde St. Nikolai', 'id' => '1']]],
            ['name' => 'Lübeck', 'locations' => [['name' => 'Dom', 'id' => '2']]],
        ], $filter['cities'] ?? null, 'Official name first, otherwise the name');

        self::assertSame([['uid' => 7, 'label' => 'Kirchenmusik']], $filter['categories'] ?? null, 'Unknown categories are skipped');
    }

    #[Test]
    public function listenersCanChangeTheFilterValues(): void
    {
        $request = self::createStub(RequestInterface::class);
        $subject = $this->createSubject(static function (ModifySearchFilterValuesEvent $event): void {
            $filter = $event->getFilterValues();
            unset($filter['cities']);
            $filter['categories'][] = ['uid' => 99, 'label' => 'Familie'];
            $event->setFilterValues($filter);
        });

        $filter = $subject->getFilterValues(self::SETTINGS, $request);

        self::assertArrayNotHasKey('cities', $filter);
        self::assertSame([7, 99], array_column($filter['categories'] ?? [], 'uid'));
        self::assertSame(self::SETTINGS, $this->dispatched?->getSettings());
        self::assertSame($request, $this->dispatched->getRequest());
    }

    #[Test]
    public function withoutCitiesNoInstitutionsAreRequested(): void
    {
        $settings = self::SETTINGS;
        unset($settings['filter']['cityCollection']);

        $filter = $this->createSubject()->getFilterValues($settings, self::createStub(RequestInterface::class));

        self::assertArrayNotHasKey('cities', $filter);
        self::assertSame([], $this->requestedInstitutions);
    }

    private function createSubject(?\Closure $listener = null): SearchFilterService
    {
        $easter = new FilterDate();
        $easter->setName('Ostern');
        $dates = self::createStub(QueryResultInterface::class);
        $dates->method('toArray')->willReturn([$easter]);
        $filterDateRepository = self::createStub(FilterDateRepository::class);
        $filterDateRepository->method('findDatesByStoragePid')->willReturnCallback(
            static fn(int $pid): QueryResultInterface => $pid === 42 ? $dates : self::createStub(QueryResultInterface::class)
        );

        $music = new Category();
        $music->_setProperty('uid', 7);
        $music->setTitle('Kirchenmusik');
        $categoryRepository = self::createStub(CategoryRepository::class);
        $categoryRepository->method('findByUid')->willReturnCallback(static fn(int $uid): ?Category => $uid === 7 ? $music : null);

        $dispatcher = self::createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $event) use ($listener): object {
            self::assertInstanceOf(ModifySearchFilterValuesEvent::class, $event);
            $listener?->__invoke($event);
            $this->dispatched = $event;
            return $event;
        });

        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);
        $ndkApiFactory = new NdkApiFactory(
            new NapiSettings($extensionConfiguration),
            new LogManager(),
            new TrafficStats(self::createStub(Registry::class))
        );

        $requested = &$this->requestedInstitutions;
        $institutions = [
            $this->createInstitution('1', 'St. Nikolai', 'Ev.-Luth. Kirchengemeinde St. Nikolai', 'Kiel'),
            $this->createInstitution('2', 'Dom', '', 'Lübeck'),
            $this->createInstitution('3', 'Rathaus', '', 'Hamburg'),
        ];

        // The NAPI request for the institutions is replaced
        return new class ($filterDateRepository, $categoryRepository, $ndkApiFactory, $dispatcher, $institutions, $requested) extends SearchFilterService {
            /**
             * @param list<Institution> $institutions
             * @param list<string> $requested
             */
            public function __construct(
                FilterDateRepository $filterDateRepository,
                CategoryRepository $categoryRepository,
                NdkApiFactory $ndkApiFactory,
                EventDispatcherInterface $eventDispatcher,
                private readonly array $institutions,
                private array &$requested,
            ) {
                parent::__construct($filterDateRepository, $categoryRepository, $ndkApiFactory, $eventDispatcher);
            }

            protected function findInstitutions(array $ids): iterable
            {
                $this->requested = $ids;
                return $this->institutions;
            }
        };
    }

    private function createInstitution(string $id, string $name, string $officialName, string $city): Institution
    {
        $address = new Address();
        $address->setCity($city);
        $institution = new Institution();
        $institution->setId($id);
        $institution->setName($name);
        $institution->setOfficialName($officialName);
        $institution->setAddress($address);
        return $institution;
    }
}
