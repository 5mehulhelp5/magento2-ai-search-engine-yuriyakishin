<?php

declare(strict_types=1);

namespace Yu\AiSearchEngine\Test\Unit\Model;

use Magento\Elasticsearch7\Model\Client\Elasticsearch as ElasticsearchClient;
use Magento\Elasticsearch\SearchAdapter\ConnectionManager;
use Magento\Elasticsearch\SearchAdapter\SearchIndexNameResolver;
use PHPUnit\Framework\TestCase;
use Yu\AiSearchEngine\Api\Data\SearchResultInterfaceFactory;
use Yu\AiSearchEngine\Model\EngineFinder;
use Yu\AiSearchEngine\Model\QueryOptions;
use Yu\AiSearchEngine\Model\SearchResult;

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
        $this->assertSame(['match' => ['color' => ['query' => 'red', 'operator' => 'and', 'boost' => 2.0, 'fuzziness' => 'AUTO']]], $bool['must'][0]);
        $this->assertSame(['match' => ['color' => ['query' => 'blue', 'operator' => 'and', 'boost' => 1.0, 'fuzziness' => 'AUTO']]], $bool['must_not'][0]);
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

    public function testRunFiltersInStockUsingIsOutOfStockEqualsOneMeaningInStock(): void
    {
        // Core quirk: the ES index field named is_out_of_stock actually
        // stores (int)IS_SALABLE, so 1 means IN stock, not out of stock.
        [$finder, $client] = $this->makeFinder();
        $captured = &$this->captureQuery($client, []);

        $finder->findByQuery('x', [], $this->options(inStockOnly: true));

        $this->assertContains(['term' => ['is_out_of_stock' => 1]], $captured['body']['query']['bool']['filter']);
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
        $client = $this->createMock(ElasticsearchClient::class);
        $connectionManager = $this->createMock(ConnectionManager::class);
        $connectionManager->method('getConnection')->willReturn($client);
        $finder = new EngineFinder($connectionManager, $indexResolver, $this->createMock(SearchResultInterfaceFactory::class));
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
        $this->assertArrayHasKey('match', $must[0] + $must[1]);
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
        $this->assertArrayHasKey('match', $must[0]);
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
     * @return array{0: EngineFinder, 1: ElasticsearchClient&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function makeFinder(): array
    {
        $client = $this->createMock(ElasticsearchClient::class);
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

        return [new EngineFinder($connectionManager, $indexResolver, $searchResultFactory), $client];
    }

    /**
     * @param ElasticsearchClient&\PHPUnit\Framework\MockObject\MockObject $client
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
