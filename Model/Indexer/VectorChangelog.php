<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\IndexerRegistry;

/**
 * Queues product IDs for the vector indexer's next "Update by Schedule"
 * run. Written directly: ChangelogInterface has no addList() on every
 * supported Magento version.
 */
class VectorChangelog
{
    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @param int[] $productIds
     */
    public function add(array $productIds): void
    {
        if ($productIds === []) {
            return;
        }
        $changelog = $this->indexerRegistry->get(VectorIndexer::INDEXER_ID)->getView()->getChangelog();
        $this->resourceConnection->getConnection()->insertArray(
            $this->resourceConnection->getTableName($changelog->getName()),
            [$changelog->getColumnName()],
            $productIds
        );
    }
}
