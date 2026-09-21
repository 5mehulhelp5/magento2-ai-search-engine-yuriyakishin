<?php

declare(strict_types=1);

namespace Yu\AiSearchEngine\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;
use Yu\AiSearchEngine\Model\SemanticConfig;

class SemanticConfigTest extends TestCase
{
    public function testIsEnabledReadsTheEnabledFlag(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')
            ->with('yu_aisearchengine/semantic/enabled', ScopeInterface::SCOPE_STORE)
            ->willReturn(true);

        $this->assertTrue((new SemanticConfig($scopeConfig))->isEnabled());
    }

    public function testGetKeywordWeightReadsTheConfiguredValue(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with('yu_aisearchengine/semantic/keyword_weight', ScopeInterface::SCOPE_STORE)
            ->willReturn('0.4');

        $this->assertSame(0.4, (new SemanticConfig($scopeConfig))->getKeywordWeight());
    }

    public function testGetVectorWeightReadsTheConfiguredValue(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with('yu_aisearchengine/semantic/vector_weight', ScopeInterface::SCOPE_STORE)
            ->willReturn('0.6');

        $this->assertSame(0.6, (new SemanticConfig($scopeConfig))->getVectorWeight());
    }
}
