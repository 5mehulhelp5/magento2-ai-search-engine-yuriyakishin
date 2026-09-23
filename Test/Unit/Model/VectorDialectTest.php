<?php

declare(strict_types=1);

namespace Yu\AiSearchEngine\Test\Unit\Model;

use Magento\Framework\Search\EngineResolverInterface;
use PHPUnit\Framework\TestCase;
use Yu\AiSearchEngine\Model\VectorDialect;

class VectorDialectTest extends TestCase
{
    /**
     * @dataProvider engineProvider
     */
    public function testIsOpenSearchDetectsEngineByConfiguredName(string $engine, bool $expected): void
    {
        $this->assertSame($expected, $this->makeDialect($engine)->isOpenSearch());
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function engineProvider(): array
    {
        return [
            'opensearch' => ['opensearch', true],
            'third-party opensearch engine' => ['vendor_opensearch', true],
            'elasticsearch7' => ['elasticsearch7', false],
            'elasticsearch8' => ['elasticsearch8', false],
        ];
    }

    public function testFieldMappingUsesDenseVectorOnElasticsearch(): void
    {
        $this->assertSame(
            ['type' => 'dense_vector', 'dims' => 1536],
            $this->makeDialect('elasticsearch7')->fieldMapping(1536)
        );
    }

    public function testFieldMappingUsesKnnVectorOnOpenSearch(): void
    {
        $this->assertSame(
            ['type' => 'knn_vector', 'dimension' => 1536],
            $this->makeDialect('opensearch')->fieldMapping(1536)
        );
    }

    public function testDimensionsFromMappingReadsBothDialectsRegardlessOfCurrentEngine(): void
    {
        $dialect = $this->makeDialect('opensearch');

        $this->assertSame(1536, $dialect->dimensionsFromMapping(['type' => 'dense_vector', 'dims' => 1536]));
        $this->assertSame(3072, $dialect->dimensionsFromMapping(['type' => 'knn_vector', 'dimension' => 3072]));
        $this->assertNull($dialect->dimensionsFromMapping([]));
    }

    public function testCosineScoreScriptUsesPainlessCosineSimilarityOnElasticsearch(): void
    {
        $this->assertSame(
            [
                'source' => "cosineSimilarity(params.query_vector, 'embedding') + 1.0",
                'params' => ['query_vector' => [0.1, 0.2]],
            ],
            $this->makeDialect('elasticsearch7')->cosineScoreScript('embedding', [0.1, 0.2])
        );
    }

    public function testCosineScoreScriptUsesKnnScoreOnOpenSearch(): void
    {
        $this->assertSame(
            [
                'lang' => 'knn',
                'source' => 'knn_score',
                'params' => [
                    'field' => 'embedding',
                    'query_value' => [0.1, 0.2],
                    'space_type' => 'cosinesimil',
                ],
            ],
            $this->makeDialect('opensearch')->cosineScoreScript('embedding', [0.1, 0.2])
        );
    }

    private function makeDialect(string $engine): VectorDialect
    {
        $engineResolver = $this->createMock(EngineResolverInterface::class);
        $engineResolver->method('getCurrentSearchEngine')->willReturn($engine);
        return new VectorDialect($engineResolver);
    }
}
