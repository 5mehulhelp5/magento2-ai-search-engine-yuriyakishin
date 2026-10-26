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
use Yu\AiSearchEngine\Model\SemanticConfig;

/**
 * Syncs the vector index: embeds name, category paths, attribute values
 * and short description/description (in that order — most meaningful
 * first, as over-long text is cut from the end) and writes the vector
 * alongside the filter fields EngineFinder uses
 * (visibility, status, category_ids, is_out_of_stock, per-group/website
 * price). One index per store view.
 */
class VectorIndexer implements IndexerActionInterface, MviewActionInterface
{
    public const INDEXER_ID = 'yu_vector_search';
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly CollectionFactory $productCollectionFactory,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly ResourceConnection $resourceConnection,
        private readonly EmbeddingProviderInterface $embeddingProvider,
        private readonly VectorIndexManager $indexManager,
        private readonly EmbeddingTextBuilder $textBuilder,
        private readonly SemanticConfig $semanticConfig,
        private readonly EmbeddingAttributeResolver $attributeResolver,
        private readonly AttributeTextProvider $attributeTextProvider,
        private readonly CategoryPathProvider $categoryPathProvider,
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
            $this->removeStaleDocuments($productIds, $storeId);
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
     * Drops documents of products deleted or disabled while the index was
     * not being kept in sync; a partial reindex can't see those.
     *
     * @param int[] $keepProductIds
     */
    private function removeStaleDocuments(array $keepProductIds, int $storeId): void
    {
        try {
            $this->indexManager->deleteAllExcept($storeId, $keepProductIds);
        } catch (\Exception $e) {
            $this->logger->error('AI Search Vector indexer: cannot remove stale documents: ' . $e->getMessage());
        }
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
        $attributeCodes = $this->attributeResolver->getAttributeCodes($storeId);
        $includeCategories = $this->semanticConfig->isCategoryEmbeddingEnabled($storeId);

        foreach (array_chunk($productIds, self::BATCH_SIZE) as $batch) {
            $this->reindexBatch($batch, $storeId, $websiteId, $indexName, $attributeCodes, $includeCategories);
        }
    }

    /**
     * @param int[] $batch
     * @param string[] $attributeCodes
     */
    private function reindexBatch(
        array $batch,
        int $storeId,
        int $websiteId,
        string $indexName,
        array $attributeCodes,
        bool $includeCategories
    ): void {
        $collection = $this->productCollectionFactory->create()
            ->addAttributeToSelect(array_merge(
                ['name', 'description', 'short_description', 'visibility', 'status'],
                $attributeCodes
            ))
            ->addFieldToFilter('entity_id', ['in' => $batch])
            ->setStoreId($storeId);
        $categoryIds = $this->fetchCategoryIds($batch, $storeId);

        $products = [];
        $texts = [];
        foreach ($collection as $product) {
            $productId = (int)$product->getId();
            $categoryPaths = $includeCategories
                ? $this->categoryPathProvider->getPaths($categoryIds[$productId] ?? [], $storeId)
                : [];
            $text = $this->textBuilder->build(array_merge(
                [(string)$product->getName()],
                [$categoryPaths !== [] ? 'Categories: ' . implode('; ', $categoryPaths) : ''],
                $this->attributeTextProvider->getLines($product, $attributeCodes, $storeId),
                [(string)$product->getShortDescription(), (string)$product->getDescription()]
            ));
            if ($text === '') {
                continue;
            }
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

            foreach ($productIdsInOrder as $position => $productId) {
                $product = $products[$productId];
                $bulk[] = ['index' => ['_index' => $indexName, '_id' => (string)$productId]];
                // getProductStockStatus(), not getStockItem(): with MSI it returns
                // salability in the website's stock, the stock item only the default source.
                $bulk[] = $this->buildDocument(
                    $vectors[$position],
                    (int)$product->getVisibility(),
                    (int)$product->getStatus(),
                    $categoryIds[$productId] ?? [],
                    (bool)$this->stockRegistry->getProductStockStatus($productId, $websiteId),
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
     * assignments, missing anchor parents.
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
