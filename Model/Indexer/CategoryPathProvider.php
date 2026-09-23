<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model\Indexer;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Turns a product's category IDs into readable breadcrumb paths
 * ("Parent > Child") for its embedding text, with the store view's
 * category names. The store's root category is left out (it is a
 * container, not a meaning), a path through an inactive category is
 * dropped, and a path that is merely an ancestor of another kept path is
 * dropped too — the category index lists anchor parents alongside the
 * actual assignment, and the longest path already contains them.
 */
class CategoryPathProvider
{
    private const SEPARATOR = ' > ';

    /** @var array<int, array<int, array{name: string, path: string, active: bool}>> */
    private array $categoriesByStore = [];

    public function __construct(
        private readonly CollectionFactory $categoryCollectionFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @param int[] $categoryIds
     * @return string[]
     */
    public function getPaths(array $categoryIds, int $storeId): array
    {
        $categories = $this->getCategories($storeId);
        $namePathsByIdPath = [];
        foreach (array_unique($categoryIds) as $categoryId) {
            if (!isset($categories[$categoryId])) {
                continue;
            }
            $idPath = $categories[$categoryId]['path'];
            $names = $this->resolveNames($idPath, $categories);
            if ($names !== []) {
                $namePathsByIdPath[$idPath] = implode(self::SEPARATOR, $names);
            }
        }

        $paths = [];
        foreach ($namePathsByIdPath as $idPath => $namePath) {
            foreach (array_keys($namePathsByIdPath) as $otherIdPath) {
                if (str_starts_with((string)$otherIdPath, $idPath . '/')) {
                    continue 2;
                }
            }
            $paths[] = $namePath;
        }
        sort($paths);
        return array_values(array_unique($paths));
    }

    /**
     * @param array<int, array{name: string, path: string, active: bool}> $categories
     * @return string[] empty if any category on the path is inactive
     */
    private function resolveNames(string $idPath, array $categories): array
    {
        $names = [];
        foreach (explode('/', $idPath) as $id) {
            $category = $categories[(int)$id] ?? null;
            if ($category === null) {
                // Tree root and store root category: not loaded, not named.
                continue;
            }
            if (!$category['active']) {
                return [];
            }
            if ($category['name'] !== '') {
                $names[] = $category['name'];
            }
        }
        return $names;
    }

    /**
     * Also used outside indexing (e.g. to label category refinements),
     * hence public; loaded once per store per request.
     *
     * @return array<int, array{name: string, path: string, active: bool}> every category below the store's root
     */
    public function getCategories(int $storeId): array
    {
        if (isset($this->categoriesByStore[$storeId])) {
            return $this->categoriesByStore[$storeId];
        }
        $rootId = (int)$this->storeManager->getStore($storeId)->getRootCategoryId();
        $collection = $this->categoryCollectionFactory->create()
            ->setStoreId($storeId)
            ->addAttributeToSelect(['name', 'is_active'])
            ->addFieldToFilter('path', ['like' => '1/' . $rootId . '/%']);

        $categories = [];
        foreach ($collection as $category) {
            $categories[(int)$category->getId()] = [
                'name' => trim((string)$category->getName()),
                'path' => (string)$category->getPath(),
                'active' => (bool)$category->getIsActive(),
            ];
        }
        return $this->categoriesByStore[$storeId] = $categories;
    }
}
