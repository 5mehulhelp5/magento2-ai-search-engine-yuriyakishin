<?php

declare(strict_types=1);

namespace Yu\AiSearchEngine\Test\Unit\Model\Indexer;

use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\DataObject;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Yu\AiSearchEngine\Model\Indexer\CategoryPathProvider;

class CategoryPathProviderTest extends TestCase
{
    /**
     * Tree (store root 2):
     *   10 Parent
     *     11 Child
     *       12 Grandchild
     *     13 Hidden (inactive)
     *       14 Under hidden
     *   20 Other
     */
    private const TREE = [
        ['entity_id' => 10, 'name' => 'Parent', 'path' => '1/2/10', 'is_active' => 1],
        ['entity_id' => 11, 'name' => 'Child', 'path' => '1/2/10/11', 'is_active' => 1],
        ['entity_id' => 12, 'name' => 'Grandchild', 'path' => '1/2/10/11/12', 'is_active' => 1],
        ['entity_id' => 13, 'name' => 'Hidden', 'path' => '1/2/10/13', 'is_active' => 0],
        ['entity_id' => 14, 'name' => 'Under hidden', 'path' => '1/2/10/13/14', 'is_active' => 1],
        ['entity_id' => 20, 'name' => 'Other', 'path' => '1/2/20', 'is_active' => 1],
    ];

    public function testBuildsBreadcrumbsWithoutTheStoreRoot(): void
    {
        $this->assertSame(['Other', 'Parent > Child'], $this->makeProvider()->getPaths([11, 20], 1));
    }

    public function testAncestorPathsListedByTheAnchorIndexAreFoldedIntoTheLongestPath(): void
    {
        $this->assertSame(['Parent > Child > Grandchild'], $this->makeProvider()->getPaths([2, 10, 11, 12], 1));
    }

    public function testPathsThroughInactiveCategoriesAreDropped(): void
    {
        $this->assertSame(['Parent'], $this->makeProvider()->getPaths([10, 13, 14], 1));
    }

    public function testUnknownCategoriesAreIgnored(): void
    {
        $this->assertSame([], $this->makeProvider()->getPaths([999], 1));
    }

    public function testCategoriesAreLoadedOncePerStore(): void
    {
        $factory = $this->makeFactory();
        $factory->expects($this->once())->method('create');
        $provider = new CategoryPathProvider($factory, $this->makeStoreManager());

        $provider->getPaths([11], 1);
        $provider->getPaths([20], 1);
    }

    private function makeProvider(): CategoryPathProvider
    {
        return new CategoryPathProvider($this->makeFactory(), $this->makeStoreManager());
    }

    /**
     * @return CollectionFactory&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeFactory(): CollectionFactory
    {
        $items = array_map(static fn(array $row) => new DataObject($row + ['id' => $row['entity_id']]), self::TREE);
        $collection = $this->createMock(Collection::class);
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addFieldToFilter')->with('path', ['like' => '1/2/%'])->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        return $factory;
    }

    private function makeStoreManager(): StoreManagerInterface
    {
        $store = $this->getMockBuilder(StoreInterface::class)
            ->addMethods(['getRootCategoryId'])
            ->getMockForAbstractClass();
        $store->method('getRootCategoryId')->willReturn(2);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return $storeManager;
    }
}
