<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Api\Data;

/**
 * Result of EngineFinder::search(): ordered product IDs plus, when facet
 * fields were requested, real counts for what else is in that same
 * result set. Facets, prices and total count describe exactly the
 * returned products — so a facet count is precisely what refining by
 * that value (EngineFinder::refine()) leaves.
 */
interface SearchResultInterface
{
    /**
     * @return int[]
     */
    public function getProductIds(): array;

    /**
     * Up to 20 most frequent values per requested facet field.
     *
     * @return array<string, array<string, int>> attribute code => [value => doc_count], most frequent first
     */
    public function getFacets(): array;

    /**
     * @return float|null
     */
    public function getPricePercentile25(): ?float;

    /**
     * Number of returned products — the base the facet counts are
     * relative to.
     *
     * @return int
     */
    public function getTotalCount(): int;

    /**
     * Prices of the returned products for the searched customer group
     * and website (filled when facets were requested).
     *
     * @return array<int, float> product ID => price
     */
    public function getPrices(): array;
}
