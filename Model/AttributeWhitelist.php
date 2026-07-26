<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Elasticsearch\Model\Adapter\FieldMapperInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Merchant-driven list of attributes a caller may target in search:
 * Use in Search OR Use in Layered Navigation, non-empty for at least one
 * product, minus system attributes. Metadata only — cost does not depend
 * on catalog size beyond one indexed GROUP-BY per value table, and the
 * result is cached until EAV changes.
 */
class AttributeWhitelist
{
    private const CACHE_ID = 'yu_aisearchengine_attributes';
    private const CACHE_LIFETIME = 3600;
    private const BLACKLIST = ['status', 'tax_class_id', 'url_key', 'visibility', 'price'];
    private const VALUE_TABLES = [
        'catalog_product_entity_varchar',
        'catalog_product_entity_int',
        'catalog_product_entity_text',
        'catalog_product_entity_decimal',
    ];

    /** @var array<string, array{es_field: string, weight: int, input: string, label: string}>|null */
    private ?array $attributes = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig,
        private readonly FieldMapperInterface $fieldMapper,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * @return array<string, array{es_field: string, weight: int, input: string, label: string}>
     */
    public function getAttributes(): array
    {
        if ($this->attributes !== null) {
            return $this->attributes;
        }
        $cached = $this->cache->load(self::CACHE_ID);
        if ($cached !== false) {
            return $this->attributes = $this->serializer->unserialize($cached);
        }
        $this->attributes = $this->build();
        $this->cache->save(
            $this->serializer->serialize($this->attributes),
            self::CACHE_ID,
            [\Magento\Eav\Model\Cache\Type::CACHE_TAG],
            self::CACHE_LIFETIME
        );
        return $this->attributes;
    }

    /**
     * @return array<string, int> es_field => boost, for the simple-query mode
     */
    public function getFieldBoosts(): array
    {
        $boosts = [];
        foreach ($this->getAttributes() as $meta) {
            $boosts[$meta['es_field']] = $meta['weight'];
        }
        return $boosts;
    }

    /**
     * The not-analyzed field for this attribute — clean whole-value terms
     * aggregation buckets, unlike the analyzed field getFieldBoosts()
     * exposes (which tokenizes multi-word values and cannot be aggregated
     * on at all without enabling fielddata). ['type' => 'filter'] gives
     * clean buckets (real option IDs, real counts) while ['type' => 'text']
     * throws an ES "not optimised for aggregations" error outright.
     *
     * @param string $code
     * @return string
     */
    public function getAggregationFieldName(string $code): string
    {
        return $this->fieldMapper->getFieldName($code, ['type' => 'filter']);
    }

    /**
     * @return array<string, array{es_field: string, weight: int, input: string, label: string}>
     */
    private function build(): array
    {
        $connection = $this->resource->getConnection();
        $entityTypeId = (int)$this->eavConfig->getEntityType(Product::ENTITY)->getId();

        $usedAttributeIds = [];
        foreach (self::VALUE_TABLES as $table) {
            $select = $connection->select()
                ->distinct()
                ->from($this->resource->getTableName($table), ['attribute_id'])
                ->where('value IS NOT NULL');
            foreach ($connection->fetchCol($select) as $id) {
                $usedAttributeIds[(int)$id] = true;
            }
        }

        $select = $connection->select()
            ->from(['ea' => $this->resource->getTableName('eav_attribute')], ['attribute_id', 'attribute_code', 'backend_type', 'frontend_input', 'frontend_label'])
            ->join(
                ['cea' => $this->resource->getTableName('catalog_eav_attribute')],
                'cea.attribute_id = ea.attribute_id',
                ['search_weight']
            )
            ->where('ea.entity_type_id = ?', $entityTypeId)
            ->where('cea.is_searchable = 1 OR cea.is_filterable > 0')
            ->where('ea.attribute_code NOT IN (?)', self::BLACKLIST)
            ->order('cea.search_weight DESC');

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            // Static attributes (sku) live as entity-table columns, not in
            // EAV value tables — the has-values check does not apply.
            if ($row['backend_type'] !== 'static' && !isset($usedAttributeIds[(int)$row['attribute_id']])) {
                continue;
            }
            $code = (string)$row['attribute_code'];
            $result[$code] = [
                'es_field' => (string)$this->fieldMapper->getFieldName($code, ['type' => 'text']),
                'weight' => max(1, (int)$row['search_weight']),
                'input' => (string)$row['frontend_input'],
                'label' => (string)($row['frontend_label'] ?? $code),
            ];
        }
        return $result;
    }
}
