<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Domain\Repository;

use Nordkirche\NkcEvent\Domain\Model\FilterDate;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * @extends Repository<FilterDate>
 */
class FilterDateRepository extends Repository
{
    protected $defaultOrderings = [
        'dateFrom' => QueryInterface::ORDER_ASCENDING,
    ];

    /**
     * Date ranges of a storage page: running (default) or upcoming ones
     *
     * @return QueryResultInterface<int, FilterDate>
     */
    public function findDatesByStoragePid(int $storagePid, bool $startedDates = true, int $limit = 5): QueryResultInterface
    {
        $today = strtotime('today');
        $query = $this->createQuery();
        $query->getQuerySettings()->setRespectStoragePage(false);
        $query->matching(
            $query->logicalAnd(
                $query->equals('pid', $storagePid),
                $startedDates ? $query->greaterThanOrEqual('dateTo', $today) : $query->greaterThanOrEqual('dateFrom', $today)
            )
        );
        $query->setOrderings(['dateFrom' => QueryInterface::ORDER_ASCENDING]);
        $query->setLimit($limit);

        return $query->execute();
    }
}
