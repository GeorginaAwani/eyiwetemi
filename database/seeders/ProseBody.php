<?php

namespace Database\Seeders;

/**
 * Builds the ProseMirror/Tiptap documents stored in the *_body columns, and reads back their plain text,
 * word counts, word-level changes and comment anchors.
 */
final class ProseBody
{
    /**
     * Builds a document from block specs: ['p', text], ['quote', text], ['break'] or ['line', text, indent].
     *
     * @param  list<array{0: string, 1?: string, 2?: int}>  $blocks
     * @return array{type: string, content: list<array<string, mixed>>}
     */
    public static function document(string $key, array $blocks): array
    {
        $content = [];

        foreach ($blocks as $index => $block) {
            $id = self::blockId($key, $index);

            $content[] = match ($block[0]) {
                'p' => self::paragraph($id, $block[1]),
                'quote' => [
                    'type' => 'blockquote',
                    'attrs' => ['id' => $id],
                    'content' => [self::paragraph($id.'-q', $block[1])],
                ],
                'break' => ['type' => 'sceneBreak', 'attrs' => ['id' => $id]],
                'line' => self::poemLine($id, $block[1], $block[2] ?? 0),
            };
        }

        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * Plain text of a document: its blocks joined by a blank line, scene breaks left out.
     *
     * @param  array{type: string, content: list<array<string, mixed>>}  $document
     */
    public static function text(array $document): string
    {
        $parts = [];

        foreach ($document['content'] as $node) {
            $text = self::nodeText($node);

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode("\n\n", $parts);
    }

    public static function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Words added and cut going from one text to the next, from a word-level longest common subsequence.
     *
     * @return array{added: int, cut: int}
     */
    public static function wordChange(string $from, string $to): array
    {
        $before = preg_split('/\s+/u', trim($from), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $after = preg_split('/\s+/u', trim($to), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($before);
        $m = count($after);

        if ($n === 0 || $m === 0) {
            return ['added' => $m, 'cut' => $n];
        }

        $previous = array_fill(0, $m + 1, 0);

        for ($i = 1; $i <= $n; $i++) {
            $current = [0];

            for ($j = 1; $j <= $m; $j++) {
                $current[$j] = $before[$i - 1] === $after[$j - 1]
                    ? $previous[$j - 1] + 1
                    : max($previous[$j], $current[$j - 1]);
            }

            $previous = $current;
        }

        $shared = $previous[$m];

        return ['added' => $m - $shared, 'cut' => $n - $shared];
    }

    /**
     * Anchor for a comment on a quote: the top-level block that holds it, and the character range inside it.
     *
     * @param  array{type: string, content: list<array<string, mixed>>}  $document
     * @return array{block_id: string, start: int, end: int, quote: string}
     */
    public static function anchor(array $document, string $quote): array
    {
        foreach ($document['content'] as $node) {
            $text = self::nodeText($node);
            $position = mb_strpos($text, $quote);

            if ($position !== false) {
                return [
                    'block_id' => (string) $node['attrs']['id'],
                    'start' => $position,
                    'end' => $position + mb_strlen($quote),
                    'quote' => $quote,
                ];
            }
        }

        throw new \RuntimeException("Quote not found in document: {$quote}");
    }

    /**
     * @return array{type: string, attrs: array{id: string}, content?: list<array{type: string, text: string}>}
     */
    private static function paragraph(string $id, string $text): array
    {
        $block = ['type' => 'paragraph', 'attrs' => ['id' => $id]];

        if ($text !== '') {
            $block['content'] = [['type' => 'text', 'text' => $text]];
        }

        return $block;
    }

    /**
     * @return array<string, mixed>
     */
    private static function poemLine(string $id, string $text, int $indent): array
    {
        $block = ['type' => 'poemLine', 'attrs' => ['id' => $id, 'indent' => min(3, max(0, $indent))]];

        if ($text !== '') {
            $block['content'] = [['type' => 'text', 'text' => $text]];
        }

        return $block;
    }

    private static function blockId(string $key, int $index): string
    {
        return 'b'.substr(md5($key.'#'.$index), 0, 10);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function nodeText(array $node): string
    {
        if (($node['type'] ?? null) === 'text') {
            return (string) $node['text'];
        }

        $text = '';

        foreach ($node['content'] ?? [] as $child) {
            $text .= self::nodeText($child);
        }

        return $text;
    }
}
