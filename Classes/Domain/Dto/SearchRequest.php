<?php

namespace Nordkirche\NkcEvent\Domain\Dto;

class SearchRequest
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
     * @var int
     */
    protected $organizer = 0;

    /**
     * @var string
     */
    protected $search = '';

    /**
     * @var string
     */
    protected $location = '';

    /**
     * @var string
     */
    protected $city = '';

    /**
     * @var int
     */
    protected $category;

    /**
     * @var array
     */
    protected $array = [];

    /**
     * @return \DateTime
     */
    public function getDateFrom()
    {
        return $this->dateFrom;
    }

    /**
     * @param string $dateFrom
     */
    public function setDateFrom($dateFrom)
    {
        $this->dateFrom = $this->createValidDateValue($dateFrom);
    }

    /**
     * @return \DateTime
     */
    public function getDateTo()
    {
        return $this->dateTo;
    }

    /**
     * @param string $dateTo
     */
    public function setDateTo($dateTo)
    {
        $this->dateTo = $this->createValidDateValue($dateTo, true);
    }

    /**
     * @return int
     */
    public function getOrganizer()
    {
        return $this->organizer;
    }

    /**
     * @param int $organizer
     */
    public function setOrganizer($organizer)
    {
        $this->organizer = $organizer;
    }

    /**
     * @return string
     */
    public function getSearch()
    {
        return $this->search;
    }

    /**
     * @param string $search
     */
    public function setSearch(string $search)
    {
        $this->search = $search;
    }

    /**
     * @return string
     */
    public function getLocation()
    {
        return $this->location;
    }

    /**
     * @param string $location
     */
    public function setLocation($location)
    {
        $this->location = $location;
    }

    /**
     * @return string
     */
    public function getCity()
    {
        return $this->city;
    }

    /**
     * @param string $city
     */
    public function setCity(string $city)
    {
        $this->city = $city;
    }

    /**
     * @return int
     */
    public function getCategory()
    {
        return $this->category;
    }

    /**
     * @param int $category
     */
    public function setCategory($category)
    {
        $this->category = $category;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $array = [];
        if ($this->getDateFrom() != null) {
            $array['dateFrom'] = $this->getDateFrom()->format('d.m.Y');
        }
        if ($this->getDateTo() != null) {
            $array['dateTo'] = $this->getDateTo()->format('d.m.Y');
        }
        if ($this->getSearch() != '') {
            $array['search'] = $this->getSearch();
        }
        if ($this->getOrganizer() != 0) {
            $array['organizer'] = $this->getOrganizer();
        }
        if ($this->getLocation() != '') {
            $array['location'] = $this->getLocation();
        }
        if ($this->getCity() != '') {
            $array['city'] = $this->getCity();
        }
        if ($this->getCategory() != 0) {
            $array['category'] = $this->getCategory();
        }
        return $array;
    }

    /**
     * @return array
     */
    public function getArray(): array
    {
        return $this->toArray();
    }

    /**
     * @param array|null $data
     */
    public function decorate($data): void
    {
        if (is_array($data)) {
            foreach (['search', 'category', 'city', 'location', 'dateFrom', 'dateTo', 'organizer'] as $property) {
                if (!empty($data[$property])) {
                    $setterName = 'set' . ucfirst($property);
                    $this->$setterName($data[$property]);
                }
            }
        }
    }

    /**
     * @param $date
     * @param bool $setTimeToEndOfDay
     * @return \DateTime|null $result
     */
    public function createValidDateValue($date, $setTimeToEndOfDay = false)
    {
        if ($date instanceof \DateTime) {
            $result = $date;
        } elseif (is_int($date) && (int)$date > 0) {
            $result = new \DateTime();
            $result->setTimestamp($date);
        } elseif (is_string($date) && $date != '') {
            $result = new \DateTime($date);
            $result->setTimezone(new \DateTimeZone('Europe/Berlin'));
            if ($setTimeToEndOfDay) {
                $result->setTime(23, 59, 59);
            }
        } else {
            $result = null;
        }

        // Do not allow dates in the past
        if (($result instanceof \DateTime) && ($result < new \DateTime())) {
            $result = new \DateTime();
            if ($setTimeToEndOfDay) {
                $result->setTime(23, 59, 59);
            }
        }

        return $result;
    }
}
