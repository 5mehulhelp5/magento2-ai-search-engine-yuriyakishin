<?php
declare(strict_types=1);

namespace Yu\AiSearchEngine\Model\Indexer;

/**
 * Joins the text parts embedded per product, dropping empty ones. HTML
 * (descriptions are often WYSIWYG/Page Builder markup) is reduced to its
 * text — tags and inline CSS/JS would otherwise dominate the vector.
 * The result is capped: the embedding API rejects an over-long input
 * outright (failing the whole batch), so the tail is cut instead —
 * callers put the most meaningful parts first.
 */
class EmbeddingTextBuilder
{
    /**
     * Characters, not tokens: well under the embedding model's token
     * limit even for scripts that tokenize poorly.
     */
    public const MAX_LENGTH = 12000;

    /**
     * @param string[] $data
     * @return string
     */
    public function build(array $data): string
    {
        $parts = array_filter(
            array_map([$this, 'toPlainText'], $data),
            static fn(string $value): bool => $value !== ''
        );
        $text = implode("\n", $parts);
        return mb_strlen($text) > self::MAX_LENGTH ? rtrim(mb_substr($text, 0, self::MAX_LENGTH)) : $text;
    }

    private function toPlainText(string $value): string
    {
        if (str_contains($value, '<')) {
            $value = (string)preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $value);
            $value = strip_tags(str_replace('>', '> ', $value));
        }
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string)preg_replace('/[ \t\x{00A0}]+/u', ' ', $value);
        return trim((string)preg_replace('/\s*[\r\n]\s*/u', "\n", $value));
    }
}
