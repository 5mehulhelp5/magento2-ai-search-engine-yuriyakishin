<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Api\Data;

interface QueryOptionsInterface
{
    public const SORT_RELEVANCE = 'relevance';
    public const SORT_PRICE_ASC = 'price_asc';
    public const SORT_PRICE_DESC = 'price_desc';
    public const SORTS = [self::SORT_RELEVANCE, self::SORT_PRICE_ASC, self::SORT_PRICE_DESC];

    public function getStoreId(): int;

    public function getCustomerGroupId(): int;

    public function getWebsiteId(): int;

    public function getLimit(): int;

    public function getPriceMin(): ?float;

    public function getPriceMax(): ?float;

    public function getCategoryId(): ?int;

    public function isInStockOnly(): bool;

    public function getSort(): string;
}
