<?php

declare(strict_types=1);

namespace Yu\AiSearchEngine\Test\Unit\Model\Indexer;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;
use Yu\AiSearchEngine\Model\Indexer\AttributeTextProvider;

class AttributeTextProviderTest extends TestCase
{
    public function testSelectAndMultiselectValuesRenderAsStoreOptionLabels(): void
    {
        $provider = $this->makeProvider([
            'attr_select' => $this->optionAttribute('Fabric', 'select', [['value' => '5', 'label' => 'Wool']]),
            'attr_multi' => $this->optionAttribute('Use', 'multiselect', [
                ['value' => '7', 'label' => 'Hiking'],
                ['value' => '8', 'label' => 'Camping'],
                ['value' => '9', 'label' => 'Running'],
            ]),
        ]);
        $product = new DataObject(['attr_select' => '5', 'attr_multi' => '7,8']);

        $this->assertSame(
            ['Fabric: Wool', 'Use: Hiking, Camping'],
            $provider->getLines($product, ['attr_select', 'attr_multi'], 1)
        );
    }

    public function testOptionGroupsAreFlattened(): void
    {
        $provider = $this->makeProvider([
            'attr_select' => $this->optionAttribute('Fabric', 'select', [
                ['label' => 'Natural', 'value' => [['value' => '5', 'label' => 'Wool']]],
            ]),
        ]);

        $this->assertSame(
            ['Fabric: Wool'],
            $provider->getLines(new DataObject(['attr_select' => '5']), ['attr_select'], 1)
        );
    }

    public function testUnknownOptionIdsAndEmptyValuesAreSkipped(): void
    {
        $provider = $this->makeProvider([
            'attr_select' => $this->optionAttribute('Fabric', 'select', [['value' => '5', 'label' => 'Wool']]),
            'attr_other' => $this->optionAttribute('Other', 'select', []),
        ]);
        $product = new DataObject(['attr_select' => '999', 'attr_other' => '']);

        $this->assertSame([], $provider->getLines($product, ['attr_select', 'attr_other', 'attr_missing'], 1));
    }

    public function testBooleanRendersAsBareLabelOnlyWhenYes(): void
    {
        $provider = $this->makeProvider([
            'attr_yes' => $this->plainAttribute('Waterproof', 'boolean'),
            'attr_no' => $this->plainAttribute('Recycled', 'boolean'),
        ]);
        $product = new DataObject(['attr_yes' => '1', 'attr_no' => '0']);

        $this->assertSame(['Waterproof'], $provider->getLines($product, ['attr_yes', 'attr_no'], 1));
    }

    public function testTextValuesWithoutLettersAreSkipped(): void
    {
        $provider = $this->makeProvider([
            'attr_code' => $this->plainAttribute('Barcode', 'text'),
            'attr_text' => $this->plainAttribute('Fit', 'text'),
        ]);
        $product = new DataObject(['attr_code' => '4006381333931', 'attr_text' => '<b>Relaxed</b> fit']);

        $this->assertSame(['Fit: Relaxed fit'], $provider->getLines($product, ['attr_code', 'attr_text'], 1));
    }

    public function testOptionLookupRunsInTheTargetStoreAndRestoresTheAttributeStore(): void
    {
        $attribute = $this->getMockBuilder(Attribute::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getAttributeCode', 'getStoreLabel', 'getFrontendInput', 'usesSource', 'getSource'])
            ->addMethods(['getStoreId', 'setStoreId'])
            ->getMock();
        $attribute->method('getId')->willReturn(1);
        $attribute->method('getAttributeCode')->willReturn('attr_select');
        $attribute->method('getStoreLabel')->willReturn('Fabric');
        $attribute->method('getFrontendInput')->willReturn('select');
        $attribute->method('usesSource')->willReturn(true);
        $attribute->method('getStoreId')->willReturn(0);

        $storeIds = [];
        $storeIdDuringLookup = null;
        $attribute->method('setStoreId')->willReturnCallback(
            function ($storeId) use (&$storeIds, $attribute) {
                $storeIds[] = $storeId;
                return $attribute;
            }
        );
        $source = $this->createMock(AbstractSource::class);
        $source->method('getAllOptions')->willReturnCallback(
            function () use (&$storeIds, &$storeIdDuringLookup) {
                $storeIdDuringLookup = end($storeIds);
                return [['value' => '5', 'label' => 'Laine']];
            }
        );
        $attribute->method('getSource')->willReturn($source);

        $provider = $this->makeProvider(['attr_select' => $attribute]);

        $this->assertSame(['Fabric: Laine'], $provider->getLines(new DataObject(['attr_select' => '5']), ['attr_select'], 2));
        $this->assertSame(2, $storeIdDuringLookup);
        $this->assertSame([2, 0], $storeIds);
    }

    /**
     * @param array<string, Attribute> $attributes
     */
    private function makeProvider(array $attributes): AttributeTextProvider
    {
        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturnCallback(
            fn(string $entityType, string $code) => $attributes[$code] ?? $this->plainAttribute('', 'text', 0)
        );
        return new AttributeTextProvider($eavConfig);
    }

    /**
     * @param array<int, array<string, mixed>> $options
     */
    private function optionAttribute(string $label, string $input, array $options): Attribute
    {
        $source = $this->createMock(AbstractSource::class);
        $source->method('getAllOptions')->willReturn($options);
        $attribute = $this->plainAttribute($label, $input);
        $attribute->method('usesSource')->willReturn(true);
        $attribute->method('getSource')->willReturn($source);
        return $attribute;
    }

    /**
     * @return Attribute&\PHPUnit\Framework\MockObject\MockObject
     */
    private function plainAttribute(string $label, string $input, int $id = 1): Attribute
    {
        $attribute = $this->createMock(Attribute::class);
        $attribute->method('getId')->willReturn($id);
        $attribute->method('getAttributeCode')->willReturn(strtolower($label));
        $attribute->method('getStoreLabel')->willReturn($label);
        $attribute->method('getFrontendInput')->willReturn($input);
        return $attribute;
    }
}
