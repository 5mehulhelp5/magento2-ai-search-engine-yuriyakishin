<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model;

use Yu\AiSearchEngine\Api\Data\QueryOptionsInterface;

/**
 * Validated description of one engine search. Built by the caller from
 * untrusted input; EngineFinder trusts every value here, so all
 * clamping/whitelisting must happen before construction.
 */
class QueryOptions implements QueryOptionsInterface
{
    public function __construct(
        private readonly int $storeId,
        private readonly int $customerGroupId,
        private readonly int $websiteId,
        private readonly int $limit,
        private readonly ?float $priceMin = null,
        private readonly ?float $priceMax = null,
        private readonly ?int $categoryId = null,
        private readonly bool $inStockOnly = false,
        private readonly string $sort = self::SORT_RELEVANCE
    ) {
    }

    public function getStoreId(): int
    {
        return $this->storeId;
    }

    public function getCustomerGroupId(): int
    {
        return $this->customerGroupId;
    }

    public function getWebsiteId(): int
    {
        return $this->websiteId;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getPriceMin(): ?float
    {
        return $this->priceMin;
    }

    public function getPriceMax(): ?float
    {
        return $this->priceMax;
    }

    public function getCategoryId(): ?int
    {
        return $this->categoryId;
    }

    public function isInStockOnly(): bool
    {
        return $this->inStockOnly;
    }

    public function getSort(): string
    {
        return $this->sort;
    }
}
