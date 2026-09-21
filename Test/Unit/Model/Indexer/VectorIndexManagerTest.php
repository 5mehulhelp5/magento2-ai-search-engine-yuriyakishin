<?php

declare(strict_types=1);

namespace Yu\AiSearchEngine\Test\Unit\Model\Indexer;

use Magento\AdvancedSearch\Model\Client\ClientInterface;
use Magento\Elasticsearch\SearchAdapter\ConnectionManager;
use Magento\Elasticsearch\SearchAdapter\SearchIndexNameResolver;
use PHPUnit\Framework\TestCase;
use Yu\AiSearchEngine\Model\Indexer\VectorIndexManager;

class VectorIndexManagerTest extends TestCase
{
    public function testGetIndexNameDelegatesToCoreResolverWithAiSearchVectorEntityType(): void
    {
        [$manager, , $indexResolver] = $this->makeManager();
        $indexResolver->method('getIndexName')->with(3, 'ai_search_vector')->willReturn('prefix_ai_search_vector_3');

        $this->assertSame('prefix_ai_search_vector_3', $manager->getIndexName(3));
    }

    public function testEnsureIndexCreatesIndexWithDenseVectorMappingWhenIndexDoesNotExist(): void
    {
        [$manager, $client, $indexResolver] = $this->makeManager();
        $indexResolver->method('getIndexName')->willReturn('prefix_ai_search_vector_1');
        $client->method('indexExists')->with('prefix_ai_search_vector_1')->willReturn(false);
        $client->expects($this->once())->method('createIndex')->with(
            'prefix_ai_search_vector_1',
            ['mappings' => ['properties' => ['embedding' => ['type' => 'dense_vector', 'dims' => 1536]]]]
        );

        $this->assertSame('prefix_ai_search_vector_1', $manager->ensureIndex(1, 1536));
    }

    public function testEnsureIndexDoesNothingWhenExistingIndexDimensionsMatch(): void
    {
        [$manager, $client, $indexResolver] = $this->makeManager();
        $indexResolver->method('getIndexName')->willReturn('prefix_ai_search_vector_1');
        $client->method('indexExists')->willReturn(true);
        $client->method('getMapping')->willReturn([
            'prefix_ai_search_vector_1' => ['mappings' => ['properties' => ['embedding' => ['type' => 'dense_vector', 'dims' => 1536]]]],
        ]);
        $client->expects($this->never())->method('createIndex');

        $this->assertSame('prefix_ai_search_vector_1', $manager->ensureIndex(1, 1536));
    }

    public function testEnsureIndexThrowsWhenExistingDimensionsDifferFromConfiguredModel(): void
    {
        [$manager, $client, $indexResolver] = $this->makeManager();
        $indexResolver->method('getIndexName')->willReturn('prefix_ai_search_vector_1');
        $client->method('indexExists')->willReturn(true);
        $client->method('getMapping')->willReturn([
            'prefix_ai_search_vector_1' => ['mappings' => ['properties' => ['embedding' => ['type' => 'dense_vector', 'dims' => 3072]]]],
        ]);

        $this->expectException(\RuntimeException::class);
        $manager->ensureIndex(1, 1536);
    }

    public function testEnsureIndexDoesNotThrowWhenExistingMappingHasNoEmbeddingFieldRecorded(): void
    {
        [$manager, $client, $indexResolver] = $this->makeManager();
        $indexResolver->method('getIndexName')->willReturn('prefix_ai_search_vector_1');
        $client->method('indexExists')->willReturn(true);
        $client->method('getMapping')->willReturn(['prefix_ai_search_vector_1' => ['mappings' => ['properties' => []]]]);

        $this->assertSame('prefix_ai_search_vector_1', $manager->ensureIndex(1, 1536));
    }

    public function testBulkSendsOperationsWrappedInABodyKey(): void
    {
        [$manager, $client] = $this->makeManager();
        $client->expects($this->once())->method('bulkQuery')->with(['body' => [['index' => ['_index' => 'x', '_id' => '1']], ['embedding' => [0.1]]]]);

        $manager->bulk([['index' => ['_index' => 'x', '_id' => '1']], ['embedding' => [0.1]]]);
    }

    public function testBulkDoesNothingForEmptyOperations(): void
    {
        [$manager, $client] = $this->makeManager();
        $client->expects($this->never())->method('bulkQuery');

        $manager->bulk([]);
    }

    /**
     * @return array{0: VectorIndexManager, 1: ClientInterface&\PHPUnit\Framework\MockObject\MockObject, 2: SearchIndexNameResolver&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function makeManager(): array
    {
        $client = $this->getMockBuilder(ClientInterface::class)
            ->addMethods(['indexExists', 'createIndex', 'getMapping', 'bulkQuery'])
            ->getMockForAbstractClass();
        $connectionManager = $this->createMock(ConnectionManager::class);
        $connectionManager->method('getConnection')->willReturn($client);
        $indexResolver = $this->createMock(SearchIndexNameResolver::class);

        return [new VectorIndexManager($connectionManager, $indexResolver), $client, $indexResolver];
    }
}
