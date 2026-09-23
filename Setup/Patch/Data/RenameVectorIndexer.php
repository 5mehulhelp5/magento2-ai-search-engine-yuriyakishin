<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Setup\Patch\Data;

use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Mview\View\StateInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Yu\AiSearchEngine\Model\Indexer\VectorIndexer;

/**
 * Moves an install from the vector indexer's former ID
 * ("yu_aisearchengine_vector") to the current one, keeping its state:
 *
 * - "Update by Schedule" stays on. Switching the new indexer to it
 *   rebuilds the triggers on the product tables, which are shared by
 *   every scheduled indexer, from the views declared now — the former
 *   view no longer is, so its statements drop out. Without that, the
 *   triggers would keep writing to a changelog table nobody reads (and
 *   fail once it is gone).
 * - A valid index stays valid: the search-engine index itself is named
 *   independently of the indexer ID, so the vectors already built remain
 *   usable and no reindex is needed.
 *
 * The former ID's state rows and changelog table are removed. Does
 * nothing on an install that never had the former ID.
 */
class RenameVectorIndexer implements DataPatchInterface
{
    private const LEGACY_ID = 'yu_aisearchengine_vector';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly IndexerRegistry $indexerRegistry
    ) {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $indexerStateTable = $this->moduleDataSetup->getTable('indexer_state');
        $mviewStateTable = $this->moduleDataSetup->getTable('mview_state');

        $legacyStatus = $connection->fetchOne(
            $connection->select()->from($indexerStateTable, ['status'])->where('indexer_id = ?', self::LEGACY_ID)
        );
        $legacyMode = $connection->fetchOne(
            $connection->select()->from($mviewStateTable, ['mode'])->where('view_id = ?', self::LEGACY_ID)
        );
        $legacyChangelog = $this->moduleDataSetup->getTable(self::LEGACY_ID . '_cl');
        $hasLegacyChangelog = $connection->isTableExists($legacyChangelog);
        if ($legacyStatus === false && $legacyMode === false && !$hasLegacyChangelog) {
            return $this;
        }

        $connection->delete($indexerStateTable, ['indexer_id = ?' => self::LEGACY_ID]);
        $connection->delete($mviewStateTable, ['view_id = ?' => self::LEGACY_ID]);

        $indexer = $this->indexerRegistry->get(VectorIndexer::INDEXER_ID);
        if ($legacyMode === StateInterface::MODE_ENABLED) {
            $indexer->setScheduled(true);
        }
        if ($hasLegacyChangelog) {
            $connection->dropTable($legacyChangelog);
        }
        if ($legacyStatus !== false) {
            $indexer->getState()->setStatus((string)$legacyStatus)->save();
        }
        return $this;
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
