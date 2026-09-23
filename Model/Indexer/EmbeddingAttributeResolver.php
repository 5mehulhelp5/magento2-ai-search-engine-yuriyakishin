<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model\Indexer;

use Yu\AiSearchEngine\Model\AttributeWhitelist;
use Yu\AiSearchEngine\Model\Config\Source\EmbeddingAttributeMode;
use Yu\AiSearchEngine\Model\SemanticConfig;

/**
 * Which product attributes are written into a product's embedding text.
 *
 * Automatic mode takes the searchable/filterable attributes (see
 * AttributeWhitelist) with descriptive values: option labels and short
 * text. Yes/No flags are left out by default — "Label: Yes" carries
 * little meaning for most flags — but may be picked explicitly.
 */
class EmbeddingAttributeResolver
{
    public const AUTO_INPUTS = ['select', 'multiselect', 'text'];
    public const SELECTABLE_INPUTS = ['select', 'multiselect', 'text', 'boolean'];

    /**
     * Already embedded on their own (name, descriptions), identifiers, or
     * SEO/system text — never useful as "Label: value" lines.
     */
    public const EXCLUDED_CODES = [
        'name',
        'description',
        'short_description',
        'sku',
        'url_key',
        'meta_title',
        'meta_keyword',
        'meta_description',
        'status',
        'visibility',
        'tax_class_id',
        'price',
        'quantity_and_stock_status',
    ];

    public function __construct(
        private readonly SemanticConfig $semanticConfig,
        private readonly AttributeWhitelist $attributeWhitelist
    ) {
    }

    /**
     * @return string[] attribute codes
     */
    public function getAttributeCodes(int $storeId): array
    {
        switch ($this->semanticConfig->getEmbeddingAttributeMode($storeId)) {
            case EmbeddingAttributeMode::NONE:
                return [];
            case EmbeddingAttributeMode::CUSTOM:
                return array_values(array_diff(
                    $this->semanticConfig->getEmbeddingAttributes($storeId),
                    self::EXCLUDED_CODES
                ));
            default:
                $codes = [];
                foreach ($this->attributeWhitelist->getAttributes() as $code => $attribute) {
                    if (in_array($attribute['input'], self::AUTO_INPUTS, true)
                        && !in_array($code, self::EXCLUDED_CODES, true)
                    ) {
                        $codes[] = (string)$code;
                    }
                }
                return $codes;
        }
    }
}
