<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

/**
 * Date range for the date filter of the event search form
 */
class FilterDate extends AbstractEntity
{
    protected ?\DateTime $dateFrom = null;

    protected ?\DateTime $dateTo = null;

    protected string $name = '';

    /**
     * Name shown in the TYPO3 backend
     */
    protected string $beName = '';

    public function getDateFrom(): ?\DateTime
    {
        return $this->dateFrom;
    }

    public function setDateFrom(?\DateTime $dateFrom): void
    {
        $this->dateFrom = $dateFrom;
    }

    public function getDateTo(): ?\DateTime
    {
        return $this->dateTo;
    }

    public function setDateTo(?\DateTime $dateTo): void
    {
        $this->dateTo = $dateTo;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getBeName(): string
    {
        return $this->beName;
    }

    public function setBeName(string $beName): void
    {
        $this->beName = $beName;
    }
}
