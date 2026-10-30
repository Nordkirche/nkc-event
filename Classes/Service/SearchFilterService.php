<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Service;

use Nordkirche\Ndk\Domain\Model\Address;
use Nordkirche\Ndk\Domain\Model\Institution\Institution;
use Nordkirche\Ndk\Domain\Query\InstitutionQuery;
use Nordkirche\Ndk\Domain\Repository\InstitutionRepository;
use Nordkirche\Ndk\Service\NapiService;
use Nordkirche\NkcBase\Domain\Repository\CategoryRepository;
use Nordkirche\NkcBase\Service\NdkApiFactory;
use Nordkirche\NkcEvent\Domain\Model\FilterDate;
use Nordkirche\NkcEvent\Domain\Repository\FilterDateRepository;
use Nordkirche\NkcEvent\Event\ModifySearchFilterValuesEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Domain\Model\Category;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Filter values of the event search: date presets, cities with their locations and categories
 */
class SearchFilterService
{
    public function __construct(
        private readonly FilterDateRepository $filterDateRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly NdkApiFactory $ndkApiFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * @return array{dates?: list<FilterDate>, cities?: array<int, array{name: string, locations?: list<array{name: string, id: string}>}>, categories?: list<array{uid: int|null, label: string}>}
     */
    public function getFilterValues(array $settings, RequestInterface $request): array
    {
        $filter = [];

        $filter['dates'] = $this->filterDateRepository->findDatesByStoragePid((int)($settings['filter']['pidDateFilter'] ?? 0))->toArray();

        foreach ((array)($settings['relativeFilterDates'] ?? []) as $filterDateData) {
            $filterDate = new FilterDate();
            $filterDate->setName((string)LocalizationUtility::translate((string)($filterDateData['label'] ?? ''), 'NkcEvent'));
            $filterDate->setDateFrom(new \DateTime());
            $filterDate->setDateTo(new \DateTime('@' . (time() + (int)($filterDateData['time'] ?? 0))));
            $filter['dates'][] = $filterDate;
        }

        $cities = GeneralUtility::trimExplode(',', (string)($settings['filter']['cityCollection'] ?? ''), true);
        if ($cities !== []) {
            $institutions = $this->findInstitutions($this->getInstitutionIds((string)($settings['filter']['institutionCollection'] ?? '')));
            foreach ($cities as $index => $city) {
                $filter['cities'][$index] = ['name' => $city];
                foreach ($institutions as $institution) {
                    $address = $institution->getAddress();
                    if ($address instanceof Address && $address->getCity() === $city) {
                        $filter['cities'][$index]['locations'][] = [
                            'name' => $institution->getOfficialName() ?: $institution->getName(),
                            'id' => $institution->getId(),
                        ];
                    }
                }
            }
        }

        foreach (GeneralUtility::intExplode(',', (string)($settings['filter']['categoryCollection'] ?? ''), true) as $categoryUid) {
            $category = $this->categoryRepository->findByUid($categoryUid);
            if ($category instanceof Category) {
                $filter['categories'][] = ['uid' => $category->getUid(), 'label' => $category->getTitle()];
            }
        }

        // "Rubriken": NAPI event types
        foreach (GeneralUtility::trimExplode(',', (string)($settings['filter']['eventTypes'] ?? ''), true) as $eventType) {
            $filter['eventTypes'][] = [
                'value' => $eventType,
                'label' => (string)(LocalizationUtility::translate('eventType.' . $eventType, 'NkcEvent') ?? $eventType),
            ];
        }

        /** @var ModifySearchFilterValuesEvent $event */
        $event = $this->eventDispatcher->dispatch(new ModifySearchFilterValuesEvent($filter, $settings, $request));
        return $event->getFilterValues();
    }

    /**
     * IDs of the NAPI resource URLs, e.g. "napi://resource/institution/123"
     *
     * @return list<string>
     */
    private function getInstitutionIds(string $institutionCollection): array
    {
        $ids = [];
        foreach (GeneralUtility::trimExplode(',', $institutionCollection, true) as $url) {
            [, $id] = NapiService::parseResourceUrl(parse_url($url) ?: []);
            if ($id) {
                $ids[] = (string)$id;
            }
        }
        return $ids;
    }

    /**
     * @param list<string> $ids
     * @return iterable<Institution>
     */
    protected function findInstitutions(array $ids): iterable
    {
        if ($ids === []) {
            return [];
        }

        $query = new InstitutionQuery();
        $query->setPageSize(99);
        $query->setInstitutions($ids);
        $query->setInclude([Institution::RELATION_ADDRESS]);
        try {
            return $this->ndkApiFactory->getApi()->factory(InstitutionRepository::class)->get($query);
        } catch (\Throwable) {
            return [];
        }
    }
}
