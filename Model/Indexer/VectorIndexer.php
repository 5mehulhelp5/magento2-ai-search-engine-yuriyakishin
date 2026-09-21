<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model\Indexer;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\ActionInterface as IndexerActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Yu\AiLlm\Api\EmbeddingProviderInterface;
use Yu\AiLlm\Model\LlmProviderException;

/**
 * Syncs the vector index: embeds name/short description/description and
 * writes the vector alongside the filter fields EngineFinder uses
 * (visibility, status, category_ids, is_out_of_stock, per-group/website
 * price). One index per store view.
 */
class VectorIndexer implements IndexerActionInterface, MviewActionInterface
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly CollectionFactory $productCollectionFactory,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly ResourceConnection $resourceConnection,
        private readonly EmbeddingProviderInterface $embeddingProvider,
        private readonly VectorIndexManager $indexManager,
        private readonly EmbeddingTextBuilder $textBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function executeFull(): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            $productIds = array_map(
                'intval',
                $this->productCollectionFactory->create()
                    ->addFieldToFilter('status', 1)
                    ->setStoreId($storeId)
                    ->getAllIds()
            );
            $this->reindexStoreProducts($productIds, $storeId);
        }
    }

    /**
     * @param int[] $ids
     */
    public function executeList(array $ids): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $this->reindexStoreProducts(array_map('intval', $ids), (int)$store->getId());
        }
    }

    public function executeRow($id): void
    {
        $this->executeList([(int)$id]);
    }

    /**
     * @param int[] $ids
     */
    public function execute($ids): void
    {
        $this->executeList($ids);
    }

    /**
     * @param int[] $productIds
     */
    private function reindexStoreProducts(array $productIds, int $storeId): void
    {
        if ($productIds === []) {
            return;
        }
        try {
            $indexName = $this->indexManager->ensureIndex($storeId, $this->embeddingProvider->getDimensions());
        } catch (LlmProviderException|\RuntimeException $e) {
            $this->logger->error('AI Search Vector indexer: cannot prepare the vector index: ' . $e->getMessage());
            return;
        }
        $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();

        foreach (array_chunk($productIds, self::BATCH_SIZE) as $batch) {
            $this->reindexBatch($batch, $storeId, $websiteId, $indexName);
        }
    }

    /**
     * @param int[] $batch
     */
    private function reindexBatch(array $batch, int $storeId, int $websiteId, string $indexName): void
    {
        $collection = $this->productCollectionFactory->create()
            ->addAttributeToSelect(['name', 'description', 'short_description', 'visibility', 'status'])
            ->addFieldToFilter('entity_id', ['in' => $batch])
            ->setStoreId($storeId);

        $products = [];
        $texts = [];
        foreach ($collection as $product) {
            $text = $this->textBuilder->build([
                (string)$product->getName(),
                (string)$product->getShortDescription(),
                (string)$product->getDescription(),
            ]);
            if ($text === '') {
                continue;
            }
            $productId = (int)$product->getId();
            $products[$productId] = $product;
            $texts[$productId] = $text;
        }

        // Missing from $products (deleted, or no embeddable text) -> remove from index.
        $missing = array_diff($batch, array_keys($products));

        $bulk = [];
        if ($texts !== []) {
            try {
                $vectors = $this->embeddingProvider->embed(array_values($texts));
            } catch (LlmProviderException $e) {
                $this->logger->error('AI Search Vector indexer: embedding call failed, skipping this batch: ' . $e->getMessage());
                return;
            }
            $productIdsInOrder = array_keys($texts);
            $prices = $this->fetchPrices($productIdsInOrder, $websiteId);
            $categoryIds = $this->fetchCategoryIds($productIdsInOrder, $storeId);

            foreach ($productIdsInOrder as $position => $productId) {
                $product = $products[$productId];
                $bulk[] = ['index' => ['_index' => $indexName, '_id' => (string)$productId]];
                $bulk[] = $this->buildDocument(
                    $vectors[$position],
                    (int)$product->getVisibility(),
                    (int)$product->getStatus(),
                    $categoryIds[$productId] ?? [],
                    (bool)$this->stockRegistry->getStockItem($productId)->getIsInStock(),
                    $prices[$productId] ?? [],
                    $websiteId
                );
            }
        }
        foreach ($missing as $productId) {
            $bulk[] = ['delete' => ['_index' => $indexName, '_id' => (string)$productId]];
        }

        $this->indexManager->bulk($bulk);
    }

    /**
     * @param float[] $vector
     * @param int[] $categoryIds
     * @param array<int, float> $priceByGroup customer_group_id => price, for this product on this website
     * @return array<string, mixed>
     */
    private function buildDocument(
        array $vector,
        int $visibility,
        int $status,
        array $categoryIds,
        bool $isInStock,
        array $priceByGroup,
        int $websiteId
    ): array {
        $document = [
            'embedding' => $vector,
            'visibility' => $visibility,
            'status' => $status,
            'category_ids' => $categoryIds,
            // 0 means IN stock, same as catalogsearch_fulltext.
            'is_out_of_stock' => (int)!$isInStock,
        ];
        foreach ($priceByGroup as $customerGroupId => $price) {
            $document['price_' . $customerGroupId . '_' . $websiteId] = $price;
        }
        return $document;
    }

    /**
     * @param int[] $productIds
     * @return array<int, array<int, float>> product ID => [customer_group_id => price]
     */
    private function fetchPrices(array $productIds, int $websiteId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('catalog_product_index_price'),
                ['entity_id', 'customer_group_id', 'min_price']
            )
            ->where('entity_id IN (?)', $productIds)
            ->where('website_id = ?', $websiteId);

        $prices = [];
        foreach ($connection->fetchAll($select) as $row) {
            $prices[(int)$row['entity_id']][(int)$row['customer_group_id']] = (float)$row['min_price'];
        }
        return $prices;
    }

    /**
     * Anchor-expanded category IDs (same source catalogsearch_fulltext
     * uses) — $product->getCategoryIds() would only give direct
     * assignments, missing anchor parents like "Women".
     *
     * @param int[] $productIds
     * @return array<int, int[]> product ID => category IDs
     */
    private function fetchCategoryIds(array $productIds, int $storeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('catalog_category_product_index_store' . $storeId),
                ['product_id', 'category_id']
            )
            ->where('product_id IN (?)', $productIds);

        $categoryIds = [];
        foreach ($connection->fetchAll($select) as $row) {
            $categoryIds[(int)$row['product_id']][] = (int)$row['category_id'];
        }
        return $categoryIds;
    }
}
