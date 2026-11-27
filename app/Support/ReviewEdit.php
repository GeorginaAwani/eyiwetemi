<?php

namespace App\Support;

/**
 * Applies a reviewer's suggested edit to a draft: the deleted text in the anchored block becomes the inserted text.
 * Offsets are characters of the block's plain text, the way reviewers' anchors count them.
 */
class ReviewEdit
{
    /**
     * The document with the edit made, or null when the anchored block or the deleted text is not there any more.
     *
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $anchor
     * @return array<string, mixed>|null
     */
    public static function apply(array $doc, array $anchor, string $deleted, string $inserted): ?array
    {
        $blockId = (string) ($anchor['block_id'] ?? '');

        if ($blockId === '' || $deleted === '') {
            return null;
        }

        foreach ($doc['content'] ?? [] as $index => $block) {
            if ((string) ($block['attrs']['id'] ?? '') !== $blockId) {
                continue;
            }

            $from = self::locate(Prose::plain($block), $deleted, (int) ($anchor['start'] ?? 0));

            if ($from === null) {
                return null;
            }

            $offset = 0;
            $block['content'] = self::rewrite($block['content'] ?? [], $offset, $from, $from + mb_strlen($deleted), $inserted);

            if ($block['content'] === []) {
                unset($block['content']);
            }

            $doc['content'][$index] = $block;

            return $doc;
        }

        return null;
    }

    /**
     * Where a text sits in a block's characters: where the anchor says it starts when it is there, else its first
     * occurrence. Null when it is not there at all.
     */
    public static function locate(string $text, string $needle, int $preferred): ?int
    {
        if ($needle === '') {
            return null;
        }

        if ($preferred >= 0 && mb_substr($text, $preferred, mb_strlen($needle)) === $needle) {
            return $preferred;
        }

        $found = mb_strpos($text, $needle);

        return $found === false ? null : $found;
    }

    /**
     * Replaces the characters from $from to $to across text nodes in document order. The inserted text lands in the node
     * the replaced text starts in, so it takes that node's marks. Nodes left empty are dropped.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private static function rewrite(array $nodes, int &$offset, int $from, int $to, string $inserted): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'text') {
                $text = (string) ($node['text'] ?? '');
                $length = mb_strlen($text);
                $start = $offset;
                $offset += $length;

                $head = mb_substr($text, 0, min(max($from - $start, 0), $length));
                $tail = mb_substr($text, min(max($to - $start, 0), $length));
                $middle = $from >= $start && $from < $start + $length ? $inserted : '';
                $replaced = $head.$middle.$tail;

                if ($replaced !== '') {
                    $out[] = [...$node, 'text' => $replaced];
                }

                continue;
            }

            if (is_array($node['content'] ?? null)) {
                $node['content'] = self::rewrite($node['content'], $offset, $from, $to, $inserted);

                if ($node['content'] === []) {
                    unset($node['content']);
                }
            }

            $out[] = $node;
        }

        return $out;
    }
}
