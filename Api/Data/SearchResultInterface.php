<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Api\Data;

/**
 * Result of EngineFinder::search(): ordered product IDs plus, when facet
 * fields were requested, real counts for what else is in that same
 * result set.
 */
interface SearchResultInterface
{
    /**
     * @return int[]
     */
    public function getProductIds(): array;

    /**
     * @return array<string, array<string, int>>
     */
    public function getFacets(): array;

    /**
     * @return float|null
     */
    public function getPricePercentile25(): ?float;
}
