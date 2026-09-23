<?php

declare(strict_types=1);

namespace Yu\AiSearchEngine\Test\Unit\Plugin\Indexer;

use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Model\AbstractModel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Yu\AiSearchEngine\Plugin\Indexer\VectorReindexPlugin;

class VectorReindexPluginTest extends TestCase
{
    public function testSaveReindexesTheProductAfterCommit(): void
    {
        $indexer = $this->makeIndexer(scheduled: false, valid: true);
        $indexer->expects($this->once())->method('reindexList')->with([42]);
        [$plugin] = $this->makePlugin($indexer);
        $resource = $this->makeResourceRunningCommitCallbacks();

        $result = $plugin->aroundSave($resource, fn() => $resource, $this->makeProduct(42));

        $this->assertSame($resource, $result);
    }

    public function testDeleteReindexesTheProductAfterCommitSoItIsRemovedFromTheIndex(): void
    {
        $indexer = $this->makeIndexer(scheduled: false, valid: true);
        $indexer->expects($this->once())->method('reindexList')->with([42]);
        [$plugin] = $this->makePlugin($indexer);
        $resource = $this->makeResourceRunningCommitCallbacks();

        $plugin->aroundDelete($resource, fn() => $resource, $this->makeProduct(42));
    }

    public function testNothingIsReindexedWhenTheSaveFailsAndTheErrorIsRethrown(): void
    {
        $indexer = $this->makeIndexer(scheduled: false, valid: true);
        $indexer->expects($this->never())->method('reindexList');
        [$plugin] = $this->makePlugin($indexer);
        $resource = $this->createMock(ProductResource::class);
        $resource->expects($this->once())->method('rollBack');
        $resource->expects($this->never())->method('commit');

        $this->expectException(\RuntimeException::class);
        $plugin->aroundSave($resource, function () {
            throw new \RuntimeException('save failed');
        }, $this->makeProduct(42));
    }

    public function testScheduledModeLeavesUpdatesToMview(): void
    {
        $indexer = $this->makeIndexer(scheduled: true, valid: true);
        $indexer->expects($this->never())->method('reindexList');
        [$plugin] = $this->makePlugin($indexer);
        $action = $this->createMock(ProductAction::class);

        $plugin->afterUpdateAttributes($action, $action, [1, 2], [], 0);
    }

    public function testNotReindexedBeforeTheFirstFullReindex(): void
    {
        $indexer = $this->makeIndexer(scheduled: false, valid: false);
        $indexer->expects($this->never())->method('reindexList');
        [$plugin] = $this->makePlugin($indexer);
        $action = $this->createMock(ProductAction::class);

        $plugin->afterUpdateAttributes($action, $action, [1], [], 0);
    }

    public function testMassActionsReindexTheAffectedProductsOnce(): void
    {
        $indexer = $this->makeIndexer(scheduled: false, valid: true);
        $indexer->expects($this->exactly(2))->method('reindexList')->with([1, 2]);
        [$plugin] = $this->makePlugin($indexer);
        $action = $this->createMock(ProductAction::class);

        $this->assertSame($action, $plugin->afterUpdateAttributes($action, $action, [1, 2, 2], [], 0));
        $this->assertNull($plugin->afterUpdateWebsites($action, null, ['1', '2'], [1], 'add'));
    }

    public function testIndexerFailureIsLoggedNotThrown(): void
    {
        $indexer = $this->makeIndexer(scheduled: false, valid: true);
        $indexer->method('reindexList')->willThrowException(new \RuntimeException('search engine down'));
        [$plugin, $logger] = $this->makePlugin($indexer);
        $logger->expects($this->once())->method('error')->with($this->stringContains('search engine down'));
        $action = $this->createMock(ProductAction::class);

        $plugin->afterUpdateAttributes($action, $action, [1], [], 0);
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
     * @return array{0: VectorReindexPlugin, 1: LoggerInterface&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function makePlugin(IndexerInterface $indexer): array
    {
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->method('get')->with('yu_vector_search')->willReturn($indexer);
        $logger = $this->createMock(LoggerInterface::class);
        return [new VectorReindexPlugin($registry, $logger), $logger];
    }

    /**
     * @return ProductResource&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeResourceRunningCommitCallbacks(): ProductResource
    {
        $callbacks = [];
        $resource = $this->createMock(ProductResource::class);
        $resource->method('addCommitCallback')->willReturnCallback(
            function (callable $callback) use (&$callbacks, &$resource) {
                $callbacks[] = $callback;
                return $resource;
            }
        );
        $resource->method('commit')->willReturnCallback(function () use (&$callbacks, &$resource) {
            foreach ($callbacks as $callback) {
                $callback();
            }
            return $resource;
        });
        return $resource;
    }

    private function makeProduct(int $id): AbstractModel
    {
        $product = $this->createMock(AbstractModel::class);
        $product->method('getId')->willReturn($id);
        return $product;
    }
}
