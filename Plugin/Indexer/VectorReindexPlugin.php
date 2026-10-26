<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Plugin\Indexer;

use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\Indexer\IndexerRegistry;
use Psr\Log\LoggerInterface;
use Yu\AiSearchEngine\Model\Indexer\VectorChangelog;
use Yu\AiSearchEngine\Model\Indexer\VectorIndexer;

/**
 * Follows every partial reindex of catalogsearch_fulltext: core already
 * routes product save/delete, mass actions, category assignment, price
 * and stock/salability changes (legacy and MSI, in both indexer modes)
 * there, so the vector index sees the same product IDs. Full fulltext
 * reindexes don't go through execute() and are not followed.
 *
 * Only execute() is intercepted: executeList()/executeRow() call it via
 * $this, which is the interceptor, so a plugin on them too would fire
 * twice for the same IDs.
 *
 * Vector indexer "Update by Schedule": IDs go to its changelog for its
 * own cron run. "Update on Save": reindexed now, but only once the index
 * has been fully built (status valid) — before that there is nothing to
 * keep in sync, and a store without embeddings configured would log an
 * error on every product save. A failure is logged, never thrown — a
 * product save must not fail because of the vector index.
 */
class VectorReindexPlugin
{
    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly VectorChangelog $vectorChangelog,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param mixed $result
     * @param int[] $entityIds
     * @return mixed
     */
    public function afterExecute(Fulltext $subject, $result, $entityIds)
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', (array)$entityIds))));
        if ($productIds === []) {
            return $result;
        }
        try {
            $indexer = $this->indexerRegistry->get(VectorIndexer::INDEXER_ID);
            if ($indexer->isScheduled()) {
                $this->vectorChangelog->add($productIds);
            } elseif ($indexer->isValid()) {
                $indexer->reindexList($productIds);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'AI Search Vector indexer: update failed for product(s) '
                . implode(',', $productIds) . ': ' . $e->getMessage()
            );
        }
        return $result;
    }
}
