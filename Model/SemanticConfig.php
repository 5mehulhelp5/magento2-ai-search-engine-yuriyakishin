<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Yu\AiSearchEngine\Model\Config\Source\EmbeddingAttributeMode;

/**
 * Kill switch and blend weights for hybrid (keyword + vector) search.
 * Off falls back to today's pure keyword behavior everywhere — the
 * default until a merchant has run a full vector reindex. Also what the
 * vector indexer embeds besides name and descriptions; those settings
 * take an explicit store ID because the indexer runs outside any store.
 */
class SemanticConfig
{
    private const PATH_ENABLED = 'yu_aisearchengine/semantic/enabled';
    private const PATH_KEYWORD_WEIGHT = 'yu_aisearchengine/semantic/keyword_weight';
    private const PATH_VECTOR_WEIGHT = 'yu_aisearchengine/semantic/vector_weight';
    private const PATH_EMBED_ATTRIBUTE_MODE = 'yu_aisearchengine/vector_content/attribute_mode';
    private const PATH_EMBED_ATTRIBUTES = 'yu_aisearchengine/vector_content/attributes';
    private const PATH_EMBED_CATEGORIES = 'yu_aisearchengine/vector_content/include_categories';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @param int|null $storeId null = current store
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @param int|null $storeId null = current store
     */
    public function getKeywordWeight(?int $storeId = null): float
    {
        return (float)$this->scopeConfig->getValue(self::PATH_KEYWORD_WEIGHT, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @param int|null $storeId null = current store
     */
    public function getVectorWeight(?int $storeId = null): float
    {
        return (float)$this->scopeConfig->getValue(self::PATH_VECTOR_WEIGHT, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @return string one of EmbeddingAttributeMode's constants
     */
    public function getEmbeddingAttributeMode(int $storeId): string
    {
        $mode = (string)$this->scopeConfig->getValue(self::PATH_EMBED_ATTRIBUTE_MODE, ScopeInterface::SCOPE_STORE, $storeId);
        return in_array($mode, [EmbeddingAttributeMode::CUSTOM, EmbeddingAttributeMode::NONE], true)
            ? $mode
            : EmbeddingAttributeMode::AUTO;
    }

    /**
     * @return string[] attribute codes picked for "Selected attributes only"
     */
    public function getEmbeddingAttributes(int $storeId): array
    {
        $value = (string)$this->scopeConfig->getValue(self::PATH_EMBED_ATTRIBUTES, ScopeInterface::SCOPE_STORE, $storeId);
        return array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
    }

    public function isCategoryEmbeddingEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_EMBED_CATEGORIES, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
