<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Sub categories of the NAPI categories. They are synchronised to sys_category with
 * the NAPI id as uid and the parent relation (nkc_base:napi_sync).
 */
class CategoryTree
{
    /**
     * Safety limit for broken (circular) trees
     */
    private const MAX_DEPTH = 10;

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /**
     * The categories and all their sub categories
     *
     * @param list<string|int> $categories
     * @return list<string>
     */
    public function withSubCategories(array $categories): array
    {
        $all = array_values(array_unique(array_map('strval', $categories)));
        $parents = array_map('intval', $all);

        for ($depth = 0; $depth < self::MAX_DEPTH && $parents !== []; $depth++) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_category');
            $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
            $children = $queryBuilder
                ->select('uid')
                ->from('sys_category')
                ->where($queryBuilder->expr()->in('parent', $queryBuilder->createNamedParameter($parents, Connection::PARAM_INT_ARRAY)))
                ->executeQuery()
                ->fetchFirstColumn();

            $parents = [];
            foreach ($children as $child) {
                if (!in_array((string)$child, $all, true)) {
                    $all[] = (string)$child;
                    $parents[] = (int)$child;
                }
            }
        }
        return $all;
    }
}
