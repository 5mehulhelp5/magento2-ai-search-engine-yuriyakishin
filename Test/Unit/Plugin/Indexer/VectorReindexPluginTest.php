<?php

declare(strict_types=1);

namespace Yu\AiSearchEngine\Test\Unit\Plugin\Indexer;

use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Yu\AiSearchEngine\Model\Indexer\VectorChangelog;
use Yu\AiSearchEngine\Plugin\Indexer\VectorReindexPlugin;

class VectorReindexPluginTest extends TestCase
{
    public function testOnlyExecuteIsIntercepted(): void
    {
        // executeList()/executeRow() reach execute() through the
        // interceptor; a plugin on them too would reindex the same IDs twice.
        $methods = array_filter(
            get_class_methods(VectorReindexPlugin::class),
            static fn(string $method): bool => (bool)preg_match('/^(before|around|after)/', $method)
        );

        $this->assertSame(['afterExecute'], array_values($methods));
    }

    public function testFulltextExecuteIsFollowedOnSaveMode(): void
    {
        $indexer = $this->makeIndexer(scheduled: false, valid: true);
        $indexer->expects($this->once())->method('reindexList')->with([1, 2]);
        [$plugin] = $this->makePlugin($indexer);

        $this->assertNull($plugin->afterExecute($this->makeFulltext(), null, [1, '2', 2]));
    }

    public function testScheduledModeWritesIdsToTheVectorChangelogInsteadOfReindexing(): void
    {
        $indexer = $this->makeIndexer(scheduled: true, valid: true);
        $indexer->expects($this->never())->method('reindexList');
        [$plugin, , $changelog] = $this->makePlugin($indexer);
        $changelog->expects($this->once())->method('add')->with([1, 2]);

        $plugin->afterExecute($this->makeFulltext(), null, [1, 2]);
    }

    public function testNotReindexedBeforeTheFirstFullReindex(): void
    {
        $indexer = $this->makeIndexer(scheduled: false, valid: false);
        $indexer->expects($this->never())->method('reindexList');
        [$plugin] = $this->makePlugin($indexer);

        $plugin->afterExecute($this->makeFulltext(), null, [1]);
    }

    public function testEmptyIdListDoesNothing(): void
    {
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->expects($this->never())->method('get');
        $plugin = new VectorReindexPlugin(
            $registry,
            $this->createMock(VectorChangelog::class),
            $this->createMock(LoggerInterface::class)
        );

        $plugin->afterExecute($this->makeFulltext(), null, []);
    }

    public function testIndexerFailureIsLoggedNotThrown(): void
    {
        $indexer = $this->makeIndexer(scheduled: false, valid: true);
        $indexer->method('reindexList')->willThrowException(new \RuntimeException('search engine down'));
        [$plugin, $logger] = $this->makePlugin($indexer);
        $logger->expects($this->once())->method('error')->with($this->stringContains('search engine down'));

        $this->assertSame('result', $plugin->afterExecute($this->makeFulltext(), 'result', [1]));
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
     *     2: VectorChangelog&\PHPUnit\Framework\MockObject\MockObject
     * }
     */
    private function makePlugin(IndexerInterface $indexer): array
    {
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->method('get')->with('yu_vector_search')->willReturn($indexer);
        $changelog = $this->createMock(VectorChangelog::class);
        $logger = $this->createMock(LoggerInterface::class);
        return [new VectorReindexPlugin($registry, $changelog, $logger), $logger, $changelog];
    }

    private function makeFulltext(): Fulltext
    {
        return $this->createMock(Fulltext::class);
    }
}
