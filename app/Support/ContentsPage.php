<?php

namespace App\Support;

use App\Enums\SitePageKey;
use App\Models\SitePage;
use Illuminate\Support\Carbon;

/**
 * Privacy and Terms: her sections, with the contents beside them and the day they were last updated. A change that
 * matters shows under the date for a month, with the sections it touched marked in the contents.
 */
class ContentsPage
{
    /** How long a change that matters is shown on the page. */
    private const CHANGE_DAYS = 30;

    /**
     * The live page as page props, or null when it isn't published.
     *
     * @return array{updated: string|null, sections: list<array{id: string, heading: string, html: string}>, change: array{line: string, ids: list<string>}|null}|null
     */
    public static function props(SitePageKey $key): ?array
    {
        $live = SitePage::query()->firstWhere('key', $key)?->live;

        if (! is_array($live) || empty($live['sections'])) {
            return null;
        }

        return [
            'updated' => isset($live['updated_on']) ? Carbon::parse($live['updated_on'])->format('j F Y') : null,
            'sections' => array_values(array_map(fn (array $section): array => [
                'id' => (string) $section['id'],
                'heading' => (string) $section['heading'],
                'html' => SafeHtml::clean($section['html'] ?? ''),
            ], $live['sections'])),
            'change' => self::change($live['change'] ?? null),
        ];
    }

    /**
     * The line and the sections of a change that matters, while it is recent: from the day it was made, for 30 days.
     *
     * @return array{line: string, ids: list<string>}|null
     */
    private static function change(mixed $change): ?array
    {
        $line = is_array($change) ? trim((string) ($change['line'] ?? '')) : '';

        if ($line === '' || empty($change['on'])) {
            return null;
        }

        $zone = (string) config('eyiwetemi.timezone');
        $on = Carbon::parse((string) $change['on'], $zone)->startOfDay();
        $today = now($zone)->startOfDay();

        if ($on->lt($today->copy()->subDays(self::CHANGE_DAYS)) || $on->gt($today)) {
            return null;
        }

        return ['line' => $line, 'ids' => array_values(array_map('strval', $change['ids'] ?? []))];
    }
}
