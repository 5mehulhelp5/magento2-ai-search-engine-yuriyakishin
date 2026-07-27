<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model;

use Magento\Catalog\Model\Config\LayerCategoryConfig;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Top-level storefront categories the AI may resolve a query to -- the
 * same direct children of the store's root category native top nav
 * shows, not the full category tree.
 */
class CategoryMap
{
    /** @var array<int, array<string, int>> */
    private array $mapByStore = [];

    public function __construct(
        private readonly CollectionFactory $categoryCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly LayerCategoryConfig $layerCategoryConfig
    ) {
    }

    /**
     * Whether native search-results layered navigation would actually
     * read a "cat" URL param at all -- a store-wide setting, checked
     * live, never written by this module.
     */
    public function isFilterableInSearch(): bool
    {
        return $this->layerCategoryConfig->isCategoryFilterVisibleInLayerNavigation();
    }

    /**
     * @return string[] category names, for the LLM prompt
     */
    public function getNames(int $storeId): array
    {
        return array_keys($this->getMap($storeId));
    }

    /**
     * @param string $name
     * @param int $storeId
     * @return int|null category ID, or null when no top-level category matches the name
     */
    public function resolveId(string $name, int $storeId): ?int
    {
        $map = $this->getMap($storeId);
        $needle = mb_strtolower(trim($name));
        foreach ($map as $label => $id) {
            if (mb_strtolower($label) === $needle) {
                return $id;
            }
        }
        return null;
    }

    /**
     * @param int $categoryId
     * @param int $storeId
     * @return string|null category name, or null when the ID isn't a known top-level category
     */
    public function resolveLabel(int $categoryId, int $storeId): ?string
    {
        $label = array_search($categoryId, $this->getMap($storeId), true);
        return $label === false ? null : (string)$label;
    }

    /**
     * @return array<string, int>
     */
    private function getMap(int $storeId): array
    {
        if (!isset($this->mapByStore[$storeId])) {
            $rootCategoryId = (int)$this->storeManager->getStore($storeId)->getRootCategoryId();
            $collection = $this->categoryCollectionFactory->create()
                ->addAttributeToSelect('name')
                ->addFieldToFilter('is_active', 1)
                ->addFieldToFilter('parent_id', $rootCategoryId)
                ->setStoreId($storeId);
            $map = [];
            foreach ($collection as $category) {
                $name = trim((string)$category->getName());
                if ($name !== '') {
                    $map[$name] = (int)$category->getId();
                }
            }
            $this->mapByStore[$storeId] = $map;
        }
        return $this->mapByStore[$storeId];
    }
}
