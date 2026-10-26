<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Plugin\Indexer;

use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Mview\View\ChangelogInterface;
use Psr\Log\LoggerInterface;
use Yu\AiSearchEngine\Model\Indexer\VectorIndexer;

/**
 * Follows every partial reindex of catalogsearch_fulltext: core already
 * routes product save/delete, mass actions, category assignment, price
 * and stock/salability changes (legacy and MSI, in both indexer modes)
 * there, so the vector index sees the same product IDs. Full fulltext
 * reindexes don't go through execute() and are not followed.
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
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * All three are intercepted: executeList()/executeRow() call execute()
     * internally, which a plugin doesn't see.
     *
     * @param mixed $result
     * @param int[] $entityIds
     * @return mixed
     */
    public function afterExecute(Fulltext $subject, $result, $entityIds)
    {
        $this->follow((array)$entityIds);
        return $result;
    }

    /**
     * @param mixed $result
     * @param int[] $ids
     * @return mixed
     */
    public function afterExecuteList(Fulltext $subject, $result, array $ids)
    {
        $this->follow($ids);
        return $result;
    }

    /**
     * @param mixed $result
     * @param int $id
     * @return mixed
     */
    public function afterExecuteRow(Fulltext $subject, $result, $id)
    {
        $this->follow([$id]);
        return $result;
    }

    /**
     * @param array<int, int|string> $entityIds
     */
    private function follow(array $entityIds): void
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $entityIds))));
        if ($productIds === []) {
            return;
        }
        try {
            $indexer = $this->indexerRegistry->get(VectorIndexer::INDEXER_ID);
            if ($indexer->isScheduled()) {
                $this->addToChangelog($indexer->getView()->getChangelog(), $productIds);
            } elseif ($indexer->isValid()) {
                $indexer->reindexList($productIds);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'AI Search Vector indexer: update failed for product(s) '
                . implode(',', $productIds) . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * ChangelogInterface has no addList() on every supported Magento version.
     *
     * @param int[] $productIds
     */
    private function addToChangelog(ChangelogInterface $changelog, array $productIds): void
    {
        $this->resourceConnection->getConnection()->insertArray(
            $this->resourceConnection->getTableName($changelog->getName()),
            [$changelog->getColumnName()],
            $productIds
        );
    }
}
