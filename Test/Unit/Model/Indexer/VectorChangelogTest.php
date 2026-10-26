<?php

declare(strict_types=1);

namespace Yu\AiSearchEngine\Test\Unit\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Mview\View\ChangelogInterface;
use Magento\Framework\Mview\ViewInterface;
use PHPUnit\Framework\TestCase;
use Yu\AiSearchEngine\Model\Indexer\VectorChangelog;

class VectorChangelogTest extends TestCase
{
    public function testAddInsertsIdsIntoTheVectorIndexerChangelogTable(): void
    {
        [$changelog, $connection] = $this->makeChangelog();
        $connection->expects($this->once())->method('insertArray')
            ->with('prefixed_yu_vector_search_cl', ['entity_id'], [1, 2]);

        $changelog->add([1, 2]);
    }

    public function testAddOfNothingWritesNothing(): void
    {
        [$changelog, $connection] = $this->makeChangelog();
        $connection->expects($this->never())->method('insertArray');

        $changelog->add([]);
    }

    /**
     * @return array{0: VectorChangelog, 1: AdapterInterface&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function makeChangelog(): array
    {
        $changelog = $this->createMock(ChangelogInterface::class);
        $changelog->method('getName')->willReturn('yu_vector_search_cl');
        $changelog->method('getColumnName')->willReturn('entity_id');
        $view = $this->createMock(ViewInterface::class);
        $view->method('getChangelog')->willReturn($changelog);
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('getView')->willReturn($view);
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->method('get')->with('yu_vector_search')->willReturn($indexer);
        $connection = $this->createMock(AdapterInterface::class);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(fn(string $name) => 'prefixed_' . $name);

        return [new VectorChangelog($registry, $resource), $connection];
    }
}
