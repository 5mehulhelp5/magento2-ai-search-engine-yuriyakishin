<?php

declare(strict_types=1);

namespace Yu\AiSearchEngine\Test\Unit\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Config\LayerCategoryConfig;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Yu\AiSearchEngine\Model\CategoryMap;

class CategoryMapTest extends TestCase
{
    private const STORE_ID = 1;
    private const ROOT_CATEGORY_ID = 2;

    public function testGetNamesReturnsTopLevelCategoryNames(): void
    {
        [$categoryMap] = $this->makeCategoryMap([
            $this->makeCategory('Men', 20),
            $this->makeCategory('Women', 21),
        ]);

        $this->assertSame(['Men', 'Women'], $categoryMap->getNames(self::STORE_ID));
    }

    public function testResolveIdMatchesNameCaseInsensitively(): void
    {
        [$categoryMap] = $this->makeCategoryMap([
            $this->makeCategory('Women', 21),
        ]);

        $this->assertSame(21, $categoryMap->resolveId('women', self::STORE_ID));
    }

    public function testResolveIdReturnsNullForUnknownName(): void
    {
        [$categoryMap] = $this->makeCategoryMap([
            $this->makeCategory('Women', 21),
        ]);

        $this->assertNull($categoryMap->resolveId('Kids', self::STORE_ID));
    }

    public function testResolveLabelReversesIdToName(): void
    {
        [$categoryMap] = $this->makeCategoryMap([
            $this->makeCategory('Women', 21),
        ]);

        $this->assertSame('Women', $categoryMap->resolveLabel(21, self::STORE_ID));
    }

    public function testResolveLabelReturnsNullForUnknownId(): void
    {
        [$categoryMap] = $this->makeCategoryMap([
            $this->makeCategory('Women', 21),
        ]);

        $this->assertNull($categoryMap->resolveLabel(999, self::STORE_ID));
    }

    public function testMapIsBuiltOnceAndCachedPerStore(): void
    {
        [$categoryMap, $collectionFactory] = $this->makeCategoryMap([
            $this->makeCategory('Women', 21),
        ]);
        $collectionFactory->expects($this->once())->method('create');

        $categoryMap->getNames(self::STORE_ID);
        $categoryMap->resolveId('Women', self::STORE_ID);
        $categoryMap->resolveLabel(21, self::STORE_ID);
    }

    public function testEmptyOrBlankCategoryNamesAreSkipped(): void
    {
        [$categoryMap] = $this->makeCategoryMap([
            $this->makeCategory('  ', 5),
            $this->makeCategory('Women', 21),
        ]);

        $this->assertSame(['Women'], $categoryMap->getNames(self::STORE_ID));
    }

    public function testIsFilterableInSearchDelegatesToLayerCategoryConfig(): void
    {
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $layerCategoryConfig = $this->createMock(LayerCategoryConfig::class);
        $layerCategoryConfig->method('isCategoryFilterVisibleInLayerNavigation')->willReturn(true);

        $categoryMap = new CategoryMap($collectionFactory, $storeManager, $layerCategoryConfig);

        $this->assertTrue($categoryMap->isFilterableInSearch());
    }

    /**
     * @param array<int, MockObject> $categories
     * @return array{0: CategoryMap, 1: MockObject}
     */
    private function makeCategoryMap(array $categories): array
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($categories));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $store = $this->createMock(Store::class);
        $store->method('getRootCategoryId')->willReturn(self::ROOT_CATEGORY_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->with(self::STORE_ID)->willReturn($store);

        $layerCategoryConfig = $this->createMock(LayerCategoryConfig::class);

        return [new CategoryMap($collectionFactory, $storeManager, $layerCategoryConfig), $collectionFactory];
    }

    private function makeCategory(string $name, int $id): MockObject
    {
        $category = $this->getMockBuilder(Category::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $category->setData(['name' => $name, 'id' => $id]);

        return $category;
    }
}
