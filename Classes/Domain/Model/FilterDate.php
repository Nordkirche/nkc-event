<?php

namespace Nordkirche\NkcEvent\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class FilterDate extends AbstractEntity
{
    /**
     * @var \DateTime
     */
    protected $dateFrom;

    /**
     * @var \DateTime
     */
    protected $dateTo;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var string Name to show in TYPO3-Backend
     */
    protected $beName;

    /**
     * @return \DateTime
     */
    public function getDateFrom()
    {
        return $this->dateFrom;
    }

    /**
     * @param \DateTime $dateFrom
     */
    public function setDateFrom($dateFrom)
    {
        $this->dateFrom = $dateFrom;
    }

    /**
     * @return \DateTime
     */
    public function getDateTo()
    {
        return $this->dateTo;
    }

    /**
     * @param \DateTime $dateTo
     */
    public function setDateTo($dateTo)
    {
        $this->dateTo = $dateTo;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getBeName()
    {
        return $this->beName;
    }

    /**
     * @param string $beName
     */
    public function setBeName($beName)
    {
        $this->beName = $beName;
    }
}
