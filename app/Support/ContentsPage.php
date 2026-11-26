<?php

namespace App\Support;

use App\Enums\SitePageKey;
use App\Models\SitePage;
use Illuminate\Support\Carbon;

/**
 * Privacy and Terms: her sections, with the contents beside them and the day they were last updated.
 */
class ContentsPage
{
    /**
     * The live page as page props, or null when it isn't published.
     *
     * @return array{updated: string|null, sections: list<array{id: string, heading: string, html: string}>}|null
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
        ];
    }
}
