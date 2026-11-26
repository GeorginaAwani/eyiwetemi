<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class Reading
{
    /** Words a reader gets through in a minute. */
    public const WORDS_PER_MINUTE = 230;

    /**
     * Minutes to read this many words (at least one).
     */
    public static function minutes(int $words): int
    {
        return max(1, (int) ceil($words / self::WORDS_PER_MINUTE));
    }

    /**
     * A reading time as the site writes it: "24 min", "1 h 35 min", "2 h".
     */
    public static function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? $hours.' h' : $hours.' h '.$rest.' min';
    }

    /**
     * A day as the site writes it, in West Africa Time: "19 Nov", with the year when it isn't this year.
     */
    public static function day(?CarbonInterface $at): ?string
    {
        if ($at === null) {
            return null;
        }

        $local = $at->copy()->setTimezone(config('eyiwetemi.timezone'));
        $thisYear = now(config('eyiwetemi.timezone'))->year;

        return $local->year === $thisYear ? $local->format('j M') : $local->format('j M Y');
    }

    /**
     * A month as the site writes it: "Aug", with the year when it isn't this year.
     */
    public static function month(?CarbonInterface $at): ?string
    {
        if ($at === null) {
            return null;
        }

        $local = $at->copy()->setTimezone(config('eyiwetemi.timezone'));
        $thisYear = now(config('eyiwetemi.timezone'))->year;

        return $local->year === $thisYear ? $local->format('M') : $local->format('M Y');
    }

    /**
     * The first paragraph of an editor document, as plain text.
     *
     * @param  array<string, mixed>|null  $body
     */
    public static function firstParagraph(?array $body): ?string
    {
        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'paragraph') {
                $text = trim(self::text($block));

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * The first sentence of a piece of text.
     */
    public static function firstSentence(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $line = trim(Str::before(trim($text), "\n"));

        if (preg_match('/^.+?[.!?…](?=\s|$)/u', $line, $match)) {
            return $match[0];
        }

        return $line;
    }

    /**
     * A note's opening as shown beside a work: its first sentence, and the second too when both fit (90 characters).
     */
    public static function noteOpening(?string $text): string
    {
        $first = (string) self::firstSentence($text);
        $rest = trim(mb_substr(trim((string) $text), mb_strlen($first)));
        $second = self::firstSentence($rest);

        return $second && mb_strlen($first.' '.$second) <= 90 ? $first.' '.$second : $first;
    }

    /**
     * A poem's first stanza: its lines up to the first empty line, with their indents.
     *
     * @param  array<string, mixed>|null  $body
     * @return list<array{text: string, indent: int}>
     */
    public static function firstStanza(?array $body): array
    {
        $lines = [];

        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? null) !== 'poemLine') {
                continue;
            }

            $text = self::text($block);

            if (trim($text) === '') {
                if ($lines !== []) {
                    break;
                }

                continue;
            }

            $lines[] = ['text' => $text, 'indent' => (int) ($block['attrs']['indent'] ?? 0)];
        }

        return $lines;
    }

    /**
     * Plain text of one editor node and its children.
     *
     * @param  array<string, mixed>  $node
     */
    public static function text(array $node): string
    {
        if (isset($node['text'])) {
            return (string) $node['text'];
        }

        return implode('', array_map(fn (array $child): string => self::text($child), $node['content'] ?? []));
    }
}
