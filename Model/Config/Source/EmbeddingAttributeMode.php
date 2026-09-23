<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Which attributes go into the embedding text — see EmbeddingAttributeResolver.
 */
class EmbeddingAttributeMode implements OptionSourceInterface
{
    public const AUTO = 'auto';
    public const CUSTOM = 'custom';
    public const NONE = 'none';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::AUTO, 'label' => __('Automatic (searchable and filterable attributes)')],
            ['value' => self::CUSTOM, 'label' => __('Selected attributes only')],
            ['value' => self::NONE, 'label' => __('None')],
        ];
    }
}
