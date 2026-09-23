<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;
use Yu\AiSearchEngine\Model\Indexer\EmbeddingAttributeResolver;

/**
 * Product attributes a merchant may pick for the embedding text: visible
 * in admin, with an input type AttributeTextProvider can render, and
 * merchant-created — system attributes are layout/pricing/shipping
 * switches, except the few listed that describe the product itself.
 */
class EmbeddableAttribute implements OptionSourceInterface
{
    private const DESCRIPTIVE_SYSTEM_CODES = ['manufacturer', 'country_of_manufacture'];

    public function __construct(
        private readonly CollectionFactory $attributeCollectionFactory
    ) {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $collection = $this->attributeCollectionFactory->create()
            ->addVisibleFilter()
            ->addFieldToFilter('frontend_input', ['in' => EmbeddingAttributeResolver::SELECTABLE_INPUTS])
            ->addFieldToFilter('attribute_code', ['nin' => EmbeddingAttributeResolver::EXCLUDED_CODES])
            ->addFieldToFilter(
                ['is_user_defined', 'attribute_code'],
                [['eq' => 1], ['in' => self::DESCRIPTIVE_SYSTEM_CODES]]
            )
            ->setOrder('frontend_label', 'ASC');

        $options = [];
        foreach ($collection as $attribute) {
            $code = (string)$attribute->getAttributeCode();
            $label = trim((string)$attribute->getFrontendLabel()) ?: $code;
            $options[] = ['value' => $code, 'label' => $label . ' (' . $code . ')'];
        }
        return $options;
    }
}
