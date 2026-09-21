<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model\Indexer;

/**
 * Joins the text parts embedded per product, dropping empty ones.
 */
class EmbeddingTextBuilder
{
    /**
     * @param string[] $data
     * @return string
     */
    public function build(array $data): string
    {
        $parts = array_filter(
            array_map('trim', $data),
            static fn(string $value): bool => $value !== ''
        );
        return implode("\n", $parts);
    }
}
