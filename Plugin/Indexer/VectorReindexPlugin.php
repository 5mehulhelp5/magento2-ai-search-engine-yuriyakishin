<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Plugin\Indexer;

use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Model\AbstractModel;
use Psr\Log\LoggerInterface;
use Yu\AiSearchEngine\Model\Indexer\VectorIndexer;

/**
 * "Update on Save" for the vector indexer: a custom indexer gets no
 * per-row updates in that mode unless something triggers them, so this
 * mirrors what core does for catalogsearch_fulltext — reindex after a
 * product save/delete commits, and after mass attribute/website updates.
 * In "Update by Schedule" mode it does nothing (mview handles it).
 *
 * Skipped until the index has been fully built once (indexer status
 * valid): before that there is nothing to keep in sync, and a store that
 * hasn't configured embeddings would log an error on every product save.
 * A failure is logged, never thrown — a product save must not fail
 * because of the vector index.
 */
class VectorReindexPlugin
{
    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws \Exception
     */
    public function aroundSave(ProductResource $subject, \Closure $proceed, AbstractModel $product): ProductResource
    {
        return $this->reindexAfterCommit($subject, $proceed, $product);
    }

    /**
     * @throws \Exception
     */
    public function aroundDelete(ProductResource $subject, \Closure $proceed, AbstractModel $product): ProductResource
    {
        return $this->reindexAfterCommit($subject, $proceed, $product);
    }

    /**
     * @param int[] $productIds
     * @param array<string, mixed> $attrData
     * @param int $storeId
     */
    public function afterUpdateAttributes(
        ProductAction $subject,
        ProductAction $result,
        $productIds,
        $attrData,
        $storeId
    ): ProductAction {
        $this->reindexList((array)$productIds);
        return $result;
    }

    /**
     * @param mixed $result
     * @param int[] $productIds
     * @param int[] $websiteIds
     * @param string $type
     * @return mixed
     */
    public function afterUpdateWebsites(ProductAction $subject, $result, $productIds, $websiteIds, $type)
    {
        $this->reindexList((array)$productIds);
        return $result;
    }

    /**
     * Same transaction wrapping as core's fulltext plugin: the callback is
     * registered inside the save's transaction, so it runs after the
     * outermost commit and sees the committed data.
     *
     * @throws \Exception
     */
    private function reindexAfterCommit(ProductResource $subject, \Closure $proceed, AbstractModel $product): ProductResource
    {
        try {
            $subject->beginTransaction();
            $result = $proceed($product);
            $subject->addCommitCallback(function () use ($product): void {
                $this->reindexList([(int)$product->getId()]);
            });
            $subject->commit();
        } catch (\Exception $e) {
            $subject->rollBack();
            throw $e;
        }
        return $result;
    }

    /**
     * @param array<int, int|string> $productIds
     */
    private function reindexList(array $productIds): void
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($productIds === []) {
            return;
        }
        try {
            $indexer = $this->indexerRegistry->get(VectorIndexer::INDEXER_ID);
            if ($indexer->isScheduled() || !$indexer->isValid()) {
                return;
            }
            $indexer->reindexList($productIds);
        } catch (\Throwable $e) {
            $this->logger->error(
                'AI Search Vector indexer: update on save failed for product(s) '
                . implode(',', $productIds) . ': ' . $e->getMessage()
            );
        }
    }
}
