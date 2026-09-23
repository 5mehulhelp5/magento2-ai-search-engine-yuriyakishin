<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model;

use Yu\AiSearchEngine\Api\Data\SearchResultInterface;

/**
 * Result of EngineFinder::search(): ordered product IDs plus, when facet
 * fields were requested, real counts for what else is in that same
 * result set. Facets, prices and total count describe exactly the
 * returned products — so a facet count is precisely what refining by
 * that value (EngineFinder::refine()) leaves.
 */
class SearchResult implements SearchResultInterface
{
    /**
     * @param int[] $productIds
     * @param array<string, array<string, int>> $facets attribute code => [value => doc_count], ordered by doc_count descending
     * @param int $totalCount number of returned products — the base the facet counts are relative to
     * @param array<int, float> $prices product ID => price for the searched customer group/website
     */
    public function __construct(
        private readonly array $productIds,
        private readonly array $facets = [],
        private readonly ?float $pricePercentile25 = null,
        private readonly int $totalCount = 0,
        private readonly array $prices = []
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

    public function getTotalCount(): int
    {
        return $this->totalCount;
    }

    /**
     * @return array<int, float>
     */
    public function getPrices(): array
    {
        return $this->prices;
    }
}
