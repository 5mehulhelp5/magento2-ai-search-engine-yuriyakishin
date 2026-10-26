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

    public function testBlendSettingsAreReadForTheGivenStore(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())->method('isSetFlag')
            ->with('yu_aisearchengine/semantic/enabled', ScopeInterface::SCOPE_STORE, 3)
            ->willReturn(true);
        $scopeConfig->expects($this->exactly(2))->method('getValue')
            ->with($this->anything(), ScopeInterface::SCOPE_STORE, 3)
            ->willReturn('0.5');
        $config = new SemanticConfig($scopeConfig);

        $this->assertTrue($config->isEnabled(3));
        $this->assertSame(0.5, $config->getKeywordWeight(3));
        $this->assertSame(0.5, $config->getVectorWeight(3));
    }

    public function testGetEmbeddingAttributeModeReadsTheStoreValue(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with('yu_aisearchengine/vector_content/attribute_mode', ScopeInterface::SCOPE_STORE, 3)
            ->willReturn('custom');

        $this->assertSame('custom', (new SemanticConfig($scopeConfig))->getEmbeddingAttributeMode(3));
    }

    public function testGetEmbeddingAttributeModeFallsBackToAutoForUnknownValues(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);

        $this->assertSame('auto', (new SemanticConfig($scopeConfig))->getEmbeddingAttributeMode(1));
    }

    public function testGetEmbeddingAttributesSplitsTheMultiselectValue(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with('yu_aisearchengine/vector_content/attributes', ScopeInterface::SCOPE_STORE, 1)
            ->willReturn('attr_a,attr_b,');

        $this->assertSame(['attr_a', 'attr_b'], (new SemanticConfig($scopeConfig))->getEmbeddingAttributes(1));
    }

    public function testGetEmbeddingAttributesIsEmptyWhenNothingIsSelected(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);

        $this->assertSame([], (new SemanticConfig($scopeConfig))->getEmbeddingAttributes(1));
    }

    public function testIsCategoryEmbeddingEnabledReadsTheStoreFlag(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')
            ->with('yu_aisearchengine/vector_content/include_categories', ScopeInterface::SCOPE_STORE, 2)
            ->willReturn(true);

        $this->assertTrue((new SemanticConfig($scopeConfig))->isCategoryEmbeddingEnabled(2));
    }
}
