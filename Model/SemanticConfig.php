<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Kill switch and blend weights for hybrid (keyword + vector) search.
 * Off falls back to today's pure keyword behavior everywhere — the
 * default until a merchant has run a full vector reindex.
 */
class SemanticConfig
{
    private const PATH_ENABLED = 'yu_aisearchengine/semantic/enabled';
    private const PATH_KEYWORD_WEIGHT = 'yu_aisearchengine/semantic/keyword_weight';
    private const PATH_VECTOR_WEIGHT = 'yu_aisearchengine/semantic/vector_weight';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function getKeywordWeight(): float
    {
        return (float)$this->scopeConfig->getValue(self::PATH_KEYWORD_WEIGHT, ScopeInterface::SCOPE_STORE);
    }

    public function getVectorWeight(): float
    {
        return (float)$this->scopeConfig->getValue(self::PATH_VECTOR_WEIGHT, ScopeInterface::SCOPE_STORE);
    }
}
