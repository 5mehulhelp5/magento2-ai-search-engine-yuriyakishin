<?php

declare(strict_types=1);

namespace Yu\AiSearchEngine\Test\Unit\Model\Indexer;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Yu\AiLlm\Api\EmbeddingProviderInterface;
use Yu\AiSearchEngine\Model\Indexer\EmbeddingTextBuilder;
use Yu\AiSearchEngine\Model\Indexer\VectorIndexer;
use Yu\AiSearchEngine\Model\Indexer\VectorIndexManager;

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

    private function makeIndexer(): VectorIndexer
    {
        return new VectorIndexer(
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(CollectionFactory::class),
            $this->createMock(StockRegistryInterface::class),
            $this->createMock(ResourceConnection::class),
            $this->createMock(EmbeddingProviderInterface::class),
            $this->createMock(VectorIndexManager::class),
            new EmbeddingTextBuilder(),
            $this->createMock(LoggerInterface::class)
        );
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
