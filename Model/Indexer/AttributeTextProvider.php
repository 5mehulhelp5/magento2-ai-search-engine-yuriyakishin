<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model\Indexer;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\DataObject;

/**
 * Renders a product's attribute values as "Label: value, value" lines
 * for its embedding text, using the store view's attribute and option
 * labels (option IDs mean nothing to an embedding model). Yes/No
 * attributes render as the bare label, and only when set to Yes.
 * Free-text values without a single letter (codes, numbers) are
 * skipped as noise.
 */
class AttributeTextProvider
{
    private const MAX_VALUE_LENGTH = 200;

    /** @var array<int, array<string, array<string, string>>> store ID => code => option value => label */
    private array $optionLabels = [];

    public function __construct(
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * @param DataObject $product loaded with the given attributes selected
     * @param string[] $attributeCodes
     * @return string[]
     */
    public function getLines(DataObject $product, array $attributeCodes, int $storeId): array
    {
        $lines = [];
        foreach ($attributeCodes as $code) {
            $raw = $product->getData($code);
            if ($raw === null || $raw === '' || $raw === []) {
                continue;
            }
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
            if (!$attribute || !$attribute->getId()) {
                continue;
            }
            $label = trim((string)$attribute->getStoreLabel($storeId)) ?: $code;

            if ($attribute->getFrontendInput() === 'boolean') {
                if ((int)$raw === 1) {
                    $lines[] = $label;
                }
                continue;
            }

            $values = $attribute->usesSource()
                ? $this->optionValues($attribute, $raw, $storeId)
                : $this->textValues((string)$raw);
            if ($values !== []) {
                $lines[] = $label . ': ' . implode(', ', $values);
            }
        }
        return $lines;
    }

    /**
     * @param mixed $raw single option ID, comma-separated IDs (multiselect) or an array of IDs
     * @return string[]
     */
    private function optionValues(AbstractAttribute $attribute, $raw, int $storeId): array
    {
        $labels = $this->getOptionLabels($attribute, $storeId);
        $ids = is_array($raw) ? $raw : explode(',', (string)$raw);
        $values = [];
        foreach ($ids as $id) {
            $value = $labels[trim((string)$id)] ?? '';
            if ($value !== '') {
                $values[] = $value;
            }
        }
        return array_values(array_unique($values));
    }

    /**
     * @return string[]
     */
    private function textValues(string $raw): array
    {
        $value = trim(strip_tags($raw));
        if ($value === '' || !preg_match('/\p{L}/u', $value)) {
            return [];
        }
        return [mb_substr($value, 0, self::MAX_VALUE_LENGTH)];
    }

    /**
     * Option sources resolve labels for the attribute's own store ID; the
     * attribute instance is shared, so it is switched to the target store
     * only for the lookup and then restored.
     *
     * @return array<string, string> option value => label
     */
    private function getOptionLabels(AbstractAttribute $attribute, int $storeId): array
    {
        $code = (string)$attribute->getAttributeCode();
        if (isset($this->optionLabels[$storeId][$code])) {
            return $this->optionLabels[$storeId][$code];
        }
        $previousStoreId = $attribute->getStoreId();
        $attribute->setStoreId($storeId);
        try {
            $options = $attribute->getSource()->getAllOptions(false);
        } finally {
            $attribute->setStoreId($previousStoreId);
        }
        $labels = [];
        $this->flattenOptions((array)$options, $labels);
        return $this->optionLabels[$storeId][$code] = $labels;
    }

    /**
     * @param array<int, mixed> $options
     * @param array<string, string> $labels
     */
    private function flattenOptions(array $options, array &$labels): void
    {
        foreach ($options as $option) {
            if (!is_array($option) || !array_key_exists('value', $option)) {
                continue;
            }
            if (is_array($option['value'])) {
                // Option group: value holds the nested options.
                $this->flattenOptions($option['value'], $labels);
                continue;
            }
            $label = trim((string)($option['label'] ?? ''));
            if ($label !== '') {
                $labels[(string)$option['value']] = $label;
            }
        }
    }
}
