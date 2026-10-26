<?php

declare(strict_types=1);

namespace Yu\AiSearchEngine\Test\Unit\Plugin\Indexer;

use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Mview\View\ChangelogInterface;
use Magento\Framework\Mview\ViewInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Yu\AiSearchEngine\Plugin\Indexer\VectorReindexPlugin;

class VectorReindexPluginTest extends TestCase
{
    public function testFulltextListReindexIsFollowedOnSaveMode(): void
    {
        $indexer = $this->makeIndexer(scheduled: false, valid: true);
        $indexer->expects($this->once())->method('reindexList')->with([1, 2]);
        [$plugin] = $this->makePlugin($indexer);

        $this->assertNull($plugin->afterExecuteList($this->makeFulltext(), null, [1, '2', 2]));
    }

    public function testFulltextRowReindexIsFollowedOnSaveMode(): void
    {
        $indexer = $this->makeIndexer(scheduled: false, valid: true);
        $indexer->expects($this->once())->method('reindexList')->with([42]);
        [$plugin] = $this->makePlugin($indexer);

        $plugin->afterExecuteRow($this->makeFulltext(), null, '42');
    }

    public function testFulltextExecuteIsFollowedOnSaveMode(): void
    {
        $indexer = $this->makeIndexer(scheduled: false, valid: true);
        $indexer->expects($this->once())->method('reindexList')->with([7]);
        [$plugin] = $this->makePlugin($indexer);

        $plugin->afterExecute($this->makeFulltext(), null, [7]);
    }

    public function testScheduledModeWritesIdsToTheVectorChangelogInsteadOfReindexing(): void
    {
        $indexer = $this->makeIndexer(scheduled: true, valid: true);
        $indexer->expects($this->never())->method('reindexList');
        $changelog = $this->createMock(ChangelogInterface::class);
        $changelog->method('getName')->willReturn('yu_vector_search_cl');
        $changelog->method('getColumnName')->willReturn('entity_id');
        $view = $this->createMock(ViewInterface::class);
        $view->method('getChangelog')->willReturn($changelog);
        $indexer->method('getView')->willReturn($view);
        [$plugin, , $connection] = $this->makePlugin($indexer);
        $connection->expects($this->once())->method('insertArray')
            ->with('prefixed_yu_vector_search_cl', ['entity_id'], [1, 2]);

        $plugin->afterExecuteList($this->makeFulltext(), null, [1, 2]);
    }

    public function testNotReindexedBeforeTheFirstFullReindex(): void
    {
        $indexer = $this->makeIndexer(scheduled: false, valid: false);
        $indexer->expects($this->never())->method('reindexList');
        [$plugin] = $this->makePlugin($indexer);

        $plugin->afterExecuteList($this->makeFulltext(), null, [1]);
    }

    public function testEmptyIdListDoesNothing(): void
    {
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->expects($this->never())->method('get');
        $plugin = new VectorReindexPlugin(
            $registry,
            $this->createMock(ResourceConnection::class),
            $this->createMock(LoggerInterface::class)
        );

        $plugin->afterExecuteList($this->makeFulltext(), null, []);
    }

    public function testIndexerFailureIsLoggedNotThrown(): void
    {
        $indexer = $this->makeIndexer(scheduled: false, valid: true);
        $indexer->method('reindexList')->willThrowException(new \RuntimeException('search engine down'));
        [$plugin, $logger] = $this->makePlugin($indexer);
        $logger->expects($this->once())->method('error')->with($this->stringContains('search engine down'));

        $this->assertSame('result', $plugin->afterExecuteList($this->makeFulltext(), 'result', [1]));
    }

    /**
     * @return IndexerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeIndexer(bool $scheduled, bool $valid): IndexerInterface
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('isScheduled')->willReturn($scheduled);
        $indexer->method('isValid')->willReturn($valid);
        return $indexer;
    }

    /**
     * @return array{
     *     0: VectorReindexPlugin,
     *     1: LoggerInterface&\PHPUnit\Framework\MockObject\MockObject,
     *     2: AdapterInterface&\PHPUnit\Framework\MockObject\MockObject
     * }
     */
    private function makePlugin(IndexerInterface $indexer): array
    {
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->method('get')->with('yu_vector_search')->willReturn($indexer);
        $connection = $this->createMock(AdapterInterface::class);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(fn(string $name) => 'prefixed_' . $name);
        $logger = $this->createMock(LoggerInterface::class);
        return [new VectorReindexPlugin($registry, $resource, $logger), $logger, $connection];
    }

    private function makeFulltext(): Fulltext
    {
        return $this->createMock(Fulltext::class);
    }
}
