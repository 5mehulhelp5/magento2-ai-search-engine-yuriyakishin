<?php

declare(strict_types=1);

namespace Yu\AiSearchEngine\Test\Unit\Model\Indexer;

use PHPUnit\Framework\TestCase;
use Yu\AiSearchEngine\Model\AttributeWhitelist;
use Yu\AiSearchEngine\Model\Indexer\EmbeddingAttributeResolver;
use Yu\AiSearchEngine\Model\SemanticConfig;

class EmbeddingAttributeResolverTest extends TestCase
{
    public function testAutoModeTakesDescriptiveWhitelistedAttributesOnly(): void
    {
        $resolver = $this->makeResolver('auto', [], [
            'sku' => ['input' => 'text'],
            'name' => ['input' => 'text'],
            'attr_select' => ['input' => 'select'],
            'attr_multi' => ['input' => 'multiselect'],
            'attr_text' => ['input' => 'text'],
            'attr_flag' => ['input' => 'boolean'],
            'attr_long' => ['input' => 'textarea'],
            'description' => ['input' => 'textarea'],
        ]);

        $this->assertSame(['attr_select', 'attr_multi', 'attr_text'], $resolver->getAttributeCodes(1));
    }

    public function testCustomModeReturnsTheSelectedAttributesMinusExcludedCodes(): void
    {
        $resolver = $this->makeResolver('custom', ['attr_flag', 'sku', 'attr_select'], []);

        $this->assertSame(['attr_flag', 'attr_select'], $resolver->getAttributeCodes(1));
    }

    public function testNoneModeReturnsNothing(): void
    {
        $resolver = $this->makeResolver('none', ['attr_select'], ['attr_select' => ['input' => 'select']]);

        $this->assertSame([], $resolver->getAttributeCodes(1));
    }

    /**
     * @param string[] $selected
     * @param array<string, array{input: string}> $whitelist
     */
    private function makeResolver(string $mode, array $selected, array $whitelist): EmbeddingAttributeResolver
    {
        $config = $this->createMock(SemanticConfig::class);
        $config->method('getEmbeddingAttributeMode')->willReturn($mode);
        $config->method('getEmbeddingAttributes')->willReturn($selected);
        $attributeWhitelist = $this->createMock(AttributeWhitelist::class);
        $attributeWhitelist->method('getAttributes')->willReturn($whitelist);

        return new EmbeddingAttributeResolver($config, $attributeWhitelist);
    }
}
