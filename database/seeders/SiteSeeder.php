<?php

namespace Database\Seeders;

use App\Enums\SitePageKey;
use App\Models\LinkCheck;
use App\Models\Media;
use App\Models\Notice;
use App\Models\SitePage;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Site pages and settings as she published them in the admin prototype (database/seeders/data/site.json):
 * About, In print, Privacy, contact and footer, links elsewhere, sharing, notices.
 */
class SiteSeeder extends Seeder
{
    private const WELCOME_LETTER = 'Thank you for being here. I write novels a chapter at a time, and the odd poem when it rains. Every Sunday I’ll send you whatever is new, and nothing when nothing is.';

    /** @var array<string, string> */
    private const CURRENCIES = ['₦' => 'NGN', '$' => 'USD', '£' => 'GBP', '€' => 'EUR'];

    private const MONTHS = ['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12];

    /** @var array<string, mixed> */
    private array $site = [];

    public function run(): void
    {
        $this->site = json_decode((string) file_get_contents(database_path('seeders/data/site.json')), true);

        $this->pages();
        $this->settings();
        $this->notices();
        $this->linkChecks();
    }

    private function pages(): void
    {
        $privacy = $this->contentsPage('privacy');
        $terms = $this->contentsPage('terms');

        $pages = [
            [SitePageKey::About, $this->about(), now()->subDays(1)],
            [SitePageKey::InPrint, $this->inPrint(), now()->subDays(4)],
            [SitePageKey::Privacy, $privacy, $this->wat($this->site['privacy']['upd'].' 09:00')],
            [SitePageKey::Terms, $terms, $this->wat($this->site['terms']['upd'].' 09:00')],
        ];

        foreach ($pages as [$key, $content, $publishedAt]) {
            SitePage::forceCreate([
                'key' => $key,
                'draft' => $content,
                'live' => $content,
                'draft_saved_at' => now()->subDays(1),
                'published_at' => $publishedAt,
            ]);
        }
    }

    /**
     * Privacy or Terms: her sections, with prototype page links turned into site addresses.
     *
     * @return array{updated_on: string, sections: list<array{id: string, heading: string, html: string}>}
     */
    private function contentsPage(string $key): array
    {
        return [
            'updated_on' => $this->site[$key]['upd'],
            'sections' => array_map(
                fn (array $section): array => [
                    'id' => $section['id'],
                    'heading' => $section['h'],
                    'html' => str_replace(['settings.html', 'privacy.html', 'terms.html'], ['/settings', '/privacy', '/terms'], $section['html']),
                ],
                $this->site[$key]['secs'],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function about(): array
    {
        $about = $this->site['about'];

        return [
            'image' => $this->picture($about['img'] ?? null),
            'hello' => ['heading' => $about['hello']['h'], 'paragraphs' => $about['hello']['ps']],
            'sections' => array_map(fn (array $section): array => [
                'id' => $section['id'],
                'heading' => $section['h'],
                'paragraphs' => array_map(fn (array $paragraph): array => [
                    'text' => $paragraph['t'],
                    'margin_note' => ($paragraph['n'] ?? '') !== '' ? $paragraph['n'] : null,
                    'pull_quote' => (bool) ($paragraph['q'] ?? false),
                ], $section['ps']),
            ], $about['secs']),
            'books' => $about['books'],
            'home' => ['quote' => $about['home']['q'], 'line' => $about['home']['l']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inPrint(): array
    {
        $print = $this->site['print'];

        return [
            'books' => array_map(fn (array $book): array => [
                'slug' => $book['slug'],
                'title' => $book['title'],
                'kind' => $book['kind'],
                'cover' => $book['cover'],
                'formats' => array_map(fn (array $format): array => [
                    'format' => $format['format'],
                    'stores' => array_map(fn (array $store): array => [
                        'store' => $store['store'],
                        'price' => $this->priceOf($store['price'], $store['currency']),
                        'url' => $this->url($store['url']),
                        'ok' => $store['ok'],
                    ], $format['stores']),
                ], $book['formats']),
            ], $print['books']),
            'coming' => array_map(fn (array $book): array => [
                'slug' => $book['slug'],
                'title' => $book['title'],
                'kind' => $book['kind'],
                'cover' => $book['cover'],
                'when' => $book['when'],
                'mode' => $book['mode'],
                'store' => $book['store'],
                'price' => $book['price'] !== null ? $this->priceOf($book['price'], $book['currency']) : null,
                'url' => $this->url($book['url']),
            ], $print['coming']),
            'events' => array_map(fn (array $event): array => [
                'id' => $event['id'],
                'title' => $event['title'],
                'starts_at' => Carbon::createFromFormat('Y-m-d H:i', $event['date'].' '.$event['time'], 'Africa/Lagos')
                    ->utc()->toIso8601String(),
                'place' => $event['place'],
                'online' => $event['online'],
                'url' => $this->url($event['url']),
                'cancelled' => $event['cancelled'],
            ], $print['events']),
        ];
    }

    /**
     * @return array{amount: int, currency: string}
     */
    private function priceOf(string $price, string $symbol): array
    {
        $major = (float) str_replace(',', '', $price);

        return [
            'amount' => (int) round($major * 100),
            'currency' => self::CURRENCIES[$symbol] ?? 'USD',
        ];
    }

    private function url(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        return preg_match('#^https?://#', $url) ? $url : 'https://'.$url;
    }

    private function settings(): void
    {
        $contact = $this->site['contact'];
        $share = $this->site['share']['site'];

        SiteSetting::put('contact', ['email' => $contact['email']]);
        SiteSetting::put('footer', ['line' => $contact['line']]);
        SiteSetting::put('links', array_map(
            fn (array $link): array => ['network' => $link[0], 'handle' => $link[1]],
            $contact['links'],
        ));
        SiteSetting::put('home_pin', $this->site['pin'] !== null
            ? ['slug' => $this->site['pin']['slug'], 'until' => $this->site['pin']['until'] ?? null]
            : null);
        SiteSetting::put('sharing', [
            'site' => ['own' => (bool) $share['own'], 'line' => $share['line'], 'image' => $this->picture($share['img'] ?? null)],
            'works' => [],
        ]);
        SiteSetting::put('newsletter', ['weekday' => 0, 'time' => '09:00']);
        SiteSetting::put('welcome', [
            'letter' => self::WELCOME_LETTER,
            'works' => ['the-ember-gate', 'salt-season', 'night-bus'],
        ]);
    }

    /**
     * The notice that is up (none, as she left it) and the earlier ones with how many readers closed them.
     */
    private function notices(): void
    {
        $notice = $this->site['notice'];

        if ($notice !== null) {
            Notice::forceCreate([
                'text' => $notice['text'],
                'link_target' => null,
                'link_url' => null,
                'starts_at' => now()->subDay(),
                'ends_at' => null,
                'taken_down_at' => null,
                'closed_count' => 0,
            ]);
        }

        foreach ($this->site['past'] as $past) {
            [$startsAt, $endsAt] = $this->range($past['when']);

            Notice::forceCreate([
                'text' => $past['text'],
                'link_target' => null,
                'link_url' => null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'taken_down_at' => $endsAt,
                'closed_count' => (int) $past['x'],
            ]);
        }
    }

    /**
     * "1–14 Nov" or "25 Nov–25 Nov" as WAT days, this year.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(string $when): array
    {
        preg_match('/^(\d{1,2})(?: ([A-Za-z]{3}))?–(\d{1,2}) ([A-Za-z]{3})$/u', $when, $m);
        $year = 2026;
        $endMonth = self::MONTHS[$m[4]];
        $startMonth = ($m[2] ?? '') !== '' ? self::MONTHS[$m[2]] : $endMonth;

        return [
            $this->wat(sprintf('%d-%02d-%02d 00:00', $year, $startMonth, $m[1])),
            $this->wat(sprintf('%d-%02d-%02d 23:59', $year, $endMonth, $m[3])),
        ];
    }

    /**
     * A picture she uploaded, if it's on this machine (her own photos aren't kept in the public repo).
     */
    private function picture(?string $path): ?int
    {
        if ($path === null || ! Storage::disk(config('eyiwetemi.media_disk'))->exists($path)) {
            return null;
        }

        return Media::forceCreate([
            'disk' => config('eyiwetemi.media_disk'),
            'path' => $path,
            'original_name' => basename($path),
            'mime' => 'image/jpeg',
            'size' => Storage::disk(config('eyiwetemi.media_disk'))->size($path),
            'uploaded_by' => User::query()->where('username', 'eyiwetemi')->value('id'),
        ])->id;
    }

    /**
     * The last check of each store link, as her store lists record it.
     */
    private function linkChecks(): void
    {
        foreach ($this->site['print']['books'] as $book) {
            foreach ($book['formats'] as $format) {
                foreach ($format['stores'] as $store) {
                    $url = $this->url($store['url']);

                    LinkCheck::forceCreate([
                        'url_hash' => hash('sha256', $url),
                        'url' => $url,
                        'ok' => $store['ok'],
                        'status_code' => $store['ok'] ? 200 : 404,
                        'checked_at' => now()->subDay(),
                    ]);
                }
            }
        }
    }

    private function wat(string $local): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i', $local, 'Africa/Lagos')->utc();
    }
}
