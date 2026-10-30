<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Service;

use Nordkirche\Ndk\Domain\Model\Category;
use Nordkirche\Ndk\Domain\Query\EventQuery;
use Nordkirche\Ndk\Domain\Query\InstitutionQuery;
use Nordkirche\Ndk\Domain\Repository\InstitutionRepository;
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
    /**
     * Value of settings.flexform.churchAssociation: the church association of the site institution
     */
    public const CHURCH_ASSOCIATION_OF_SITE = 'site';

    /**
     * Church associations of the site institutions, per request
     *
     * @var array<string, list<string>>
     */
    private array $churchAssociationsOfSite = [];

    public function __construct(
        private readonly NdkApiFactory $ndkApiFactory,
        private readonly ?CategoryTree $categoryTree = null,
    ) {}

    /**
     * Filters of the list and calendar plugins (settings.flexform)
     *
     * @param array $settings Plugin settings, for the institutions of the site (settings.siteInstitutions)
     */
    public function applyListFilters(EventQuery $query, array $flexform, array $settings = []): void
    {
        $this->applyOrganizers($query, $flexform, $settings);
        $this->applyChurchAssociations($query, $flexform, $settings);
        $this->applyCities($query, (string)($flexform['cities'] ?? ''));
        if (($zipCodes = QueryFilter::toList($flexform['zipCodes'] ?? '')) !== []) {
            $query->setZipCodes($zipCodes);
        }
        if (($targetGroups = QueryFilter::toList($flexform['targetGroupCollection'] ?? '')) !== []) {
            $query->setTargetGroups($targetGroups);
        }
        if (($eventTypes = QueryFilter::toList($flexform['eventTypes'] ?? '')) !== []) {
            $query->setEventTypes($eventTypes);
        }

        $this->applyLocations($query, $flexform['eventLocation'] ?? '');
        $this->applyCategories($query, $flexform['categories'] ?? '', $flexform['categoryOperator'] ?? '', !empty($flexform['includeSubCategories']));

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
    public function applyMapFilters(EventQuery $query, array $flexform, array $settings = []): void
    {
        if (($events = QueryFilter::toList($flexform['eventCollection'] ?? '')) !== []) {
            $query->setEvents($events);
        }
        $this->applyOrganizers($query, $flexform, $settings);
        $this->applyCategories($query, $flexform['categories'] ?? '', $flexform['selectCategoryOption'] ?? '', !empty($flexform['includeSubCategories']));
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
            $this->applyCities($query, $city);
        }

        $eventType = (string)$searchRequest->getEventType();
        if ($eventType !== '') {
            $eventTypes = $query->getEventTypes();
            if (!$eventTypes || in_array($eventType, $eventTypes, true)) {
                $query->setEventTypes([$eventType]);
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

    /**
     * Institutions of the plugin, or of the site ("Nur Veranstaltungen der Institution dieser Site").
     * With sub institutions the NAPI filter organizers_with_parents includes all levels below.
     */
    private function applyOrganizers(EventQuery $query, array $flexform, array $settings): void
    {
        $organizers = QueryFilter::toList($flexform['institutionCollection'] ?? '');
        if (!empty($flexform['restrictToSiteInstitution']) && ($siteInstitutions = $this->getSiteInstitutions($settings)) !== []) {
            $organizers = $siteInstitutions;
        }
        if ($organizers === []) {
            return;
        }
        if (!empty($flexform['includeChildInstitutions'])) {
            $query->setOrganizersWithParents($organizers);
        } else {
            $query->setOrganizers($organizers);
        }
    }

    /**
     * Institutions of the site: settings.siteInstitutions (ids or NAPI URLs, comma separated)
     *
     * @return list<string>
     */
    public function getSiteInstitutions(array $settings): array
    {
        return QueryFilter::siteInstitutions($settings['siteInstitutions'] ?? '');
    }

    /**
     * "Handlungsraum": church associations (NAPI categories) of the plugin or of the site institution
     */
    private function applyChurchAssociations(EventQuery $query, array $flexform, array $settings): void
    {
        $value = $flexform['churchAssociation'] ?? '';
        if ($value === self::CHURCH_ASSOCIATION_OF_SITE) {
            $churchAssociations = $this->getChurchAssociationsOfSite($settings);
            // Like nk_event: without a church association of the site no event matches
            $query->setChurchAssociations($churchAssociations !== [] ? $churchAssociations : ['0']);
        } elseif (($churchAssociations = QueryFilter::toList($value)) !== []) {
            $query->setChurchAssociations($churchAssociations);
        }
    }

    /**
     * @return list<string>
     */
    protected function getChurchAssociationsOfSite(array $settings): array
    {
        $institutions = $this->getSiteInstitutions($settings);
        if ($institutions === []) {
            return [];
        }
        $key = implode(',', $institutions);
        if (isset($this->churchAssociationsOfSite[$key])) {
            return $this->churchAssociationsOfSite[$key];
        }

        $churchAssociations = [];
        try {
            $institutionQuery = new InstitutionQuery();
            $institutionQuery->setInstitutions($institutions);
            $institutionQuery->setInclude(['categories']);
            foreach ($this->ndkApiFactory->getApi()->factory(InstitutionRepository::class)->get($institutionQuery) as $institution) {
                foreach ($institution->getCategories() ?? [] as $category) {
                    if ($category instanceof Category && $category->getIsChurchAssociation()) {
                        $churchAssociations[] = (string)$category->getId();
                    }
                }
            }
        } catch (\Throwable) {
            // NAPI not available: no church association
        }
        return $this->churchAssociationsOfSite[$key] = array_values(array_unique($churchAssociations));
    }

    /**
     * Comma separated cities and zip codes (numbers)
     */
    private function applyCities(EventQuery $query, string $value): void
    {
        $zipCodes = $cities = [];
        foreach (GeneralUtility::trimExplode(',', $value, true) as $city) {
            if (is_numeric($city)) {
                $zipCodes[] = $city;
            } else {
                $cities[] = $city;
            }
        }
        if ($zipCodes !== []) {
            $query->setZipCodes($zipCodes);
        }
        if ($cities !== []) {
            $query->setCities($cities);
        }
    }

    /**
     * Event locations or institutions (NAPI URLs): the NAPI filters by their names
     */
    private function applyLocations(EventQuery $query, mixed $value): void
    {
        $names = [];
        foreach (QueryFilter::toList($value) as $url) {
            try {
                /** @var NapiService $napiService */
                $napiService = $this->ndkApiFactory->getApi()->factory(NapiService::class);
                $location = $napiService->resolveUrl($url);
                if (is_object($location) && method_exists($location, 'getName') && $location->getName()) {
                    $names[] = (string)$location->getName();
                }
            } catch (\Throwable) {
                // Unknown location: no filter
            }
        }
        if ($names !== []) {
            $query->setLocation(implode(',', array_unique($names)));
        }
    }

    private function applyCategories(EventQuery $query, mixed $categories, mixed $operator, bool $includeSubCategories = false): void
    {
        $categories = QueryFilter::toList($categories);
        if ($categories === []) {
            return;
        }
        if ($includeSubCategories && $this->categoryTree instanceof CategoryTree) {
            $categories = $this->categoryTree->withSubCategories($categories);
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
