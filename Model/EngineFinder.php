<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model;

use Magento\Elasticsearch\SearchAdapter\ConnectionManager;
use Magento\Elasticsearch\SearchAdapter\SearchIndexNameResolver;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Yu\AiLlm\Api\EmbeddingProviderInterface;
use Yu\AiLlm\Model\LlmProviderException;
use Yu\AiSearchEngine\Api\Data\QueryOptionsInterface;
use Yu\AiSearchEngine\Api\Data\SearchResultInterface;
use Yu\AiSearchEngine\Api\Data\SearchResultInterfaceFactory;
use Yu\AiSearchEngine\Model\Indexer\VectorIndexManager;

/**
 * Structured search description -> ordered product IDs (+ optional facet
 * counts), straight against the search engine. Bypasses Magento's
 * query-builder layer (returns empty outside storefront rendering in
 * this env); connection, engine choice and index name still come from
 * standard Magento configuration. The index name is resolved on every
 * call: the prefix is merchant-editable, never cache it. Only
 * match/multi_match/range/term/terms/percentiles clauses may appear in
 * the body — callers pass data, never DSL.
 */
class EngineFinder
{
    private const VISIBILITY_IN_SEARCH = [3, 4];
    private const STATUS_ENABLED = 1;
    /**
     * With attribute terms, vector hits are post-filtered (see
     * keepTermMatches()), so the vector query fetches a wider candidate
     * pool to still fill the page after non-matching hits are dropped.
     */
    private const VECTOR_CANDIDATE_FACTOR = 5;
    private const MAX_VECTOR_CANDIDATES = 500;
    /**
     * Values returned per facet field, most frequent first. Generous
     * because category facets list every anchor parent too, and those
     * (the most frequent, least useful) must not crowd out the rest.
     */
    private const FACET_BUCKET_LIMIT = 20;
    /**
     * Query vectors are cached: the embedding API is the slowest step of
     * a search, and a cached vector makes the same query return the same
     * products on every request (pagination, refinements) instead of
     * depending on tiny run-to-run differences in the API's output.
     */
    private const QUERY_VECTOR_CACHE_PREFIX = 'yu_aisearchengine_query_vector_';
    private const QUERY_VECTOR_CACHE_LIFETIME = 86400;

    public function __construct(
        private readonly ConnectionManager $connectionManager,
        private readonly SearchIndexNameResolver $indexNameResolver,
        private readonly SearchResultInterfaceFactory $searchResultFactory,
        private readonly SemanticConfig $semanticConfig,
        private readonly EmbeddingProviderInterface $embeddingProvider,
        private readonly VectorIndexManager $vectorIndexManager,
        private readonly VectorDialect $vectorDialect,
        private readonly CacheInterface $cache,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Simple mode: one query string across all whitelisted fields, blended
     * with a semantic (vector) match when enabled (see hybridMerge()).
     *
     * @param string $query
     * @param array<string, int> $fieldBoosts es_field => boost
     * @param QueryOptionsInterface $options
     * @return int[]
     */
    public function findByQuery(string $query, array $fieldBoosts, QueryOptionsInterface $options): array
    {
        $bool = ['must' => [$this->multiMatchClause($query, $fieldBoosts)]];
        $keywordResponse = $this->run($bool, $options);
        if ($query === '' || !$this->semanticConfig->isEnabled()) {
            return $this->extractIds($keywordResponse);
        }
        return $this->hybridMerge($query, [], $keywordResponse, $options);
    }

    /**
     * Targeted mode: per-attribute match clauses. Positive terms are
     * AND-combined — the caller named these properties, every one must
     * hold ("red yoga pants" must not return non-red pants). Excluded
     * terms go to must_not. An exclusion-only call is valid: it means
     * "everything except".
     *
     * @param array<int, array{field: string, query: string, boost: float, exclude: bool, required?: bool}> $terms
     * @param QueryOptionsInterface $options
     * @return int[]
     */
    public function findByTerms(array $terms, QueryOptionsInterface $options): array
    {
        return $this->extractIds($this->run($this->termsClause($terms), $options));
    }

    /**
     * Combined mode: free-text keywords narrowed by structured per-
     * attribute terms in the same query, optionally with facets (up to
     * 20 most frequent values + counts per field in $facetFields) and
     * prices of exactly the returned products — one extra query, see
     * describe().
     *
     * @param string $query empty string = keywords not applied
     * @param array<string, int> $fieldBoosts es_field => boost, used only when $query is non-empty
     * @param array<int, array{field: string, query: string, boost: float, exclude: bool, required?: bool}> $terms
     * @param QueryOptionsInterface $options
     * @param array<string, string> $facetFields attribute code => aggregation es_field; empty = no aggregations requested
     * @return SearchResultInterface
     */
    public function search(
        string $query,
        array $fieldBoosts,
        array $terms,
        QueryOptionsInterface $options,
        array $facetFields = []
    ): SearchResultInterface {
        $bool = $this->termsClause($terms);
        if ($query !== '') {
            $bool['must'] = array_merge($bool['must'] ?? [], [$this->multiMatchClause($query, $fieldBoosts)]);
        }
        $response = $this->run($bool, $options);
        $productIds = $this->extractIds($response);
        if ($query !== '' && $this->semanticConfig->isEnabled()) {
            $productIds = $this->hybridMerge($query, $this->termsClause($terms), $response, $options);
        }
        return $this->describe($productIds, $options, $facetFields);
    }

    /**
     * Narrows an already-found, ordered product list by exact values —
     * the same fields facets count on, so a facet count is precisely how
     * many products the matching refinement keeps. Order is preserved;
     * nothing outside $productIds can appear. Also applies the options'
     * category/price/stock filters (e.g. a category or price refinement).
     *
     * @param int[] $productIds
     * @param array<string, int|string|array<int, int|string>> $exactFilters es_field => value (e.g. an attribute's
     *     aggregation field => option ID); a list of values means every one of them must be present
     * @param QueryOptionsInterface $options
     * @param array<string, string> $facetFields attribute code => aggregation es_field; empty = no aggregations requested
     * @return SearchResultInterface
     */
    public function refine(
        array $productIds,
        array $exactFilters,
        QueryOptionsInterface $options,
        array $facetFields = []
    ): SearchResultInterface {
        if ($productIds === []) {
            return $this->describe([], $options, $facetFields);
        }
        $filter = $this->buildFilter($options);
        $filter[] = ['ids' => ['values' => array_map('strval', $productIds)]];
        foreach ($exactFilters as $field => $values) {
            foreach ((array)$values as $value) {
                $filter[] = ['term' => [$field => $value]];
            }
        }
        $response = $this->connectionManager->getConnection()->query([
            'index' => $this->indexNameResolver->getIndexName($options->getStoreId(), 'catalogsearch_fulltext'),
            'body' => [
                'size' => count($productIds),
                '_source' => false,
                'query' => ['bool' => ['filter' => $filter]],
            ],
        ]);
        $matching = array_flip($this->extractIds($response));
        $kept = array_values(array_filter($productIds, static fn(int $id): bool => isset($matching[$id])));
        return $this->describe($kept, $options, $facetFields);
    }

    /**
     * Facets, prices and count of exactly the given products — what the
     * shopper sees, so every count matches what a refinement leaves.
     * Facet failure degrades to a result without facets, never throws.
     *
     * @param int[] $productIds
     * @param array<string, string> $facetFields
     */
    private function describe(array $productIds, QueryOptionsInterface $options, array $facetFields): SearchResultInterface
    {
        $data = ['productIds' => $productIds, 'totalCount' => count($productIds)];
        if ($facetFields === [] || $productIds === []) {
            return $this->searchResultFactory->create($data);
        }
        $priceField = $this->priceField($options);
        try {
            $response = $this->connectionManager->getConnection()->query([
                'index' => $this->indexNameResolver->getIndexName($options->getStoreId(), 'catalogsearch_fulltext'),
                'body' => [
                    'size' => count($productIds),
                    '_source' => false,
                    'docvalue_fields' => [$priceField],
                    'query' => ['bool' => ['filter' => [['ids' => ['values' => array_map('strval', $productIds)]]]]],
                    'aggs' => $this->facetAggregations($facetFields),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Facets over search results failed: ' . $e->getMessage());
            return $this->searchResultFactory->create($data);
        }
        $prices = $this->extractPrices($response, $priceField);
        return $this->searchResultFactory->create($data + [
            'facets' => $this->extractFacets($response, $facetFields),
            'pricePercentile25' => $this->percentile(array_values($prices), 25),
            'prices' => $prices,
        ]);
    }

    /**
     * @param array<string, string> $facetFields
     * @return array<string, mixed>
     */
    private function facetAggregations(array $facetFields): array
    {
        $aggs = [];
        foreach ($facetFields as $code => $field) {
            $aggs['facet_' . $code] = ['terms' => ['field' => $field, 'size' => self::FACET_BUCKET_LIMIT]];
        }
        return $aggs;
    }

    private function priceField(QueryOptionsInterface $options): string
    {
        return 'price_' . $options->getCustomerGroupId() . '_' . $options->getWebsiteId();
    }

    /**
     * @param array<string, int> $fieldBoosts
     * @return array<string, mixed>
     */
    private function multiMatchClause(string $query, array $fieldBoosts): array
    {
        $fields = [];
        foreach ($fieldBoosts as $field => $boost) {
            $fields[] = $field . '^' . $boost;
        }
        return [
            'multi_match' => [
                'query' => $query,
                'fields' => $fields,
                'operator' => 'and',
                // AUTO scales to edit-distance 2 for 6+ letter words,
                // which can match an unrelated real word by coincidence
                // (e.g. "jacket" against "pocket", distance 2) and
                // pollute free-text keyword results. Capped at 1: still
                // tolerates a single typo, no longer matches a different
                // real word by coincidence.
                'fuzziness' => 1,
            ],
        ];
    }

    /**
     * @param array<int, array{field: string, query: string, boost: float, exclude: bool, required?: bool}> $terms
     * @return array<string, mixed>
     */
    private function termsClause(array $terms): array
    {
        $must = [];
        $mustNot = [];
        foreach ($terms as $term) {
            $matchClause = [
                'match' => [
                    $term['field'] => [
                        'query' => $term['query'],
                        'operator' => 'and',
                        'boost' => $term['boost'],
                        'fuzziness' => 'AUTO',
                    ],
                ],
            ];
            if ($term['exclude']) {
                // A missing field already satisfies "not X" on its own —
                // must_not against a field the document doesn't have is a
                // no-op match, so it needs no exists-aware wrapping.
                $mustNot[] = $matchClause;
                continue;
            }
            if (!empty($term['required'])) {
                // The caller demands the value itself (e.g. a filter the
                // shopper explicitly picked): a missing value is a miss.
                $must[] = $matchClause;
                continue;
            }
            // A product carrying no value at all for this attribute is
            // neither confirmed nor contradicted by the term — many
            // attributes are only ever set on part of the catalog, and a
            // term has nothing to say about a product missing it. Only a
            // value that's actually present and different counts as a
            // real mismatch; absence must not be scored the same as a
            // contradiction.
            $must[] = [
                'bool' => [
                    'should' => [
                        $matchClause,
                        ['bool' => ['must_not' => ['exists' => ['field' => $term['field']]]]],
                    ],
                    'minimum_should_match' => 1,
                ],
            ];
        }
        $bool = [];
        if ($must !== []) {
            $bool['must'] = $must;
        }
        if ($mustNot !== []) {
            $bool['must_not'] = $mustNot;
        }
        return $bool;
    }

    /**
     * Hard constraints shared by the keyword and vector queries.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildFilter(QueryOptionsInterface $options): array
    {
        $filter = [
            ['terms' => ['visibility' => self::VISIBILITY_IN_SEARCH]],
            ['term' => ['status' => self::STATUS_ENABLED]],
        ];
        if ($options->getCategoryId() !== null) {
            $filter[] = ['term' => ['category_ids' => $options->getCategoryId()]];
        }
        if ($options->isInStockOnly()) {
            // Magento_InventoryElasticsearch\...\ProductDataMapperPlugin::afterMap()
            // stores (int)!IS_SALABLE under this field name, so 0 means IN stock.
            $filter[] = ['term' => ['is_out_of_stock' => 0]];
        }
        if ($options->getPriceMin() !== null || $options->getPriceMax() !== null) {
            $range = [];
            if ($options->getPriceMin() !== null) {
                $range['gte'] = $options->getPriceMin();
            }
            if ($options->getPriceMax() !== null) {
                $range['lte'] = $options->getPriceMax();
            }
            // Same per-group price field Magento's own layered navigation
            // filters by; precision matches the storefront (reindex lag).
            $filter[] = ['range' => ['price_' . $options->getCustomerGroupId() . '_' . $options->getWebsiteId() => $range]];
        }
        return $filter;
    }

    /**
     * Blends keyword + vector results. Falls back to keyword-only on any
     * embedding/vector-query failure — never throws.
     *
     * @param array<string, mixed> $termsBool termsClause() output (must/must_not), empty when there are no terms
     * @param array<string, mixed> $keywordResponse raw ES response
     * @return int[]
     */
    private function hybridMerge(string $query, array $termsBool, array $keywordResponse, QueryOptionsInterface $options): array
    {
        try {
            $queryVector = $this->embedQuery($query);
        } catch (LlmProviderException $e) {
            $this->logger->warning('Semantic search embedding failed, falling back to keyword-only: ' . $e->getMessage());
            return $this->extractIds($keywordResponse);
        }
        if ($queryVector === []) {
            return $this->extractIds($keywordResponse);
        }
        try {
            $size = $termsBool === []
                ? $options->getLimit()
                : min($options->getLimit() * self::VECTOR_CANDIDATE_FACTOR, self::MAX_VECTOR_CANDIDATES);
            $vectorResponse = $this->runVectorQuery($queryVector, $options, $size);
            if ($termsBool !== []) {
                $vectorResponse = $this->keepTermMatches($vectorResponse, $termsBool, $options);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Semantic search vector query failed, falling back to keyword-only: ' . $e->getMessage());
            return $this->extractIds($keywordResponse);
        }
        return $this->mergeScores($keywordResponse, $vectorResponse, $options->getLimit());
    }

    /**
     * The dimension count is part of the key: switching to a model with
     * a different vector size must never reuse an old vector.
     *
     * @return float[] empty when the provider returned nothing
     * @throws LlmProviderException
     */
    private function embedQuery(string $query): array
    {
        $key = self::QUERY_VECTOR_CACHE_PREFIX . sha1($this->embeddingProvider->getDimensions() . '|' . $query);
        $cached = $this->cache->load($key);
        if (is_string($cached) && $cached !== '') {
            $vector = $this->json->unserialize($cached);
            if (is_array($vector) && $vector !== []) {
                return $vector;
            }
        }
        $vector = $this->embeddingProvider->embed([$query])[0] ?? [];
        if ($vector !== []) {
            $this->cache->save($this->json->serialize($vector), $key, [], self::QUERY_VECTOR_CACHE_LIFETIME);
        }
        return $vector;
    }

    /**
     * The vector index carries only the filter fields (visibility,
     * status, category, stock, price), not attribute values — a term
     * clause run against it would see every attribute field as missing,
     * which termsClause() treats as "not contradicted", i.e. it would
     * match everything. So vector hits are checked against the main
     * index instead: one query restricted to the hit IDs, keeping only
     * those that satisfy the same terms the keyword query applied.
     *
     * @param array<string, mixed> $vectorResponse
     * @param array<string, mixed> $termsBool
     * @return array<string, mixed> $vectorResponse with non-matching hits removed
     */
    private function keepTermMatches(array $vectorResponse, array $termsBool, QueryOptionsInterface $options): array
    {
        $ids = $this->extractIds($vectorResponse);
        if ($ids === []) {
            return $vectorResponse;
        }
        $bool = $termsBool;
        $bool['filter'] = [['ids' => ['values' => array_map('strval', $ids)]]];
        $response = $this->connectionManager->getConnection()->query([
            'index' => $this->indexNameResolver->getIndexName($options->getStoreId(), 'catalogsearch_fulltext'),
            'body' => [
                'size' => count($ids),
                '_source' => false,
                'query' => ['bool' => $bool],
            ],
        ]);
        $allowed = array_flip($this->extractIds($response));

        $vectorResponse['hits']['hits'] = array_values(array_filter(
            $vectorResponse['hits']['hits'] ?? [],
            static fn(array $hit): bool => isset($allowed[(int)$hit['_id']])
        ));
        return $vectorResponse;
    }

    /**
     * @param float[] $queryVector
     * @return array<string, mixed> raw ES response
     */
    private function runVectorQuery(array $queryVector, QueryOptionsInterface $options, int $size): array
    {
        return $this->connectionManager->getConnection()->query([
            'index' => $this->vectorIndexManager->getIndexName($options->getStoreId()),
            'body' => [
                'size' => $size,
                '_source' => false,
                'query' => [
                    'script_score' => [
                        'query' => ['bool' => ['filter' => $this->buildFilter($options)]],
                        'script' => $this->vectorDialect->cosineScoreScript('embedding', $queryVector),
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $keywordResponse
     * @param array<string, mixed> $vectorResponse
     * @return int[]
     */
    private function mergeScores(array $keywordResponse, array $vectorResponse, int $limit): array
    {
        $keywordScores = $this->normalizeKeywordScores($keywordResponse);
        $vectorScores = $this->normalizeVectorScores($vectorResponse);
        $keywordWeight = $this->semanticConfig->getKeywordWeight();
        $vectorWeight = $this->semanticConfig->getVectorWeight();

        $combined = [];
        foreach (array_unique(array_merge(array_keys($keywordScores), array_keys($vectorScores))) as $id) {
            $combined[$id] = $keywordWeight * ($keywordScores[$id] ?? 0.0) + $vectorWeight * ($vectorScores[$id] ?? 0.0);
        }
        arsort($combined);
        return array_map('intval', array_slice(array_keys($combined), 0, $limit));
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, float> product ID => score in [0,1]
     */
    private function normalizeKeywordScores(array $response): array
    {
        $maxScore = (float)($response['hits']['max_score'] ?? 0.0);
        $scores = [];
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            $scores[(int)$hit['_id']] = $maxScore > 0.0 ? (float)$hit['_score'] / $maxScore : 0.0;
        }
        return $scores;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, float> product ID => score in [0,1]
     */
    private function normalizeVectorScores(array $response): array
    {
        $scores = [];
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            // Raw score range is [0,2] — see VectorDialect::cosineScoreScript().
            $scores[(int)$hit['_id']] = (float)$hit['_score'] / 2.0;
        }
        return $scores;
    }

    /**
     * @param array<string, mixed> $bool
     * @return array<string, mixed> raw ES response
     */
    private function run(array $bool, QueryOptionsInterface $options): array
    {
        $priceField = $this->priceField($options);
        $bool['filter'] = $this->buildFilter($options);

        $body = [
            'size' => $options->getLimit(),
            '_source' => false,
            'query' => ['bool' => $bool],
        ];
        if ($options->getSort() === QueryOptionsInterface::SORT_PRICE_ASC) {
            $body['sort'] = [[$priceField => 'asc']];
        } elseif ($options->getSort() === QueryOptionsInterface::SORT_PRICE_DESC) {
            $body['sort'] = [[$priceField => 'desc']];
        }

        return $this->connectionManager->getConnection()->query([
            'index' => $this->indexNameResolver->getIndexName($options->getStoreId(), 'catalogsearch_fulltext'),
            'body' => $body,
        ]);
    }

    /**
     * @param array<string, mixed> $response
     * @return int[]
     */
    private function extractIds(array $response): array
    {
        $ids = [];
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            $ids[] = (int)$hit['_id'];
        }
        return $ids;
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, string> $facetFields
     * @return array<string, array<string, int>>
     */
    private function extractFacets(array $response, array $facetFields): array
    {
        $facets = [];
        foreach (array_keys($facetFields) as $code) {
            $buckets = $response['aggregations']['facet_' . $code]['buckets'] ?? [];
            $values = [];
            foreach ($buckets as $bucket) {
                $values[(string)$bucket['key']] = (int)$bucket['doc_count'];
            }
            if ($values !== []) {
                $facets[$code] = $values;
            }
        }
        return $facets;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, float> product ID => price, for products that have one
     */
    private function extractPrices(array $response, string $priceField): array
    {
        $prices = [];
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            $value = $hit['fields'][$priceField][0] ?? null;
            if ($value !== null) {
                $prices[(int)$hit['_id']] = (float)$value;
            }
        }
        return $prices;
    }

    /**
     * Linear interpolation between closest ranks.
     *
     * @param float[] $values
     */
    private function percentile(array $values, int $percent): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $position = (count($values) - 1) * $percent / 100;
        $lower = (int)floor($position);
        $upper = (int)ceil($position);
        return $values[$lower] + ($values[$upper] - $values[$lower]) * ($position - $lower);
    }
}
