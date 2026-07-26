<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model;

use Yu\AiSearchEngine\Api\Data\SearchResultInterface;

/**
 * Result of EngineFinder::search(): ordered product IDs plus, when facet
 * fields were requested, real counts for what else is in that same
 * result set.
 */
class SearchResult implements SearchResultInterface
{
    /**
     * @param int[] $productIds
     * @param array<string, array<string, int>> $facets attribute code => [value => doc_count], ordered by doc_count descending
     */
    public function __construct(
        private readonly array $productIds,
        private readonly array $facets = [],
        private readonly ?float $pricePercentile25 = null
    ) {
    }

    /**
     * @return int[]
     */
    public function getProductIds(): array
    {
        return $this->productIds;
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function getFacets(): array
    {
        return $this->facets;
    }

    public function getPricePercentile25(): ?float
    {
        return $this->pricePercentile25;
    }
}
