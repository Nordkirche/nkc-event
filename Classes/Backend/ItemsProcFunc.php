<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Backend;

use Nordkirche\Ndk\Domain\Model\Category;
use Nordkirche\Ndk\Domain\Query\PageQuery;
use Nordkirche\Ndk\Domain\Repository\CategoryRepository;
use Nordkirche\NkcBase\Service\ApiService;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Select items of the plugin flexforms
 */
class ItemsProcFunc
{
    private const CACHE_IDENTIFIER = 'nkcevent_church_associations';

    /**
     * Church associations ("Handlungsräume"): NAPI categories with is_church_association
     */
    public function churchAssociations(array &$params): void
    {
        foreach ($this->getChurchAssociations() as $id => $name) {
            $params['items'][] = ['label' => $name, 'value' => (string)$id];
        }
    }

    /**
     * @return array<string, string> id => name
     */
    protected function getChurchAssociations(): array
    {
        $cache = $this->getCache();
        $cached = $cache?->get(self::CACHE_IDENTIFIER);
        if (is_array($cached)) {
            return $cached;
        }

        $churchAssociations = [];
        try {
            foreach (ApiService::getAllItems(ApiService::get()->factory(CategoryRepository::class), new PageQuery(), [], 100) as $category) {
                if ($category instanceof Category && $category->getIsChurchAssociation()) {
                    $churchAssociations[(string)$category->getId()] = (string)$category->getName();
                }
            }
        } catch (\Throwable) {
            // NAPI not available: no items, nothing cached
            return [];
        }
        asort($churchAssociations, SORT_NATURAL | SORT_FLAG_CASE);
        $cache?->set(self::CACHE_IDENTIFIER, $churchAssociations, [], 86400);
        return $churchAssociations;
    }

    private function getCache(): ?FrontendInterface
    {
        try {
            return GeneralUtility::makeInstance(CacheManager::class)->getCache('hash');
        } catch (\Throwable) {
            return null;
        }
    }
}
