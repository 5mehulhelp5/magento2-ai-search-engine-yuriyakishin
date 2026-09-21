<?php

declare(strict_types=1);

namespace Yu\AiSearchEngine\Test\Unit\Model;

use Magento\AdvancedSearch\Model\Client\ClientInterface;
use Magento\Elasticsearch\SearchAdapter\ConnectionManager;
use Magento\Elasticsearch\SearchAdapter\SearchIndexNameResolver;
use PHPUnit\Framework\TestCase;
use Yu\AiLlm\Api\EmbeddingProviderInterface;
use Yu\AiLlm\Model\LlmProviderException;
use Yu\AiSearchEngine\Api\Data\SearchResultInterfaceFactory;
use Yu\AiSearchEngine\Model\EngineFinder;
use Yu\AiSearchEngine\Model\Indexer\VectorIndexManager;
use Yu\AiSearchEngine\Model\QueryOptions;
use Yu\AiSearchEngine\Model\SearchResult;
use Yu\AiSearchEngine\Model\SemanticConfig;

class EngineFinderTest extends TestCase
{
    public function testFindByQueryBuildsBoostedMultiMatchClause(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->findByQuery('red shoes', ['name_search' => 5, 'sku' => 2], $this->options());

        $this->assertSame(
            [['multi_match' => [
                'query' => 'red shoes',
                'fields' => ['name_search^5', 'sku^2'],
                'operator' => 'and',
                'fuzziness' => 1,
            ]]],
            $captured['body']['query']['bool']['must']
        );
    }

    public function testFindByTermsSplitsIncludedAndExcludedIntoMustAndMustNot(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->findByTerms([
            ['field' => 'color', 'query' => 'red', 'boost' => 2.0, 'exclude' => false],
            ['field' => 'color', 'query' => 'blue', 'boost' => 1.0, 'exclude' => true],
        ], $this->options());

        $bool = $captured['body']['query']['bool'];
        $this->assertSame(
            [
                'bool' => [
                    'should' => [
                        ['match' => ['color' => ['query' => 'red', 'operator' => 'and', 'boost' => 2.0, 'fuzziness' => 'AUTO']]],
                        ['bool' => ['must_not' => ['exists' => ['field' => 'color']]]],
                    ],
                    'minimum_should_match' => 1,
                ],
            ],
            $bool['must'][0]
        );
        // Excluded terms stay a bare match: a document missing the field
        // already satisfies "not X" on its own, no exists-wrapping needed.
        $this->assertSame(['match' => ['color' => ['query' => 'blue', 'operator' => 'and', 'boost' => 1.0, 'fuzziness' => 'AUTO']]], $bool['must_not'][0]);
    }

    public function testFindByTermsMatchingTermDoesNotExcludeDocumentsMissingTheField(): void
    {
        // The whole point of the wrapping: a term on a sparsely-populated
        // attribute must narrow among products that have it, not zero out
        // every product that simply never had the attribute set.
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->findByTerms([
            ['field' => 'gender_value', 'query' => 'Men', 'boost' => 1.0, 'exclude' => false],
        ], $this->options());

        $shouldClauses = $captured['body']['query']['bool']['must'][0]['bool']['should'];
        $this->assertCount(2, $shouldClauses);
        $this->assertArrayHasKey('match', $shouldClauses[0]);
        $this->assertSame(['bool' => ['must_not' => ['exists' => ['field' => 'gender_value']]]], $shouldClauses[1]);
    }

    public function testFindByTermsWithOnlyExclusionsOmitsMustEntirely(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->findByTerms([
            ['field' => 'color', 'query' => 'blue', 'boost' => 1.0, 'exclude' => true],
        ], $this->options());

        $this->assertArrayNotHasKey('must', $captured['body']['query']['bool']);
        $this->assertArrayHasKey('must_not', $captured['body']['query']['bool']);
    }

    public function testRunAlwaysFiltersToEnabledAndVisibleInSearch(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->findByQuery('x', [], $this->options());

        $filter = $captured['body']['query']['bool']['filter'];
        $this->assertSame(['terms' => ['visibility' => [3, 4]]], $filter[0]);
        $this->assertSame(['term' => ['status' => 1]], $filter[1]);
    }

    public function testRunAddsCategoryFilterOnlyWhenCategoryIdIsSet(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->findByQuery('x', [], $this->options(categoryId: 42));

        $this->assertContains(['term' => ['category_ids' => 42]], $captured['body']['query']['bool']['filter']);
    }

    public function testRunOmitsCategoryFilterWhenCategoryIdIsNull(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->findByQuery('x', [], $this->options());

        foreach ($captured['body']['query']['bool']['filter'] as $clause) {
            $this->assertArrayNotHasKey('category_ids', $clause['term'] ?? []);
        }
    }

    public function testRunFiltersInStockUsingIsOutOfStockEqualsZeroMeaningInStock(): void
    {
        // Magento_InventoryElasticsearch\...\ProductDataMapperPlugin::afterMap()
        // stores (int)!IS_SALABLE under this field name, so 0 means IN stock.
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->findByQuery('x', [], $this->options(inStockOnly: true));

        $this->assertContains(['term' => ['is_out_of_stock' => 0]], $captured['body']['query']['bool']['filter']);
    }

    public function testRunOmitsStockFilterWhenNotRequested(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->findByQuery('x', [], $this->options());

        foreach ($captured['body']['query']['bool']['filter'] as $clause) {
            $this->assertArrayNotHasKey('is_out_of_stock', $clause['term'] ?? []);
        }
    }

    public function testRunBuildsPriceRangeFilterOnThePerGroupPerWebsiteField(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->findByQuery('x', [], $this->options(customerGroupId: 3, websiteId: 2, priceMin: 10.0, priceMax: 99.99));

        $priceFilter = null;
        foreach ($captured['body']['query']['bool']['filter'] as $clause) {
            if (isset($clause['range'])) {
                $priceFilter = $clause['range'];
            }
        }
        $this->assertSame(['gte' => 10.0, 'lte' => 99.99], $priceFilter['price_3_2']);
    }

    public function testRunOmitsPriceRangeFilterWhenNeitherBoundIsSet(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->findByQuery('x', [], $this->options());

        foreach ($captured['body']['query']['bool']['filter'] as $clause) {
            $this->assertArrayNotHasKey('range', $clause);
        }
    }

    public function testRunAddsPriceAscendingSortWithMatchingPriceField(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->findByQuery('x', [], $this->options(customerGroupId: 1, websiteId: 1, sort: QueryOptions::SORT_PRICE_ASC));

        $this->assertSame([['price_1_1' => 'asc']], $captured['body']['sort']);
    }

    public function testRunAddsPriceDescendingSort(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->findByQuery('x', [], $this->options(sort: QueryOptions::SORT_PRICE_DESC));

        $this->assertSame('desc', $captured['body']['sort'][0][array_key_first($captured['body']['sort'][0])]);
    }

    public function testRunOmitsSortForRelevance(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->findByQuery('x', [], $this->options());

        $this->assertArrayNotHasKey('sort', $captured['body']);
    }

    public function testRunUsesLimitAndDisablesSourceFetching(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->findByQuery('x', [], $this->options(limit: 7));

        $this->assertSame(7, $captured['body']['size']);
        $this->assertFalse($captured['body']['_source']);
    }

    public function testRunUsesResolvedIndexNameForTheGivenStore(): void
    {
        $indexResolver = $this->createMock(SearchIndexNameResolver::class);
        $indexResolver->method('getIndexName')->with(5, 'catalogsearch_fulltext')->willReturn('resolved_index_name');
        $client = $this->getMockBuilder(ClientInterface::class)->addMethods(['query'])->getMockForAbstractClass();
        $connectionManager = $this->createMock(ConnectionManager::class);
        $connectionManager->method('getConnection')->willReturn($client);
        $semanticConfig = $this->createMock(SemanticConfig::class);
        $semanticConfig->method('isEnabled')->willReturn(false);
        $finder = new EngineFinder(
            $connectionManager,
            $indexResolver,
            $this->createMock(SearchResultInterfaceFactory::class),
            $semanticConfig,
            $this->createMock(EmbeddingProviderInterface::class),
            $this->createMock(VectorIndexManager::class),
            $this->createMock(\Psr\Log\LoggerInterface::class)
        );
        $captured = &$this->captureQuery($client, []);

        $finder->findByQuery('x', [], $this->options(storeId: 5));

        $this->assertSame('resolved_index_name', $captured['index']);
    }

    public function testRunParsesHitIdsAsIntegers(): void
    {
        [$finder, $client] = $this->makeFinder();
        $client->method('query')->willReturn(['hits' => ['hits' => [['_id' => '42'], ['_id' => '7']]]]);

        $ids = $finder->findByQuery('x', [], $this->options());

        $this->assertSame([42, 7], $ids);
    }

    public function testRunReturnsEmptyArrayWhenResponseHasNoHits(): void
    {
        [$finder, $client] = $this->makeFinder();
        $client->method('query')->willReturn([]);

        $this->assertSame([], $finder->findByQuery('x', [], $this->options()));
    }

    public function testSearchCombinesKeywordsAndTermsInOneMustClause(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->search(
            'yoga pants',
            ['name' => 5],
            [['field' => 'color_value', 'query' => 'red', 'boost' => 1.0, 'exclude' => false]],
            $this->options()
        );

        $must = $captured['body']['query']['bool']['must'];
        $this->assertCount(2, $must);
        $this->assertArrayHasKey('multi_match', $must[0] + $must[1]);
        // The term entry is exists-or-match wrapped, not a bare 'match'.
        $this->assertArrayHasKey('bool', $must[0] + $must[1]);
    }

    public function testSearchOmitsKeywordsClauseWhenQueryIsEmpty(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->search(
            '',
            [],
            [['field' => 'color_value', 'query' => 'red', 'boost' => 1.0, 'exclude' => false]],
            $this->options()
        );

        $must = $captured['body']['query']['bool']['must'];
        $this->assertCount(1, $must);
        // The term entry is exists-or-match wrapped, not a bare 'match'.
        $this->assertArrayHasKey('bool', $must[0]);
    }

    public function testSearchRequestsAggregationsWhenFacetFieldsGiven(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->search('yoga pants', ['name' => 5], [], $this->options(customerGroupId: 0, websiteId: 1), ['color' => 'color']);

        $aggs = $captured['body']['aggs'];
        $this->assertSame(['field' => 'color', 'size' => 1], $aggs['facet_color']['terms']);
        $this->assertSame(['field' => 'price_0_1', 'percents' => [25]], $aggs['facet_price']['percentiles']);
    }

    public function testSearchOmitsAggsClauseWhenNoFacetFieldsGiven(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->search('yoga pants', ['name' => 5], [], $this->options());

        $this->assertArrayNotHasKey('aggs', $captured['body']);
    }

    public function testSearchParsesFacetBucketsAndPricePercentileFromResponse(): void
    {
        [$finder, $client] = $this->makeFinder();
        $client->method('query')->willReturn([
            'hits' => ['hits' => [['_id' => '10']]],
            'aggregations' => [
                'facet_color' => ['buckets' => [['key' => 'Red', 'doc_count' => 12]]],
                'facet_price' => ['values' => ['25.0' => 29.99]],
            ],
        ]);

        $result = $finder->search('yoga pants', ['name' => 5], [], $this->options(), ['color' => 'color']);

        $this->assertSame([10], $result->getProductIds());
        $this->assertSame(['color' => ['Red' => 12]], $result->getFacets());
        $this->assertSame(29.99, $result->getPricePercentile25());
    }

    public function testSearchReturnsEmptyFacetsWhenNoFacetFieldsRequested(): void
    {
        [$finder, $client] = $this->makeFinder();
        $client->method('query')->willReturn(['hits' => ['hits' => []]]);

        $result = $finder->search('yoga pants', ['name' => 5], [], $this->options());

        $this->assertSame([], $result->getFacets());
        $this->assertNull($result->getPricePercentile25());
    }

    public function testFindByQueryCallsTheEngineOnceWhenSemanticSearchIsDisabled(): void
    {
        [$finder, $client] = $this->makeFinder();
        $client->expects($this->once())->method('query')->willReturn(['hits' => ['hits' => [['_id' => '1', '_score' => 5.0]], 'max_score' => 5.0]]);

        $ids = $finder->findByQuery('red jacket', ['name' => 5], $this->options());

        $this->assertSame([1], $ids);
    }

    public function testFindByQueryBlendsKeywordAndVectorScoresWhenSemanticSearchIsEnabled(): void
    {
        [$finder, $client, $semanticConfig, $embeddingProvider] = $this->makeFinder();
        $semanticConfig->method('isEnabled')->willReturn(true);
        $embeddingProvider->method('embed')->with(['warm jacket'])->willReturn([[0.1, 0.2]]);
        $client->method('query')->willReturnOnConsecutiveCalls(
            // Keyword: product 1 scores highest, product 2 not found at all.
            ['hits' => ['hits' => [['_id' => '1', '_score' => 10.0]], 'max_score' => 10.0]],
            // Vector: product 2 (a paraphrase match keyword search missed) scores highest.
            ['hits' => ['hits' => [['_id' => '2', '_score' => 1.8], ['_id' => '1', '_score' => 1.2]]]]
        );

        $ids = $finder->findByQuery('warm jacket', ['name' => 5], $this->options());

        // keyword-normalized(1) = 10/10 = 1.0; vector-normalized(1) = 1.2/2 = 0.6
        // combined(1) = 0.4*1.0 + 0.6*0.6 = 0.76
        // keyword-normalized(2) = 0 (absent); vector-normalized(2) = 1.8/2 = 0.9
        // combined(2) = 0.4*0 + 0.6*0.9 = 0.54
        $this->assertSame([1, 2], $ids);
    }

    public function testFindByQueryFallsBackToKeywordOnlyWhenEmbeddingFails(): void
    {
        [$finder, $client, $semanticConfig, $embeddingProvider] = $this->makeFinder();
        $semanticConfig->method('isEnabled')->willReturn(true);
        $embeddingProvider->method('embed')->willThrowException(new LlmProviderException('boom', true));
        $client->expects($this->once())->method('query')->willReturn(['hits' => ['hits' => [['_id' => '1', '_score' => 5.0]], 'max_score' => 5.0]]);

        $ids = $finder->findByQuery('warm jacket', ['name' => 5], $this->options());

        $this->assertSame([1], $ids);
    }

    public function testFindByQueryFallsBackToKeywordOnlyWhenTheVectorQueryThrows(): void
    {
        [$finder, $client, $semanticConfig, $embeddingProvider] = $this->makeFinder();
        $semanticConfig->method('isEnabled')->willReturn(true);
        $embeddingProvider->method('embed')->willReturn([[0.1, 0.2]]);
        $call = 0;
        $client->method('query')->willReturnCallback(function () use (&$call) {
            $call++;
            if ($call === 1) {
                return ['hits' => ['hits' => [['_id' => '1', '_score' => 5.0]], 'max_score' => 5.0]];
            }
            throw new \RuntimeException('vector index missing');
        });

        $ids = $finder->findByQuery('warm jacket', ['name' => 5], $this->options());

        $this->assertSame([1], $ids);
    }

    public function testFindByQueryDoesNotAttemptSemanticSearchForAnEmptyQuery(): void
    {
        [$finder, $client, $semanticConfig, $embeddingProvider] = $this->makeFinder();
        $semanticConfig->method('isEnabled')->willReturn(true);
        $embeddingProvider->expects($this->never())->method('embed');
        $client->method('query')->willReturn(['hits' => ['hits' => []]]);

        $finder->findByQuery('', [], $this->options());
    }

    public function testFindByQuerySendsAScriptScoreCosineSimilarityQueryAgainstTheVectorIndex(): void
    {
        [$finder, $client, $semanticConfig, $embeddingProvider] = $this->makeFinder();
        $semanticConfig->method('isEnabled')->willReturn(true);
        $embeddingProvider->method('embed')->willReturn([[0.1, 0.2, 0.3]]);
        $captured = [];
        $client->method('query')->willReturnCallback(function (array $params) use (&$captured) {
            $captured[] = $params;
            return ['hits' => ['hits' => []]];
        });

        $finder->findByQuery('warm jacket', ['name' => 5], $this->options(storeId: 7));

        $this->assertCount(2, $captured);
        $vectorCall = $captured[1];
        $this->assertSame('prefix_ai_search_vector_1', $vectorCall['index']);
        $script = $vectorCall['body']['query']['script_score'];
        $this->assertSame("cosineSimilarity(params.query_vector, 'embedding') + 1.0", $script['script']['source']);
        $this->assertSame([0.1, 0.2, 0.3], $script['script']['params']['query_vector']);
        // findByQuery has no terms, so the vector query's base bool is filter-only.
        $this->assertArrayHasKey('filter', $script['query']['bool']);
        $this->assertArrayNotHasKey('must', $script['query']['bool']);
    }

    public function testSearchAppliesTermsAsAHardConstraintOnTheVectorQueryToo(): void
    {
        [$finder, $client, $semanticConfig, $embeddingProvider] = $this->makeFinder();
        $semanticConfig->method('isEnabled')->willReturn(true);
        $embeddingProvider->method('embed')->willReturn([[0.1, 0.2]]);
        $captured = [];
        $client->method('query')->willReturnCallback(function (array $params) use (&$captured) {
            $captured[] = $params;
            return ['hits' => ['hits' => []]];
        });

        $finder->search(
            'warm jacket',
            ['name' => 5],
            [['field' => 'color_value', 'query' => 'red', 'boost' => 1.0, 'exclude' => false]],
            $this->options()
        );

        $vectorCall = $captured[1];
        $vectorBool = $vectorCall['body']['query']['script_score']['query']['bool'];
        // The exists-or-match-wrapped term clause from termsClause() must
        // be present on the vector query too, not just the keyword one —
        // otherwise a product violating the color term could leak into
        // the blended results.
        $this->assertArrayHasKey('must', $vectorBool);
        $this->assertArrayHasKey('filter', $vectorBool);
    }

    public function testSearchDoesNotAttemptSemanticSearchWhenSemanticSearchIsDisabled(): void
    {
        [$finder, $client, $semanticConfig, $embeddingProvider] = $this->makeFinder();
        $semanticConfig->method('isEnabled')->willReturn(false);
        $embeddingProvider->expects($this->never())->method('embed');
        $client->expects($this->once())->method('query')->willReturn(['hits' => ['hits' => [['_id' => '1']]]]);

        $result = $finder->search('warm jacket', ['name' => 5], [], $this->options());

        $this->assertSame([1], $result->getProductIds());
    }

    private function options(
        int $storeId = 1,
        int $customerGroupId = 0,
        int $websiteId = 1,
        int $limit = 10,
        ?float $priceMin = null,
        ?float $priceMax = null,
        ?int $categoryId = null,
        bool $inStockOnly = false,
        string $sort = QueryOptions::SORT_RELEVANCE
    ): QueryOptions {
        return new QueryOptions($storeId, $customerGroupId, $websiteId, $limit, $priceMin, $priceMax, $categoryId, $inStockOnly, $sort);
    }

    /**
     * @return array{0: EngineFinder, 1: ClientInterface&\PHPUnit\Framework\MockObject\MockObject, 2: SemanticConfig&\PHPUnit\Framework\MockObject\MockObject, 3: EmbeddingProviderInterface&\PHPUnit\Framework\MockObject\MockObject, 4: VectorIndexManager&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function makeFinder(): array
    {
        // ConnectionManager::getConnection() returns whichever ES/OpenSearch
        // version's client is installed -- ClientInterface is the one
        // version-agnostic contract they all share, but it declares only
        // testConnection(). addMethods() adds query() for mocking without
        // pinning the test to a specific ES major version's client class.
        $client = $this->getMockBuilder(ClientInterface::class)
            ->addMethods(['query'])
            ->getMockForAbstractClass();
        $connectionManager = $this->createMock(ConnectionManager::class);
        $connectionManager->method('getConnection')->willReturn($client);
        $indexResolver = $this->createMock(SearchIndexNameResolver::class);
        $indexResolver->method('getIndexName')->willReturn('catalogsearch_fulltext_store_1');
        $searchResultFactory = $this->createMock(SearchResultInterfaceFactory::class);
        $searchResultFactory->method('create')->willReturnCallback(
            static fn (array $data = []): SearchResult => new SearchResult(
                $data['productIds'] ?? [],
                $data['facets'] ?? [],
                $data['pricePercentile25'] ?? null
            )
        );
        // isEnabled() left unstubbed: PHPUnit's mock default for an
        // unconfigured bool-returning method is false, which is exactly
        // the desired default (keyword-only) — and leaves each hybrid
        // test's own ->willReturn(true) as the only configured stub, so
        // it isn't shadowed by an earlier default (PHPUnit honors the
        // *first* configured stub when the same method is stubbed twice).
        $semanticConfig = $this->createMock(SemanticConfig::class);
        $semanticConfig->method('getKeywordWeight')->willReturn(0.4);
        $semanticConfig->method('getVectorWeight')->willReturn(0.6);
        $embeddingProvider = $this->createMock(EmbeddingProviderInterface::class);
        $vectorIndexManager = $this->createMock(VectorIndexManager::class);
        $vectorIndexManager->method('getIndexName')->willReturn('prefix_ai_search_vector_1');

        $finder = new EngineFinder(
            $connectionManager,
            $indexResolver,
            $searchResultFactory,
            $semanticConfig,
            $embeddingProvider,
            $vectorIndexManager,
            $this->createMock(\Psr\Log\LoggerInterface::class)
        );

        return [$finder, $client, $semanticConfig, $embeddingProvider, $vectorIndexManager];
    }

    /**
     * @param ClientInterface&\PHPUnit\Framework\MockObject\MockObject $client
     * @return array{index: string, body: array}
     */
    private function &captureQuery($client, array $response): array
    {
        $captured = [];
        $client->method('query')->willReturnCallback(
            function (array $params) use (&$captured, $response): array {
                $captured = $params;
                return $response;
            }
        );

        return $captured;
    }
}
