<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model\Indexer;

use Magento\Elasticsearch\SearchAdapter\ConnectionManager;
use Magento\Elasticsearch\SearchAdapter\SearchIndexNameResolver;

/**
 * Owns the per-store vector index's lifecycle: name, create-with-mapping,
 * dimension-mismatch guard, bulk write.
 */
class VectorIndexManager
{
    private const ENTITY_TYPE = 'ai_search_vector';
    private const EMBEDDING_FIELD = 'embedding';

    public function __construct(
        private readonly ConnectionManager $connectionManager,
        private readonly SearchIndexNameResolver $indexNameResolver
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
                        self::EMBEDDING_FIELD => [
                            'type' => 'dense_vector',
                            'dims' => $dimensions,
                        ],
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
                . ' "yu_aisearchengine_vector" to switch embedding models.',
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
     * @param object $client ClientInterface, but concretely also has getMapping() (like indexExists()/createIndex()/bulkQuery() above)
     */
    private function getExistingDimensions(string $indexName, $client): ?int
    {
        $mapping = $client->getMapping(['index' => $indexName]);
        $dims = $mapping[$indexName]['mappings']['properties'][self::EMBEDDING_FIELD]['dims'] ?? null;
        return $dims !== null ? (int)$dims : null;
    }
}
