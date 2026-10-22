<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Event;

use TYPO3\CMS\Extbase\Mvc\RequestInterface;

/**
 * Filter values of the search form: date presets ("dates"), categories, cities and
 * locations ("cities"), before they are assigned to the template
 */
final class ModifySearchFilterValuesEvent
{
    public function __construct(
        private array $filterValues,
        private readonly array $settings,
        private readonly RequestInterface $request
    ) {}

    public function getFilterValues(): array
    {
        return $this->filterValues;
    }

    public function setFilterValues(array $filterValues): void
    {
        $this->filterValues = $filterValues;
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
