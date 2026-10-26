<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model\Indexer;

use Magento\Elasticsearch\SearchAdapter\ConnectionManager;
use Magento\Elasticsearch\SearchAdapter\SearchIndexNameResolver;
use Yu\AiSearchEngine\Model\VectorDialect;

/**
 * Owns the per-store vector index's lifecycle: name, create-with-mapping,
 * dimension-mismatch guard, bulk write, stale-document cleanup.
 */
class VectorIndexManager
{
    private const ENTITY_TYPE = 'ai_search_vector';
    private const EMBEDDING_FIELD = 'embedding';
    private const DELETE_PAGE_SIZE = 1000;

    public function __construct(
        private readonly ConnectionManager $connectionManager,
        private readonly SearchIndexNameResolver $indexNameResolver,
        private readonly VectorDialect $vectorDialect
    ) {
    }

    public function getIndexName(int $storeId): string
    {
        return $this->indexNameResolver->getIndexName($storeId, self::ENTITY_TYPE);
    }

    /**
     * @throws \RuntimeException if the index already exists with a different embedding dimension count
     */
    public function ensureIndex(int $storeId, int $dimensions): string
    {
        $indexName = $this->getIndexName($storeId);
        $client = $this->connectionManager->getConnection();

        if (!$client->indexExists($indexName)) {
            $client->createIndex($indexName, [
                'mappings' => [
                    'properties' => [
                        self::EMBEDDING_FIELD => $this->vectorDialect->fieldMapping($dimensions),
                    ],
                ],
            ]);
            return $indexName;
        }

        $existingDims = $this->getExistingDimensions($indexName, $client);
        if ($existingDims !== null && $existingDims !== $dimensions) {
            throw new \RuntimeException(sprintf(
                'Vector index "%s" already has %d-dimension embeddings but the configured embedding'
                . ' model produces %d dimensions. Delete the index and run a full reindex of'
                . ' "yu_vector_search" to switch embedding models.',
                $indexName,
                $existingDims,
                $dimensions
            ));
        }

        return $indexName;
    }

    /**
     * @param array<int, array<string, mixed>> $operations flat action/document pairs, Elasticsearch bulk API shape
     */
    public function bulk(array $operations): void
    {
        if ($operations === []) {
            return;
        }
        $this->connectionManager->getConnection()->bulkQuery(['body' => $operations]);
    }

    /**
     * Deletes every document whose ID is not in $keepProductIds.
     * Deletes are refreshed before the next page is fetched, so a page
     * never returns IDs already deleted; a page with no progress stops
     * the loop instead of spinning on a failed bulk.
     *
     * @param int[] $keepProductIds
     */
    public function deleteAllExcept(int $storeId, array $keepProductIds): void
    {
        $indexName = $this->getIndexName($storeId);
        $client = $this->connectionManager->getConnection();
        if (!$client->indexExists($indexName)) {
            return;
        }
        $keepIds = array_map('strval', array_values($keepProductIds));
        $deleted = [];

        while (true) {
            $response = $client->query([
                'index' => $indexName,
                'body' => [
                    'size' => self::DELETE_PAGE_SIZE,
                    '_source' => false,
                    'query' => ['bool' => ['must_not' => [['ids' => ['values' => $keepIds]]]]],
                ],
            ]);
            $ids = array_column($response['hits']['hits'] ?? [], '_id');
            $ids = array_diff($ids, array_keys($deleted));
            if ($ids === []) {
                return;
            }
            $operations = [];
            foreach ($ids as $id) {
                $operations[] = ['delete' => ['_index' => $indexName, '_id' => $id]];
                $deleted[$id] = true;
            }
            $client->bulkQuery(['body' => $operations, 'refresh' => true]);
        }
    }

    /**
     * @param object $client ClientInterface, but concretely also has getMapping() (like indexExists()/createIndex()/bulkQuery() above)
     */
    private function getExistingDimensions(string $indexName, $client): ?int
    {
        $mapping = $client->getMapping(['index' => $indexName]);
        $fieldMapping = $mapping[$indexName]['mappings']['properties'][self::EMBEDDING_FIELD] ?? [];
        return $this->vectorDialect->dimensionsFromMapping($fieldMapping);
    }
}
