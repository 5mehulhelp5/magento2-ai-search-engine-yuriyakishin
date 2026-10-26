<?php

declare(strict_types=1);

namespace Yu\AiSearchEngine\Test\Unit\Model\Indexer;

use Magento\AdvancedSearch\Model\Client\ClientInterface;
use Magento\Elasticsearch\SearchAdapter\ConnectionManager;
use Magento\Elasticsearch\SearchAdapter\SearchIndexNameResolver;
use Magento\Framework\Search\EngineResolverInterface;
use PHPUnit\Framework\TestCase;
use Yu\AiSearchEngine\Model\Indexer\VectorIndexManager;
use Yu\AiSearchEngine\Model\VectorDialect;

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

    public function testEnsureIndexCreatesIndexWithKnnVectorMappingOnOpenSearch(): void
    {
        [$manager, $client, $indexResolver] = $this->makeManager('opensearch');
        $indexResolver->method('getIndexName')->willReturn('prefix_ai_search_vector_1');
        $client->method('indexExists')->with('prefix_ai_search_vector_1')->willReturn(false);
        $client->expects($this->once())->method('createIndex')->with(
            'prefix_ai_search_vector_1',
            ['mappings' => ['properties' => ['embedding' => ['type' => 'knn_vector', 'dimension' => 1536]]]]
        );

        $this->assertSame('prefix_ai_search_vector_1', $manager->ensureIndex(1, 1536));
    }

    public function testEnsureIndexThrowsWhenExistingOpenSearchDimensionsDifferFromConfiguredModel(): void
    {
        [$manager, $client, $indexResolver] = $this->makeManager('opensearch');
        $indexResolver->method('getIndexName')->willReturn('prefix_ai_search_vector_1');
        $client->method('indexExists')->willReturn(true);
        $client->method('getMapping')->willReturn([
            'prefix_ai_search_vector_1' => ['mappings' => ['properties' => ['embedding' => ['type' => 'knn_vector', 'dimension' => 3072]]]],
        ]);

        $this->expectException(\RuntimeException::class);
        $manager->ensureIndex(1, 1536);
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

    public function testBulkThrowsWhenTheEngineRejectsDocuments(): void
    {
        [$manager, $client] = $this->makeManager();
        $client->method('bulkQuery')->willReturn([
            'errors' => true,
            'items' => [
                ['index' => ['_id' => '1', 'status' => 201]],
                ['index' => ['_id' => '2', 'status' => 400, 'error' => ['reason' => 'dimension mismatch']]],
            ],
        ]);

        $this->expectExceptionMessage('2: dimension mismatch');
        $manager->bulk([['index' => ['_index' => 'x', '_id' => '2']], ['embedding' => [0.1]]]);
    }

    public function testBulkIgnoresDeletesOfDocumentsAlreadyGone(): void
    {
        [$manager, $client] = $this->makeManager();
        $client->method('bulkQuery')->willReturn([
            'errors' => true,
            'items' => [['delete' => ['_id' => '3', 'status' => 404, 'error' => ['reason' => 'not_found']]]],
        ]);

        $manager->bulk([['delete' => ['_index' => 'x', '_id' => '3']]]);
        $this->addToAssertionCount(1);
    }

    public function testBulkDoesNothingForEmptyOperations(): void
    {
        [$manager, $client] = $this->makeManager();
        $client->expects($this->never())->method('bulkQuery');

        $manager->bulk([]);
    }

    public function testDeleteAllExceptDeletesEveryDocumentOutsideTheKeptIds(): void
    {
        [$manager, $client, $indexResolver] = $this->makeManager();
        $indexResolver->method('getIndexName')->willReturn('prefix_ai_search_vector_1');
        $client->method('indexExists')->willReturn(true);
        $client->expects($this->exactly(2))->method('query')
            ->with($this->callback(function (array $query): bool {
                return $query['index'] === 'prefix_ai_search_vector_1'
                    && $query['body']['_source'] === false
                    && $query['body']['query'] === ['bool' => ['must_not' => [['ids' => ['values' => ['1', '2']]]]]];
            }))
            ->willReturnOnConsecutiveCalls(
                ['hits' => ['hits' => [['_id' => '5'], ['_id' => '9']]]],
                ['hits' => ['hits' => []]]
            );
        $client->expects($this->once())->method('bulkQuery')->with([
            'body' => [
                ['delete' => ['_index' => 'prefix_ai_search_vector_1', '_id' => '5']],
                ['delete' => ['_index' => 'prefix_ai_search_vector_1', '_id' => '9']],
            ],
            'refresh' => true,
        ]);

        $manager->deleteAllExcept(1, [1, 2]);
    }

    public function testDeleteAllExceptStopsWhenDeletedDocumentsKeepComingBack(): void
    {
        [$manager, $client, $indexResolver] = $this->makeManager();
        $indexResolver->method('getIndexName')->willReturn('prefix_ai_search_vector_1');
        $client->method('indexExists')->willReturn(true);
        $client->expects($this->exactly(2))->method('query')
            ->willReturn(['hits' => ['hits' => [['_id' => '5']]]]);
        $client->expects($this->once())->method('bulkQuery');

        $manager->deleteAllExcept(1, [1]);
    }

    public function testDeleteAllExceptDoesNothingWhenTheIndexDoesNotExist(): void
    {
        [$manager, $client, $indexResolver] = $this->makeManager();
        $indexResolver->method('getIndexName')->willReturn('prefix_ai_search_vector_1');
        $client->method('indexExists')->willReturn(false);
        $client->expects($this->never())->method('query');
        $client->expects($this->never())->method('bulkQuery');

        $manager->deleteAllExcept(1, [1]);
    }

    /**
     * @return array{0: VectorIndexManager, 1: ClientInterface&\PHPUnit\Framework\MockObject\MockObject, 2: SearchIndexNameResolver&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function makeManager(string $engine = 'elasticsearch7'): array
    {
        $client = $this->getMockBuilder(ClientInterface::class)
            ->addMethods(['indexExists', 'createIndex', 'getMapping', 'bulkQuery', 'query'])
            ->getMockForAbstractClass();
        $connectionManager = $this->createMock(ConnectionManager::class);
        $connectionManager->method('getConnection')->willReturn($client);
        $indexResolver = $this->createMock(SearchIndexNameResolver::class);

        $engineResolver = $this->createMock(EngineResolverInterface::class);
        $engineResolver->method('getCurrentSearchEngine')->willReturn($engine);

        return [
            new VectorIndexManager($connectionManager, $indexResolver, new VectorDialect($engineResolver)),
            $client,
            $indexResolver,
        ];
    }
}
