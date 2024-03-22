<?php

namespace Nordkirche\NkcEvent\Domain\Repository;

use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

class FilterDateRepository extends Repository
{
    // Order by daze
    protected $defaultOrderings = [
        'date_from' => QueryInterface::ORDER_ASCENDING,
    ];

    /**
     * @param int $storagePid
     * @param bool $startedDates
     * @param int $limit
     * @return array|QueryResultInterface
     */
    public function findDatesByStoragePid($storagePid, $startedDates = true, $limit = 5)
    {
        $today = strtotime('today');
        $query = $this->createQuery();
        $query->getQuerySettings()->setRespectStoragePage(false);

        if ((bool)$startedDates) {
            // Find also started dates
            $query->matching(
                $query->logicalAnd(
                    $query->equals('pid', $storagePid),
                    $query->greaterThanOrEqual('dateTo', $today)
                )
            );
        } else {
            // Find upcoming
            $query->matching(
                $query->logicalAnd(
                    $query->equals('pid', $storagePid),
                    $query->greaterThanOrEqual('dateFrom', $today)
                )
            );
        }

        $query->setOrderings(['dateFrom' => QueryInterface::ORDER_ASCENDING]);

        $query->setLimit($limit);

        return $query->execute();
    }
}
