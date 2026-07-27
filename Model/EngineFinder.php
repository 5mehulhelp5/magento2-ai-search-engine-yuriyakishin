<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model;

use Magento\Elasticsearch\SearchAdapter\ConnectionManager;
use Magento\Elasticsearch\SearchAdapter\SearchIndexNameResolver;
use Yu\AiSearchEngine\Api\Data\QueryOptionsInterface;
use Yu\AiSearchEngine\Api\Data\SearchResultInterface;
use Yu\AiSearchEngine\Api\Data\SearchResultInterfaceFactory;

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

    public function __construct(
        private readonly ConnectionManager $connectionManager,
        private readonly SearchIndexNameResolver $indexNameResolver,
        private readonly SearchResultInterfaceFactory $searchResultFactory
    ) {
    }

    /**
     * Simple mode: one query string across all whitelisted fields.
     *
     * @param string $query
     * @param array<string, int> $fieldBoosts es_field => boost
     * @param QueryOptionsInterface $options
     * @return int[]
     */
    public function findByQuery(string $query, array $fieldBoosts, QueryOptionsInterface $options): array
    {
        $bool = ['must' => [$this->multiMatchClause($query, $fieldBoosts)]];
        return $this->extractIds($this->run($bool, $options, []));
    }

    /**
     * Targeted mode: per-attribute match clauses. Positive terms are
     * AND-combined — the caller named these properties, every one must
     * hold ("red yoga pants" must not return non-red pants). Excluded
     * terms go to must_not. An exclusion-only call is valid: it means
     * "everything except".
     *
     * @param array<int, array{field: string, query: string, boost: float, exclude: bool}> $terms
     * @param QueryOptionsInterface $options
     * @return int[]
     */
    public function findByTerms(array $terms, QueryOptionsInterface $options): array
    {
        return $this->extractIds($this->run($this->termsClause($terms), $options, []));
    }

    /**
     * Combined mode: free-text keywords narrowed by structured per-
     * attribute terms in the same query, optionally with facet
     * aggregations (top value + count per field in $facetFields, plus a
     * price 25th-percentile) computed over the same filtered result set
     * in the same round-trip.
     *
     * @param string $query empty string = keywords not applied
     * @param array<string, int> $fieldBoosts es_field => boost, used only when $query is non-empty
     * @param array<int, array{field: string, query: string, boost: float, exclude: bool}> $terms
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
        $response = $this->run($bool, $options, $facetFields);
        return $this->searchResultFactory->create([
            'productIds' => $this->extractIds($response),
            'facets' => $this->extractFacets($response, $facetFields),
            'pricePercentile25' => $this->extractPricePercentile25($response, $facetFields),
        ]);
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
     * @param array<int, array{field: string, query: string, boost: float, exclude: bool}> $terms
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
     * @param array<string, mixed> $bool
     * @param array<string, string> $facetFields
     * @return array<string, mixed> raw ES response
     */
    private function run(array $bool, QueryOptionsInterface $options, array $facetFields): array
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
        // Same per-group price field Magento's own layered navigation
        // filters by; precision matches the storefront (reindex lag).
        $priceField = 'price_' . $options->getCustomerGroupId() . '_' . $options->getWebsiteId();
        if ($options->getPriceMin() !== null || $options->getPriceMax() !== null) {
            $range = [];
            if ($options->getPriceMin() !== null) {
                $range['gte'] = $options->getPriceMin();
            }
            if ($options->getPriceMax() !== null) {
                $range['lte'] = $options->getPriceMax();
            }
            $filter[] = ['range' => [$priceField => $range]];
        }
        $bool['filter'] = $filter;

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
        if ($facetFields !== []) {
            $aggs = [];
            foreach ($facetFields as $code => $field) {
                $aggs['facet_' . $code] = ['terms' => ['field' => $field, 'size' => 1]];
            }
            $aggs['facet_price'] = ['percentiles' => ['field' => $priceField, 'percents' => [25]]];
            $body['aggs'] = $aggs;
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
     * @param array<string, string> $facetFields
     */
    private function extractPricePercentile25(array $response, array $facetFields): ?float
    {
        if ($facetFields === []) {
            return null;
        }
        $value = $response['aggregations']['facet_price']['values']['25.0'] ?? null;
        return $value !== null ? (float)$value : null;
    }
}
