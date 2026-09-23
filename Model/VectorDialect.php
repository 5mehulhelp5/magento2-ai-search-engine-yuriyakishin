<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model;

use Magento\Framework\Search\EngineResolverInterface;

/**
 * Engine-specific vector syntax. Elasticsearch and OpenSearch diverged on
 * vectors: Elasticsearch uses `dense_vector` (`dims`) scored by painless
 * `cosineSimilarity()`; OpenSearch has neither and uses the k-NN plugin's
 * `knn_vector` (`dimension`) scored by the `knn_score` script. Both
 * scripts return cosine similarity + 1, i.e. a score in [0,2].
 *
 * OpenSearch indexes are created without `index.knn` on purpose: exact
 * script scoring doesn't need it, and enabling it would build HNSW graphs
 * in native memory that nothing here queries.
 */
class VectorDialect
{
    public function __construct(
        private readonly EngineResolverInterface $engineResolver
    ) {
    }

    public function isOpenSearch(): bool
    {
        return str_contains(strtolower((string)$this->engineResolver->getCurrentSearchEngine()), 'opensearch');
    }

    /**
     * @return array<string, mixed> field mapping for the embedding field
     */
    public function fieldMapping(int $dimensions): array
    {
        return $this->isOpenSearch()
            ? ['type' => 'knn_vector', 'dimension' => $dimensions]
            : ['type' => 'dense_vector', 'dims' => $dimensions];
    }

    /**
     * Reads both dialects, so an index built under the other engine is
     * still recognised.
     *
     * @param array<string, mixed> $fieldMapping
     */
    public function dimensionsFromMapping(array $fieldMapping): ?int
    {
        $dims = $fieldMapping['dimension'] ?? $fieldMapping['dims'] ?? null;
        return $dims !== null ? (int)$dims : null;
    }

    /**
     * @param float[] $queryVector
     * @return array<string, mixed> `script` part of a script_score query
     */
    public function cosineScoreScript(string $field, array $queryVector): array
    {
        if ($this->isOpenSearch()) {
            return [
                'lang' => 'knn',
                'source' => 'knn_score',
                'params' => [
                    'field' => $field,
                    'query_value' => $queryVector,
                    'space_type' => 'cosinesimil',
                ],
            ];
        }
        return [
            // +1.0: ES requires score >= 0; range becomes [0,2].
            'source' => "cosineSimilarity(params.query_vector, '" . $field . "') + 1.0",
            'params' => ['query_vector' => $queryVector],
        ];
    }
}
