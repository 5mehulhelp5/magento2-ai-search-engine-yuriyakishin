<?php

declare(strict_types=1);

namespace Yu\AiSearchEngine\Test\Unit\Model\Indexer;

use PHPUnit\Framework\TestCase;
use Yu\AiSearchEngine\Model\Indexer\EmbeddingTextBuilder;

class EmbeddingTextBuilderTest extends TestCase
{
    public function testBuildJoinsAllPartsWithNewlines(): void
    {
        $builder = new EmbeddingTextBuilder();

        $this->assertSame(
            "Insulated Parka\nWarm and windproof\nA long description of the parka.",
            $builder->build(['Insulated Parka', 'Warm and windproof', 'A long description of the parka.'])
        );
    }

    public function testBuildSkipsEmptyParts(): void
    {
        $builder = new EmbeddingTextBuilder();

        $this->assertSame('Insulated Parka', $builder->build(['Insulated Parka', '', '']));
    }

    public function testBuildTrimsWhitespaceOnlyParts(): void
    {
        $builder = new EmbeddingTextBuilder();

        $this->assertSame('Insulated Parka', $builder->build(['Insulated Parka', '   ', "\n\t"]));
    }

    public function testBuildTrimsLeadingAndTrailingWhitespaceFromEachPart(): void
    {
        $builder = new EmbeddingTextBuilder();

        $this->assertSame(
            "Insulated Parka\nWarm and windproof",
            $builder->build(["  Insulated Parka \n", " Warm and windproof  "])
        );
    }

    public function testBuildReturnsEmptyStringWhenAllPartsAreEmpty(): void
    {
        $builder = new EmbeddingTextBuilder();

        $this->assertSame('', $builder->build(['', '', '']));
    }

    public function testBuildSupportsAnyNumberOfParts(): void
    {
        $builder = new EmbeddingTextBuilder();

        $this->assertSame(
            "Insulated Parka\nJackets",
            $builder->build(['Insulated Parka', 'Jackets'])
        );
    }

    public function testBuildStripsHtmlTagsScriptsAndStylesAndDecodesEntities(): void
    {
        $builder = new EmbeddingTextBuilder();

        $this->assertSame(
            "Warm & dry parka\nFully seam-sealed",
            $builder->build([
                '<style>.x{color:red}</style><p>Warm &amp; dry&nbsp;parka</p><script>alert(1)</script>',
                "<ul>\n  <li>Fully seam-sealed</li>\n</ul>",
            ])
        );
    }

    public function testBuildKeepsTagsFromRunningWordsTogether(): void
    {
        $builder = new EmbeddingTextBuilder();

        $this->assertSame('Warm Dry', $builder->build(['<b>Warm</b><i>Dry</i>']));
    }

    public function testBuildCollapsesBlankLinesInsideAPart(): void
    {
        $builder = new EmbeddingTextBuilder();

        $this->assertSame("First line\nSecond line", $builder->build(["First   line\n\n\n  Second\tline"]));
    }

    public function testBuildCutsOverLongTextFromTheEnd(): void
    {
        $builder = new EmbeddingTextBuilder();

        $text = $builder->build(['Insulated Parka', str_repeat('ж', EmbeddingTextBuilder::MAX_LENGTH)]);

        $this->assertSame(EmbeddingTextBuilder::MAX_LENGTH, mb_strlen($text));
        $this->assertStringStartsWith("Insulated Parka\n", $text);
    }
}
