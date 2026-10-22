<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Service;

use Nordkirche\Ndk\Domain\Query\EventQuery;
use Nordkirche\Ndk\Service\Interfaces\QueryInterface;
use Nordkirche\Ndk\Service\NapiService;
use Nordkirche\NkcBase\Service\NdkApiFactory;
use Nordkirche\NkcBase\Service\QueryFilter;
use Nordkirche\NkcEvent\Domain\Dto\SearchRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Applies the plugin settings (flexform) and the search of visitors to event queries
 */
class EventQueryService
{
    public function __construct(private readonly NdkApiFactory $ndkApiFactory) {}

    /**
     * Filters of the list plugin (settings.flexform)
     */
    public function applyListFilters(EventQuery $query, array $flexform): void
    {
        if (($organizers = QueryFilter::toList($flexform['institutionCollection'] ?? '')) !== []) {
            $query->setOrganizers($organizers);
        }
        if (($targetGroups = QueryFilter::toList($flexform['targetGroupCollection'] ?? '')) !== []) {
            $query->setTargetGroups($targetGroups);
        }
        if (($eventTypes = QueryFilter::toList($flexform['eventTypes'] ?? '')) !== []) {
            $query->setEventTypes($eventTypes);
        }

        if (!empty($flexform['eventLocation'])) {
            try {
                /** @var NapiService $napiService */
                $napiService = $this->ndkApiFactory->getApi()->factory(NapiService::class);
                $eventLocation = $napiService->resolveUrl((string)$flexform['eventLocation']);
                if (method_exists($eventLocation, 'getName') && $eventLocation->getName()) {
                    $query->setLocation($eventLocation->getName());
                }
            } catch (\Throwable) {
                // Unknown location: no filter
            }
        }

        $this->applyCategories($query, $flexform['categories'] ?? '', $flexform['categoryOperator'] ?? '');

        if ($dateFrom = $this->toDate($flexform['dateFrom'] ?? null)) {
            $query->setTimeFrom($dateFrom);
        }
        if ($dateTo = $this->toDate($flexform['dateTo'] ?? null)) {
            $query->setTimeTo($dateTo);
        }
        if (($numDays = $this->getNumDays($flexform)) !== null) {
            $query->setTimeTo($numDays);
        }

        QueryFilter::geo($query, $flexform['geosearch'] ?? 0, $flexform['latitude'] ?? 0, $flexform['longitude'] ?? 0, $flexform['radius'] ?? 0);
    }

    /**
     * Filters of the map plugins (settings.flexform)
     */
    public function applyMapFilters(EventQuery $query, array $flexform): void
    {
        if (($events = QueryFilter::toList($flexform['eventCollection'] ?? '')) !== []) {
            $query->setEvents($events);
        }
        if (($organizers = QueryFilter::toList($flexform['institutionCollection'] ?? '')) !== []) {
            $query->setOrganizers($organizers);
        }
        $this->applyCategories($query, $flexform['categories'] ?? '', $flexform['selectCategoryOption'] ?? '');
        if (($cities = QueryFilter::toList($flexform['cities'] ?? '')) !== []) {
            $query->setCities($cities);
        }
        if (($zipCodes = QueryFilter::toList($flexform['zipCodes'] ?? '')) !== []) {
            $query->setZipCodes($zipCodes);
        }

        $query->setTimeFromStart($this->toDate($flexform['dateFrom'] ?? null) ?? new \DateTime('today'));
        if ($dateTo = $this->toDate($flexform['dateTo'] ?? null)) {
            $query->setTimeToEnd($dateTo);
        }
        if (($numDays = $this->getNumDays($flexform)) !== null) {
            $query->setTimeToEnd($numDays);
        }
    }

    /**
     * Search form of the visitors
     */
    public function applyUserFilters(EventQuery $query, SearchRequest $searchRequest): void
    {
        $search = (string)$searchRequest->getSearch();
        if (strlen($search) > 2) {
            $query->setQuery($search);
        }

        $organizer = (int)$searchRequest->getOrganizer();
        if ($organizer > 0) {
            $organizers = $query->getOrganizers();
            if (!$organizers || in_array($organizer, $organizers)) {
                $query->setOrganizers([$organizer]);
            }
        }

        $query->setTimeFromStart($searchRequest->getDateFrom() ?: new \DateTime('today'));
        if ($searchRequest->getDateTo()) {
            $query->setTimeToEnd($searchRequest->getDateTo());
        }

        if ((string)$searchRequest->getLocation() !== '') {
            $query->setLocation((string)$searchRequest->getLocation());
        }

        $city = (string)$searchRequest->getCity();
        if (strlen($city) > 2) {
            $zipCodes = $cities = [];
            foreach (GeneralUtility::trimExplode(',', $city, true) as $value) {
                if (is_numeric($value)) {
                    $zipCodes[] = $value;
                } else {
                    $cities[] = $value;
                }
            }
            if ($zipCodes !== []) {
                $query->setZipCodes($zipCodes);
            }
            if ($cities !== []) {
                $query->setCities($cities);
            }
        }

        $category = (int)$searchRequest->getCategory();
        if ($category > 0) {
            $categories = $query->getCategoriesOr();
            if (!$categories || in_array($category, $categories)) {
                $query->setCategoriesOr([$category]);
            }
        }
    }

    private function applyCategories(EventQuery $query, mixed $categories, mixed $operator): void
    {
        $categories = QueryFilter::toList($categories);
        if ($categories === []) {
            return;
        }
        if ($operator == QueryInterface::OPERATOR_AND) {
            $query->setCategoriesAnd($categories);
        } else {
            $query->setCategoriesOr($categories);
        }
    }

    /**
     * "The next x days": until the start of the day x days ahead
     */
    private function getNumDays(array $flexform): ?\DateTime
    {
        $numDays = (int)($flexform['numDays'] ?? 0);
        return $numDays > 0 ? new \DateTime('today +' . $numDays . ' days') : null;
    }

    /**
     * Flexform date: timestamp (TYPO3 v13) or date string
     */
    private function toDate(mixed $value): ?\DateTime
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        $timestamp = is_numeric($value) ? (int)$value : strtotime((string)$value);
        return $timestamp ? new \DateTime(date('Y-m-d', $timestamp)) : null;
    }
}
