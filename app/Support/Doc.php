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
     * @return list<array{text: string, em?: bool, strong?: bool, href?: string}>
     */
    public static function runs(array $node): array
    {
        $runs = [];

        foreach ($node['content'] ?? [] as $child) {
            if (($child['type'] ?? '') === 'hardBreak') {
                $runs[] = ['text' => "\n"];

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
}
