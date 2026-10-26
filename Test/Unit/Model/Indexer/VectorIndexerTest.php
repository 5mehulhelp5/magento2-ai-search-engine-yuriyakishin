<?php

declare(strict_types=1);

namespace Yu\AiSearchEngine\Test\Unit\Model\Indexer;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Yu\AiLlm\Api\EmbeddingProviderInterface;
use Yu\AiLlm\Model\LlmProviderException;
use Yu\AiSearchEngine\Model\Indexer\AttributeTextProvider;
use Yu\AiSearchEngine\Model\Indexer\CategoryPathProvider;
use Yu\AiSearchEngine\Model\Indexer\VectorChangelog;
use Yu\AiSearchEngine\Model\Indexer\EmbeddingAttributeResolver;
use Yu\AiSearchEngine\Model\Indexer\EmbeddingTextBuilder;
use Yu\AiSearchEngine\Model\Indexer\VectorIndexer;
use Yu\AiSearchEngine\Model\Indexer\VectorIndexManager;
use Yu\AiSearchEngine\Model\SemanticConfig;

class VectorIndexerTest extends TestCase
{
    public function testBuildDocumentAssemblesAllFilterFieldsAndTheVector(): void
    {
        $indexer = $this->makeIndexer();

        $document = $this->invokeBuildDocument(
            $indexer,
            vector: [0.1, 0.2, 0.3],
            visibility: 4,
            status: 1,
            categoryIds: [10, 11],
            isInStock: true,
            priceByGroup: [0 => 19.99, 1 => 17.99],
            websiteId: 1
        );

        $this->assertSame([0.1, 0.2, 0.3], $document['embedding']);
        $this->assertSame(4, $document['visibility']);
        $this->assertSame(1, $document['status']);
        $this->assertSame([10, 11], $document['category_ids']);
        // Same negated convention as catalogsearch_fulltext's own field.
        $this->assertSame(0, $document['is_out_of_stock']);
        $this->assertSame(19.99, $document['price_0_1']);
        $this->assertSame(17.99, $document['price_1_1']);
    }

    public function testBuildDocumentMarksOutOfStockProductsWithOne(): void
    {
        $indexer = $this->makeIndexer();

        $document = $this->invokeBuildDocument(
            $indexer,
            vector: [0.1],
            visibility: 4,
            status: 1,
            categoryIds: [],
            isInStock: false,
            priceByGroup: [],
            websiteId: 1
        );

        $this->assertSame(1, $document['is_out_of_stock']);
    }

    public function testBuildDocumentOmitsPriceFieldsWhenNoPricesAreKnown(): void
    {
        $indexer = $this->makeIndexer();

        $document = $this->invokeBuildDocument(
            $indexer,
            vector: [0.1],
            visibility: 4,
            status: 1,
            categoryIds: [],
            isInStock: true,
            priceByGroup: [],
            websiteId: 1
        );

        foreach (array_keys($document) as $key) {
            $this->assertStringStartsNotWith('price_', $key);
        }
    }

    public function testFullReindexSetsTheStoreBeforeFilteringAndLimitsToTheStoresWebsite(): void
    {
        $calls = [];
        $collection = $this->makeCollection($calls, [], [5]);
        $indexManager = $this->createMock(VectorIndexManager::class);
        $indexManager->expects($this->once())->method('deleteAllExcept')->with(2, [5]);
        $indexer = $this->makeIndexer(
            storeManager: $this->makeStoreManager(storeId: 2, websiteId: 3),
            collectionFactory: $this->makeCollectionFactory($collection),
            indexManager: $indexManager
        );

        $indexer->executeFull();

        $this->assertSame(['setStoreId', 2], $calls[0]);
        $this->assertContains(['addWebsiteFilter', [3]], $calls);
        $this->assertContains(['addFieldToFilter', 'status'], $calls);
    }

    public function testFullReindexFailureIsThrownSoItIsNotReportedAsSuccessful(): void
    {
        $indexManager = $this->createMock(VectorIndexManager::class);
        $indexManager->method('ensureIndex')->willThrowException(new \RuntimeException('dimension mismatch'));
        $indexManager->expects($this->never())->method('deleteAllExcept');
        $calls = [];
        $indexer = $this->makeIndexer(
            storeManager: $this->makeStoreManager(storeId: 1, websiteId: 1),
            collectionFactory: $this->makeCollectionFactory($this->makeCollection($calls, [], [5])),
            indexManager: $indexManager
        );

        $this->expectExceptionMessage('dimension mismatch');
        $indexer->executeFull();
    }

    public function testPartialReindexLimitsTheBatchToEnabledProductsOfTheStoresWebsite(): void
    {
        $calls = [];
        $collection = $this->makeCollection($calls, [$this->makeProduct(7)]);
        $embeddingProvider = $this->createMock(EmbeddingProviderInterface::class);
        $embeddingProvider->method('embed')->willReturn([[0.1]]);
        $indexer = $this->makeIndexer(
            storeManager: $this->makeStoreManager(storeId: 2, websiteId: 3),
            collectionFactory: $this->makeCollectionFactory($collection),
            embeddingProvider: $embeddingProvider
        );

        $indexer->executeList([7]);

        $this->assertSame(['setStoreId', 2], $calls[0]);
        $this->assertContains(['addWebsiteFilter', [3]], $calls);
        $this->assertContains(['addFieldToFilter', 'status'], $calls);
    }

    public function testProductsLeftOutOfTheBatchAreDeletedFromTheIndex(): void
    {
        $calls = [];
        $embeddingProvider = $this->createMock(EmbeddingProviderInterface::class);
        $embeddingProvider->method('embed')->willReturn([[0.1]]);
        $indexManager = $this->createMock(VectorIndexManager::class);
        $indexManager->method('ensureIndex')->willReturn('vector_1');
        $indexManager->expects($this->once())->method('bulk')->with($this->callback(
            static fn(array $ops): bool => in_array(['delete' => ['_index' => 'vector_1', '_id' => '8']], $ops, true)
        ));
        $indexer = $this->makeIndexer(
            storeManager: $this->makeStoreManager(storeId: 1, websiteId: 1),
            collectionFactory: $this->makeCollectionFactory($this->makeCollection($calls, [$this->makeProduct(7)])),
            embeddingProvider: $embeddingProvider,
            indexManager: $indexManager
        );

        $indexer->executeList([7, 8]);
    }

    public function testPartialReindexThrowsWhenTheEmbeddingCallFails(): void
    {
        $calls = [];
        $embeddingProvider = $this->createMock(EmbeddingProviderInterface::class);
        $embeddingProvider->method('embed')->willThrowException(new LlmProviderException('rate limited', true));
        $indexManager = $this->createMock(VectorIndexManager::class);
        $indexManager->expects($this->never())->method('bulk');
        $indexer = $this->makeIndexer(
            storeManager: $this->makeStoreManager(storeId: 1, websiteId: 1),
            collectionFactory: $this->makeCollectionFactory($this->makeCollection($calls, [$this->makeProduct(7)])),
            embeddingProvider: $embeddingProvider,
            indexManager: $indexManager
        );

        $this->expectException(LlmProviderException::class);
        $indexer->executeList([7]);
    }

    public function testPartialReindexThrowsWhenTheProviderReturnsTooFewVectors(): void
    {
        $calls = [];
        $embeddingProvider = $this->createMock(EmbeddingProviderInterface::class);
        $embeddingProvider->method('embed')->willReturn([[0.1]]);
        $indexer = $this->makeIndexer(
            storeManager: $this->makeStoreManager(storeId: 1, websiteId: 1),
            collectionFactory: $this->makeCollectionFactory(
                $this->makeCollection($calls, [$this->makeProduct(7), $this->makeProduct(8)])
            ),
            embeddingProvider: $embeddingProvider
        );

        $this->expectExceptionMessage('returned 1 vector(s) for 2 text(s)');
        $indexer->executeList([7, 8]);
    }

    public function testScheduledUpdateFailureQueuesTheIdsForRetryInsteadOfThrowing(): void
    {
        $indexManager = $this->createMock(VectorIndexManager::class);
        $indexManager->method('ensureIndex')->willThrowException(new \RuntimeException('search engine down'));
        $changelog = $this->createMock(VectorChangelog::class);
        $changelog->expects($this->once())->method('add')->with([7, 8]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('search engine down'));
        $indexer = $this->makeIndexer(
            storeManager: $this->makeStoreManager(storeId: 1, websiteId: 1),
            indexManager: $indexManager,
            vectorChangelog: $changelog,
            logger: $logger
        );

        $indexer->execute(['7', '8']);
    }

    public function testSuccessfulScheduledUpdateQueuesNothing(): void
    {
        $calls = [];
        $embeddingProvider = $this->createMock(EmbeddingProviderInterface::class);
        $embeddingProvider->method('embed')->willReturn([[0.1]]);
        $changelog = $this->createMock(VectorChangelog::class);
        $changelog->expects($this->never())->method('add');
        $indexer = $this->makeIndexer(
            storeManager: $this->makeStoreManager(storeId: 1, websiteId: 1),
            collectionFactory: $this->makeCollectionFactory($this->makeCollection($calls, [$this->makeProduct(7)])),
            embeddingProvider: $embeddingProvider,
            vectorChangelog: $changelog
        );

        $indexer->execute([7]);
    }

    private function makeIndexer(
        ?StoreManagerInterface $storeManager = null,
        ?CollectionFactory $collectionFactory = null,
        ?EmbeddingProviderInterface $embeddingProvider = null,
        ?VectorIndexManager $indexManager = null,
        ?VectorChangelog $vectorChangelog = null,
        ?LoggerInterface $logger = null
    ): VectorIndexer {
        return new VectorIndexer(
            $storeManager ?? $this->createMock(StoreManagerInterface::class),
            $collectionFactory ?? $this->createMock(CollectionFactory::class),
            $this->createMock(StockRegistryInterface::class),
            $this->makeResourceConnection(),
            $embeddingProvider ?? $this->createMock(EmbeddingProviderInterface::class),
            $indexManager ?? $this->createMock(VectorIndexManager::class),
            new EmbeddingTextBuilder(),
            $this->createMock(SemanticConfig::class),
            $this->createMock(EmbeddingAttributeResolver::class),
            $this->createMock(AttributeTextProvider::class),
            $this->createMock(CategoryPathProvider::class),
            $vectorChangelog ?? $this->createMock(VectorChangelog::class),
            $logger ?? $this->createMock(LoggerInterface::class)
        );
    }

    private function makeStoreManager(int $storeId, int $websiteId): StoreManagerInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($storeId);
        $store->method('getWebsiteId')->willReturn($websiteId);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store]);
        $storeManager->method('getStore')->willReturn($store);
        return $storeManager;
    }

    private function makeCollectionFactory(Collection $collection): CollectionFactory
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        return $factory;
    }

    /**
     * Records the fluent calls in order: [method, first argument].
     *
     * @param array<int, array{0: string, 1: mixed}> $calls
     * @param Product[] $products iterated by a partial reindex
     * @param int[] $allIds returned by getAllIds() for a full reindex
     */
    private function makeCollection(array &$calls, array $products, array $allIds = []): Collection
    {
        $collection = $this->createMock(Collection::class);
        foreach (['setStoreId', 'addWebsiteFilter', 'addAttributeToSelect', 'addFieldToFilter'] as $method) {
            $collection->method($method)->willReturnCallback(
                function ($argument) use (&$calls, $method, $collection) {
                    $calls[] = [$method, $argument];
                    return $collection;
                }
            );
        }
        $collection->method('getAllIds')->willReturn($allIds);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($products));
        return $collection;
    }

    private function makeProduct(int $id): Product
    {
        /** @var Product&MockObject $product */
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getName', 'getVisibility', 'getStatus'])
            ->addMethods(['getShortDescription', 'getDescription'])
            ->getMock();
        $product->method('getId')->willReturn($id);
        $product->method('getName')->willReturn('Product ' . $id);
        $product->method('getVisibility')->willReturn(4);
        $product->method('getStatus')->willReturn(1);
        return $product;
    }

    private function makeResourceConnection(): ResourceConnection
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([]);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    /**
     * buildDocument() is intentionally private — it has no reason to be
     * called from outside VectorIndexer — so this test reaches it via
     * Reflection rather than widening its visibility for testability alone.
     *
     * @param float[] $vector
     * @param int[] $categoryIds
     * @param array<int, float> $priceByGroup
     * @return array<string, mixed>
     */
    private function invokeBuildDocument(
        VectorIndexer $indexer,
        array $vector,
        int $visibility,
        int $status,
        array $categoryIds,
        bool $isInStock,
        array $priceByGroup,
        int $websiteId
    ): array {
        $method = new \ReflectionMethod(VectorIndexer::class, 'buildDocument');
        $method->setAccessible(true);
        return $method->invoke($indexer, $vector, $visibility, $status, $categoryIds, $isInStock, $priceByGroup, $websiteId);
    }
}
