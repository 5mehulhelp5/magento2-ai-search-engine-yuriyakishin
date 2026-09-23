<?php

declare(strict_types=1);

namespace Yu\AiSearchEngine\Test\Unit\Model;

use Magento\AdvancedSearch\Model\Client\ClientInterface;
use Magento\Elasticsearch\SearchAdapter\ConnectionManager;
use Magento\Elasticsearch\SearchAdapter\SearchIndexNameResolver;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Yu\AiLlm\Api\EmbeddingProviderInterface;
use Yu\AiLlm\Model\LlmProviderException;
use Yu\AiSearchEngine\Api\Data\SearchResultInterfaceFactory;
use Yu\AiSearchEngine\Model\EngineFinder;
use Yu\AiSearchEngine\Model\Indexer\VectorIndexManager;
use Yu\AiSearchEngine\Model\QueryOptions;
use Yu\AiSearchEngine\Model\SearchResult;
use Yu\AiSearchEngine\Model\SemanticConfig;
use Yu\AiSearchEngine\Model\VectorDialect;

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

    public function testRequiredTermIsABareMatchSoDocumentsMissingTheFieldAreExcluded(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->findByTerms([
            ['field' => 'color', 'query' => 'red', 'boost' => 2.0, 'exclude' => false, 'required' => true],
        ], $this->options());

        $this->assertSame(
            ['match' => ['color' => ['query' => 'red', 'operator' => 'and', 'boost' => 2.0, 'fuzziness' => 'AUTO']]],
            $captured['body']['query']['bool']['must'][0]
        );
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
            $this->makeDialect('elasticsearch7'),
            $this->createMock(CacheInterface::class),
            new Json(),
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

    public function testSearchDescribesExactlyTheReturnedProductsWhenFacetFieldsGiven(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = [];
        $responses = [
            ['hits' => ['hits' => [['_id' => '10'], ['_id' => '11'], ['_id' => '12']], 'total' => ['value' => 300]]],
            [
                'hits' => ['hits' => [
                    ['_id' => '10', 'fields' => ['price_0_1' => [10.0]]],
                    ['_id' => '11', 'fields' => ['price_0_1' => [20.0]]],
                    ['_id' => '12', 'fields' => ['price_0_1' => [30.0]]],
                ]],
                'aggregations' => ['facet_color' => ['buckets' => [['key' => 5, 'doc_count' => 2], ['key' => 6, 'doc_count' => 1]]]],
            ],
        ];
        $client->method('query')->willReturnCallback(function (array $params) use (&$captured, &$responses) {
            $captured[] = $params;
            return array_shift($responses);
        });

        $result = $finder->search('yoga pants', ['name' => 5], [], $this->options(), ['color' => 'color']);

        $this->assertSame([10, 11, 12], $result->getProductIds());
        // Counts describe the 3 returned products, not the 300 keyword matches.
        $this->assertSame(3, $result->getTotalCount());
        $this->assertSame(['color' => ['5' => 2, '6' => 1]], $result->getFacets());
        $this->assertSame([10 => 10.0, 11 => 20.0, 12 => 30.0], $result->getPrices());
        $this->assertSame(15.0, $result->getPricePercentile25());

        $this->assertArrayNotHasKey('aggs', $captured[0]['body']);
        $describe = $captured[1]['body'];
        $this->assertSame(3, $describe['size']);
        $this->assertSame(['price_0_1'], $describe['docvalue_fields']);
        $this->assertSame([['ids' => ['values' => ['10', '11', '12']]]], $describe['query']['bool']['filter']);
        $this->assertSame(['field' => 'color', 'size' => 20], $describe['aggs']['facet_color']['terms']);
    }

    public function testSearchStillReturnsProductsWhenTheFacetQueryFails(): void
    {
        [$finder, $client] = $this->makeFinder();
        $call = 0;
        $client->method('query')->willReturnCallback(function () use (&$call) {
            if (++$call === 1) {
                return ['hits' => ['hits' => [['_id' => '10']]]];
            }
            throw new \RuntimeException('aggregation failed');
        });

        $result = $finder->search('yoga pants', ['name' => 5], [], $this->options(), ['color' => 'color']);

        $this->assertSame([10], $result->getProductIds());
        $this->assertSame([], $result->getFacets());
    }

    public function testRefineKeepsOnlyMatchingProductsInTheirOriginalOrder(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = [];
        $responses = [
            // the engine returns matches in its own order
            ['hits' => ['hits' => [['_id' => '3'], ['_id' => '1']]]],
            ['hits' => ['hits' => []], 'aggregations' => []],
        ];
        $client->method('query')->willReturnCallback(function (array $params) use (&$captured, &$responses) {
            $captured[] = $params;
            return array_shift($responses);
        });

        $result = $finder->refine([1, 2, 3], ['material' => 44], $this->options(categoryId: 7), ['color' => 'color']);

        $this->assertSame([1, 3], $result->getProductIds());
        $this->assertSame(2, $result->getTotalCount());
        $filter = $captured[0]['body']['query']['bool']['filter'];
        $this->assertContains(['ids' => ['values' => ['1', '2', '3']]], $filter);
        // Exact value on the same field facets count on — no fuzzy text match.
        $this->assertContains(['term' => ['material' => 44]], $filter);
        $this->assertContains(['term' => ['category_ids' => 7]], $filter);
        $this->assertSame(3, $captured[0]['body']['size']);
        // facets are computed over the kept products
        $this->assertSame([['ids' => ['values' => ['1', '3']]]], $captured[1]['body']['query']['bool']['filter']);
    }

    public function testRefineOfNothingQueriesNothing(): void
    {
        [$finder, $client] = $this->makeFinder();
        $client->expects($this->never())->method('query');

        $this->assertSame([], $finder->refine([], ['material' => 44], $this->options(), ['color' => 'color'])->getProductIds());
    }

    public function testSearchOmitsAggsClauseWhenNoFacetFieldsGiven(): void
    {
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->search('yoga pants', ['name' => 5], [], $this->options());

        $this->assertArrayNotHasKey('aggs', $captured['body']);
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

    public function testSearchDropsVectorHitsThatViolateTheTermsViaTheMainIndex(): void
    {
        [$finder, $client, $semanticConfig, $embeddingProvider] = $this->makeFinder();
        $semanticConfig->method('isEnabled')->willReturn(true);
        $semanticConfig->method('getKeywordWeight')->willReturn(0.4);
        $semanticConfig->method('getVectorWeight')->willReturn(0.6);
        $embeddingProvider->method('embed')->willReturn([[0.1, 0.2]]);
        $captured = [];
        $responses = [
            // 1. keyword query (main index, terms applied)
            ['hits' => ['hits' => [['_id' => '1', '_score' => 4.0]], 'max_score' => 4.0]],
            // 2. vector query: 2 and 3 are semantically close, only 2 has the term
            ['hits' => ['hits' => [['_id' => '3', '_score' => 1.9], ['_id' => '2', '_score' => 1.8]]]],
            // 3. term check of the vector hits against the main index
            ['hits' => ['hits' => [['_id' => '2']]]],
        ];
        $client->method('query')->willReturnCallback(function (array $params) use (&$captured, &$responses) {
            $captured[] = $params;
            return array_shift($responses);
        });

        $result = $finder->search(
            'warm jacket',
            ['name' => 5],
            [['field' => 'color_value', 'query' => 'red', 'boost' => 1.0, 'exclude' => false]],
            $this->options(limit: 10)
        );

        // combined(1) = 0.4*1.0 = 0.40; combined(2) = 0.6*(1.8/2) = 0.54;
        // 3 would score 0.57 but violates the term, so it must be absent.
        $this->assertSame([2, 1], $result->getProductIds());
        $this->assertCount(3, $captured);

        // The vector index has no attribute fields: the vector query
        // carries the shared filters only, over a wider candidate pool.
        $vectorCall = $captured[1];
        $this->assertSame(['filter'], array_keys($vectorCall['body']['query']['script_score']['query']['bool']));
        $this->assertSame(50, $vectorCall['body']['size']);

        // The term check runs on the main index, restricted to the vector hits.
        $checkCall = $captured[2];
        $this->assertSame('catalogsearch_fulltext_store_1', $checkCall['index']);
        $this->assertArrayHasKey('must', $checkCall['body']['query']['bool']);
        $this->assertSame(
            [['ids' => ['values' => ['3', '2']]]],
            $checkCall['body']['query']['bool']['filter']
        );
    }

    public function testQueryVectorIsCachedAndReusedForTheSameQuery(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $saved = [];
        $cache->method('save')->willReturnCallback(function ($data, $key) use (&$saved) {
            $saved[$key] = $data;
            return true;
        });
        $cache->method('load')->willReturnCallback(function ($key) use (&$saved) {
            return $saved[$key] ?? false;
        });
        [$finder, $client, $semanticConfig, $embeddingProvider] = $this->makeFinder($cache);
        $semanticConfig->method('isEnabled')->willReturn(true);
        $embeddingProvider->method('getDimensions')->willReturn(3);
        $embeddingProvider->expects($this->once())->method('embed')->willReturn([[0.1, 0.2, 0.3]]);
        $vectors = [];
        $client->method('query')->willReturnCallback(function (array $params) use (&$vectors) {
            if (isset($params['body']['query']['script_score'])) {
                $vectors[] = $params['body']['query']['script_score']['script']['params']['query_vector'];
            }
            return ['hits' => ['hits' => []]];
        });

        $finder->findByQuery('warm jacket', ['name' => 5], $this->options());
        $finder->findByQuery('warm jacket', ['name' => 5], $this->options());

        $this->assertSame([[0.1, 0.2, 0.3], [0.1, 0.2, 0.3]], $vectors);
        $this->assertCount(1, $saved);
    }

    public function testSearchWithoutTermsSkipsTheVectorTermCheck(): void
    {
        [$finder, $client, $semanticConfig, $embeddingProvider] = $this->makeFinder();
        $semanticConfig->method('isEnabled')->willReturn(true);
        $embeddingProvider->method('embed')->willReturn([[0.1, 0.2]]);
        $captured = [];
        $client->method('query')->willReturnCallback(function (array $params) use (&$captured) {
            $captured[] = $params;
            return ['hits' => ['hits' => [['_id' => '1', '_score' => 1.0]], 'max_score' => 1.0]];
        });

        $finder->search('warm jacket', ['name' => 5], [], $this->options(limit: 10));

        $this->assertCount(2, $captured);
        $this->assertSame(10, $captured[1]['body']['size']);
    }

    public function testSearchFallsBackToKeywordOnlyWhenTheVectorTermCheckFails(): void
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
            if ($call === 2) {
                return ['hits' => ['hits' => [['_id' => '9', '_score' => 1.9]]]];
            }
            throw new \RuntimeException('main index unavailable');
        });

        $result = $finder->search(
            'warm jacket',
            ['name' => 5],
            [['field' => 'color_value', 'query' => 'red', 'boost' => 1.0, 'exclude' => false]],
            $this->options()
        );

        $this->assertSame([1], $result->getProductIds());
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

    private function makeDialect(string $engine): VectorDialect
    {
        $engineResolver = $this->createMock(EngineResolverInterface::class);
        $engineResolver->method('getCurrentSearchEngine')->willReturn($engine);
        return new VectorDialect($engineResolver);
    }

    /**
     * @return array{0: EngineFinder, 1: ClientInterface&\PHPUnit\Framework\MockObject\MockObject, 2: SemanticConfig&\PHPUnit\Framework\MockObject\MockObject, 3: EmbeddingProviderInterface&\PHPUnit\Framework\MockObject\MockObject, 4: VectorIndexManager&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function makeFinder(?CacheInterface $cache = null): array
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
                $data['pricePercentile25'] ?? null,
                $data['totalCount'] ?? 0,
                $data['prices'] ?? []
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
            $this->makeDialect('elasticsearch7'),
            $cache ?? $this->createMock(CacheInterface::class),
            new Json(),
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
