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
}
