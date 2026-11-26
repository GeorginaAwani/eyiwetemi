<?php

namespace App\Support;

/**
 * Reads the editor's JSON documents (live_body) into plain shapes the pages render.
 */
class Doc
{
    /**
     * A block's text as runs with their marks (italic, bold, link).
     *
     * @param  array<string, mixed>  $node
     * @return list<array{text: string, em?: bool, strong?: bool, href?: string, fn?: int, note?: string}>
     */
    public static function runs(array $node): array
    {
        $runs = [];

        foreach ($node['content'] ?? [] as $child) {
            if (($child['type'] ?? '') === 'hardBreak') {
                $runs[] = ['text' => "\n"];

                continue;
            }

            /* a footnote: its number is given once the page's blocks are put together (numberFootnotes) */
            if (($child['type'] ?? '') === 'footnote') {
                $runs[] = ['text' => '', 'fn' => 0, 'note' => (string) ($child['attrs']['text'] ?? '')];

                continue;
            }

            if (! isset($child['text'])) {
                array_push($runs, ...self::runs($child));

                continue;
            }

            $run = ['text' => (string) $child['text']];
            foreach ($child['marks'] ?? [] as $mark) {
                match ($mark['type'] ?? '') {
                    'italic' => $run['em'] = true,
                    'bold' => $run['strong'] = true,
                    'link' => $run['href'] = (string) ($mark['attrs']['href'] ?? ''),
                    default => null,
                };
            }
            $runs[] = $run;
        }

        return $runs;
    }

    /**
     * The document's paragraphs, each as runs; empty ones are dropped.
     *
     * @param  array<string, mixed>|null  $doc
     * @return list<list<array{text: string, em?: bool, strong?: bool, href?: string}>>
     */
    public static function paragraphs(?array $doc): array
    {
        $paragraphs = [];

        foreach ($doc['content'] ?? [] as $node) {
            $runs = self::runs($node);

            if (trim(implode('', array_column($runs, 'text'))) !== '') {
                $paragraphs[] = $runs;
            }
        }

        return $paragraphs;
    }

    /**
     * Number a page's footnotes in reading order (1, 2, 3…); empty notes are left out.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    public static function numberFootnotes(array $blocks): array
    {
        $n = 0;
        $number = function (array $runs) use (&$n): array {
            $out = [];
            foreach ($runs as $run) {
                if (array_key_exists('fn', $run)) {
                    if (trim((string) $run['note']) === '') {
                        continue;
                    }
                    $run['fn'] = ++$n;
                }
                $out[] = $run;
            }

            return $out;
        };

        foreach ($blocks as &$block) {
            if (isset($block['runs'])) {
                $block['runs'] = $number($block['runs']);
            }
            if (isset($block['items'])) {
                $block['items'] = array_map($number, $block['items']);
            }
        }
        unset($block);

        return $blocks;
    }
}
