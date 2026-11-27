<?php

namespace App\Support;

/**
 * The writing screen's documents (ProseMirror JSON, as stored in draft_body / live_body): what may be in them,
 * their plain text and their word count.
 *
 * Blocks: paragraph, poemLine (indent 0–3), blockquote (one paragraph), sceneBreak, bulletList > listItem > paragraph,
 * image (mediaId, credit). Inline: text with italic / bold / link marks, hardBreak, footnote (its note in attrs.text).
 */
class Prose
{
    /** @var array<string, list<string>> each node type and the attributes it keeps */
    private const NODES = [
        'doc' => [],
        'paragraph' => ['id'],
        'poemLine' => ['id', 'indent'],
        'blockquote' => ['id'],
        'sceneBreak' => ['id'],
        'bulletList' => ['id'],
        'listItem' => [],
        'image' => ['id', 'mediaId', 'credit'],
        'text' => [],
        'hardBreak' => [],
        'footnote' => ['id', 'text'],
    ];

    private const MARKS = ['italic', 'bold', 'link'];

    /**
     * An empty page: one empty paragraph.
     *
     * @return array<string, mixed>
     */
    public static function empty(): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['id' => self::newId()]]]];
    }

    public static function newId(): string
    {
        return 'b'.bin2hex(random_bytes(5));
    }

    /**
     * Keep only what the editor makes: known nodes, their own attributes, italic / bold / safe links.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    public static function clean(array $node): ?array
    {
        $type = $node['type'] ?? null;

        if (! is_string($type) || ! array_key_exists($type, self::NODES)) {
            return null;
        }

        $out = ['type' => $type];

        if ($type === 'text') {
            $text = is_string($node['text'] ?? null) ? $node['text'] : '';

            if ($text === '') {
                return null;
            }

            $out['text'] = $text;
            $marks = self::marks($node['marks'] ?? []);

            if ($marks !== []) {
                $out['marks'] = $marks;
            }

            return $out;
        }

        $attrs = [];

        foreach (self::NODES[$type] as $name) {
            $value = $node['attrs'][$name] ?? null;

            $attrs[$name] = match ($name) {
                'id' => is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $value) ? $value : self::newId(),
                'indent' => max(0, min(3, (int) $value)),
                'mediaId' => is_numeric($value) ? (int) $value : null,
                'credit', 'text' => is_string($value) ? mb_substr($value, 0, 2000) : '',
                default => $value,
            };
        }

        if ($attrs !== []) {
            $out['attrs'] = $attrs;
        }

        $children = [];

        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            $clean = is_array($child) ? self::clean($child) : null;

            if ($clean !== null) {
                $children[] = $clean;
            }
        }

        if ($children !== []) {
            $out['content'] = $children;
        }

        return $out;
    }

    /**
     * Plain text: blocks joined by a blank line (as the search and the reading-time count read it), footnotes left out.
     *
     * @param  array<string, mixed>|null  $doc
     */
    public static function text(?array $doc): string
    {
        $parts = [];

        foreach ($doc['content'] ?? [] as $node) {
            $parts[] = self::inline($node);
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * A node's text alone: its text nodes one after another, with nothing between them. Reviewers' marks count their
     * anchors in these characters.
     *
     * @param  array<string, mixed>  $node
     */
    public static function plain(array $node): string
    {
        if (($node['type'] ?? null) === 'text') {
            return (string) ($node['text'] ?? '');
        }

        $text = '';

        foreach ($node['content'] ?? [] as $child) {
            $text .= self::plain($child);
        }

        return $text;
    }

    /**
     * Words, footnotes included (they are printed too).
     *
     * @param  array<string, mixed>|null  $doc
     */
    public static function words(?array $doc): int
    {
        return self::count(self::text($doc)) + array_sum(array_map(self::count(...), self::footnotes($doc)));
    }

    public static function count(string $text): int
    {
        return preg_match_all("/[\\p{L}\\p{N}’']+/u", $text);
    }

    /**
     * @param  array<string, mixed>|null  $doc
     * @return list<string>
     */
    public static function footnotes(?array $doc): array
    {
        $notes = [];
        $walk = function (array $node) use (&$walk, &$notes): void {
            if (($node['type'] ?? null) === 'footnote') {
                $notes[] = (string) ($node['attrs']['text'] ?? '');
            }
            foreach ($node['content'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($doc ?? []);

        return $notes;
    }

    /**
     * Words added and cut going from one text to the next (a word-level longest common subsequence;
     * very long texts fall back to the difference in length).
     *
     * @return array{added: int, cut: int}
     */
    public static function wordChange(string $from, string $to): array
    {
        $before = preg_split('/\s+/u', trim($from), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $after = preg_split('/\s+/u', trim($to), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($before);
        $m = count($after);

        if ($n === 0 || $m === 0 || $n * $m > 30_000_000) {
            return $n === 0 || $m === 0 ? ['added' => $m, 'cut' => $n] : ['added' => max(0, $m - $n), 'cut' => max(0, $n - $m)];
        }

        $previous = array_fill(0, $m + 1, 0);

        for ($i = 1; $i <= $n; $i++) {
            $current = [0];

            for ($j = 1; $j <= $m; $j++) {
                $current[$j] = $before[$i - 1] === $after[$j - 1] ? $previous[$j - 1] + 1 : max($previous[$j], $current[$j - 1]);
            }

            $previous = $current;
        }

        return ['added' => $m - $previous[$m], 'cut' => $n - $previous[$m]];
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function inline(array $node): string
    {
        return match ($node['type'] ?? null) {
            'text' => (string) ($node['text'] ?? ''),
            'hardBreak' => "\n",
            'footnote', 'image', 'sceneBreak' => '',
            'bulletList' => implode("\n", array_map(self::inline(...), $node['content'] ?? [])),
            default => implode('', array_map(self::inline(...), $node['content'] ?? [])),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function marks(mixed $marks): array
    {
        $out = [];

        foreach (is_array($marks) ? $marks : [] as $mark) {
            $type = $mark['type'] ?? null;

            if (! in_array($type, self::MARKS, true)) {
                continue;
            }

            if ($type === 'link') {
                $href = trim((string) ($mark['attrs']['href'] ?? ''));

                if (! preg_match('#^(https?://|mailto:)#i', $href)) {
                    continue;
                }

                $out[] = ['type' => 'link', 'attrs' => ['href' => mb_substr($href, 0, 2000)]];

                continue;
            }

            $out[] = ['type' => $type];
        }

        return $out;
    }
}
