<?php

declare(strict_types=1);

namespace Yu\AiSearchEngine\Test\Unit\Model;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Type;
use Magento\Elasticsearch\Model\Adapter\FieldMapperInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Serialize\SerializerInterface;
use PHPUnit\Framework\TestCase;
use Yu\AiSearchEngine\Model\AttributeWhitelist;

class AttributeWhitelistTest extends TestCase
{
    public function testBuildKeepsStaticAttributesEvenWithoutEavValues(): void
    {
        $whitelist = $this->makeWhitelist($this->rows());

        $attributes = $whitelist->getAttributes();

        $this->assertArrayHasKey('sku', $attributes);
        $this->assertSame('sku_field', $attributes['sku']['es_field']);
    }

    public function testBuildSkipsNonStaticAttributesWithNoEavValuesAnywhere(): void
    {
        $whitelist = $this->makeWhitelist($this->rows());

        $attributes = $whitelist->getAttributes();

        $this->assertArrayNotHasKey('brand', $attributes);
    }

    public function testBuildKeepsAttributesWithEavValues(): void
    {
        $whitelist = $this->makeWhitelist($this->rows());

        $attributes = $whitelist->getAttributes();

        $this->assertArrayHasKey('color', $attributes);
        $this->assertSame(5, $attributes['color']['weight']);
        $this->assertSame('select', $attributes['color']['input']);
        $this->assertSame('Color', $attributes['color']['label']);
    }

    public function testBuildFloorsSearchWeightAtOne(): void
    {
        $whitelist = $this->makeWhitelist($this->rows());

        $attributes = $whitelist->getAttributes();

        $this->assertSame(1, $attributes['name']['weight']);
    }

    public function testBuildFallsBackToAttributeCodeWhenFrontendLabelIsNull(): void
    {
        $whitelist = $this->makeWhitelist($this->rows());

        $attributes = $whitelist->getAttributes();

        $this->assertSame('name', $attributes['name']['label']);
    }

    public function testGetFieldBoostsMapsEsFieldToWeight(): void
    {
        $whitelist = $this->makeWhitelist($this->rows());

        $this->assertSame(
            ['color_field' => 5, 'sku_field' => 10, 'name_field' => 1],
            $whitelist->getFieldBoosts()
        );
    }

    public function testGetAttributesBuildsOnlyOncePerInstanceEvenWithoutCacheHit(): void
    {
        $connection = $this->makeConnection($this->rows());
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $cache->expects($this->once())->method('save');

        $whitelist = new AttributeWhitelist(
            $resource,
            $this->makeEavConfig(),
            $this->makeFieldMapper(),
            $cache,
            $this->createMock(SerializerInterface::class)
        );

        $whitelist->getAttributes();
        $whitelist->getAttributes();
    }

    public function testGetAttributesReturnsUnserializedCacheHitWithoutTouchingTheDatabase(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('serialized-payload');
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('unserialize')->with('serialized-payload')->willReturn(['cached' => ['es_field' => 'x', 'weight' => 1, 'input' => 'text', 'label' => 'X']]);

        $whitelist = new AttributeWhitelist(
            $resource,
            $this->createMock(EavConfig::class),
            $this->createMock(FieldMapperInterface::class),
            $cache,
            $serializer
        );

        $this->assertSame(
            ['cached' => ['es_field' => 'x', 'weight' => 1, 'input' => 'text', 'label' => 'X']],
            $whitelist->getAttributes()
        );
    }

    public function testGetAggregationFieldNameDelegatesToFieldMapperWithTheProbeConfirmedContext(): void
    {
        $fieldMapper = $this->createMock(FieldMapperInterface::class);
        $fieldMapper->expects($this->once())
            ->method('getFieldName')
            ->with('color', ['type' => 'filter'])
            ->willReturn('color');

        // Direct construction, same pattern as
        // testGetAttributesReturnsUnserializedCacheHitWithoutTouchingTheDatabase()
        // above — getAggregationFieldName() only touches the field mapper,
        // so the other four collaborators need no special setup.
        $whitelist = new AttributeWhitelist(
            $this->createMock(ResourceConnection::class),
            $this->createMock(EavConfig::class),
            $fieldMapper,
            $this->createMock(CacheInterface::class),
            $this->createMock(SerializerInterface::class)
        );

        $this->assertSame('color', $whitelist->getAggregationFieldName('color'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        return [
            ['attribute_id' => 10, 'attribute_code' => 'color', 'backend_type' => 'varchar', 'frontend_input' => 'select', 'frontend_label' => 'Color', 'search_weight' => '5'],
            ['attribute_id' => 12, 'attribute_code' => 'brand', 'backend_type' => 'varchar', 'frontend_input' => 'select', 'frontend_label' => 'Brand', 'search_weight' => '0'],
            ['attribute_id' => 1, 'attribute_code' => 'sku', 'backend_type' => 'static', 'frontend_input' => 'text', 'frontend_label' => 'SKU', 'search_weight' => '10'],
            ['attribute_id' => 11, 'attribute_code' => 'name', 'backend_type' => 'varchar', 'frontend_input' => 'text', 'frontend_label' => null, 'search_weight' => '0'],
        ];
    }

    private function makeWhitelist(array $rows): AttributeWhitelist
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->makeConnection($rows));
        $resource->method('getTableName')->willReturnArgument(0);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        return new AttributeWhitelist(
            $resource,
            $this->makeEavConfig(),
            $this->makeFieldMapper(),
            $cache,
            $this->createMock(SerializerInterface::class)
        );
    }

    /**
     * @return AdapterInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeConnection(array $rows)
    {
        $select = $this->getMockBuilder(Select::class)->disableOriginalConstructor()->getMock();
        $select->method('distinct')->willReturnSelf();
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        // Only attributes 10 (color) and 11 (name) have EAV values; 1 (sku)
        // is static so it doesn't need this, and 12 (brand) has none.
        $connection->method('fetchCol')->willReturn([10, 11]);
        $connection->method('fetchAll')->willReturn($rows);

        return $connection;
    }

    private function makeEavConfig(): EavConfig
    {
        $type = $this->createMock(Type::class);
        $type->method('getId')->willReturn(4);
        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getEntityType')->with(Product::ENTITY)->willReturn($type);
        return $eavConfig;
    }

    private function makeFieldMapper(): FieldMapperInterface
    {
        $fieldMapper = $this->createMock(FieldMapperInterface::class);
        $fieldMapper->method('getFieldName')->willReturnCallback(
            static fn(string $code): string => $code . '_field'
        );
        return $fieldMapper;
    }
}
