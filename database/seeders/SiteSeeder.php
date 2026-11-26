<?php

namespace Database\Seeders;

use App\Enums\SitePageKey;
use App\Models\LinkCheck;
use App\Models\Notice;
use App\Models\SitePage;
use App\Models\SiteSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class SiteSeeder extends Seeder
{
    private const WELCOME_LETTER = 'Thank you for being here. I write novels a chapter at a time, and the odd poem when it rains. Every Sunday I’ll send you whatever is new, and nothing when nothing is.';

    /**
     * In print: the books on sale, the books coming, and the reading events. Prices are the prototype's strings,
     * stored as minor units by priceOf().
     *
     * @var array{books: list<array<string, mixed>>, coming: list<array<string, mixed>>, events: list<array<string, mixed>>}
     */
    private const PRINT = [
        'books' => [
            [
                'slug' => 'salt-season', 'title' => 'Salt Season', 'kind' => 'A novel', 'cover' => '#C24E77',
                'formats' => [
                    ['format' => 'Paperback', 'stores' => [
                        ['store' => 'Roving Heights', 'price' => '8,500', 'currency' => '₦', 'url' => 'rovingheights.com/salt-season', 'ok' => true],
                        ['store' => 'Amazon', 'price' => '12.99', 'currency' => '$', 'url' => 'amazon.com/dp/B0CSALT01', 'ok' => true],
                        ['store' => 'Jumia', 'price' => '9,000', 'currency' => '₦', 'url' => 'jumia.com.ng/salt-season', 'ok' => true],
                    ]],
                    ['format' => 'Hardback', 'stores' => [
                        ['store' => 'Amazon', 'price' => '24.99', 'currency' => '$', 'url' => 'amazon.com/dp/B0CSALT02', 'ok' => true],
                    ]],
                    ['format' => 'Ebook', 'stores' => [
                        ['store' => 'Okada Books', 'price' => '3,000', 'currency' => '₦', 'url' => 'okadabooks.com/salt-season', 'ok' => true],
                        ['store' => 'Kindle', 'price' => '4.99', 'currency' => '$', 'url' => 'amazon.com/dp/B0CSALTK1', 'ok' => true],
                    ]],
                    ['format' => 'Signed', 'stores' => [
                        ['store' => 'Roving Heights', 'price' => '10,500', 'currency' => '₦', 'url' => 'rovingheights.com/salt-season-signed', 'ok' => true],
                    ]],
                ],
            ],
            [
                'slug' => 'rain-stories', 'title' => 'Rain Stories', 'kind' => 'Short stories', 'cover' => '#7E3550',
                'formats' => [
                    ['format' => 'Paperback', 'stores' => [
                        ['store' => 'Roving Heights', 'price' => '7,000', 'currency' => '₦', 'url' => 'rovingheights.com/rain-stories', 'ok' => true],
                        ['store' => 'Amazon', 'price' => '9.99', 'currency' => '$', 'url' => 'amazon.com/dp/B0CRAIN01', 'ok' => false],
                    ]],
                    ['format' => 'Ebook', 'stores' => [
                        ['store' => 'Okada Books', 'price' => '2,500', 'currency' => '₦', 'url' => 'okadabooks.com/rain-stories', 'ok' => true],
                    ]],
                ],
            ],
        ],
        'coming' => [
            [
                'slug' => 'paper-boats', 'title' => 'Paper Boats', 'kind' => 'A novel', 'cover' => '#977AA0',
                'when' => 'March 2027', 'mode' => 'pre', 'store' => 'Roving Heights', 'price' => '8,000', 'currency' => '₦',
                'url' => 'rovingheights.com/paper-boats',
            ],
            [
                'slug' => 'midnight-poems', 'title' => 'Midnight Poems', 'kind' => 'Poems', 'cover' => '#A24F68',
                'when' => '2027', 'mode' => 'follow', 'store' => null, 'price' => null, 'currency' => null, 'url' => null,
            ],
        ],
        'events' => [
            ['id' => 'e1', 'date' => '2026-12-06', 'time' => '16:00', 'title' => 'Salt Season, read aloud', 'online' => false, 'place' => 'Port Harcourt', 'url' => 'rovingheights.com/events', 'cancelled' => false],
            ['id' => 'e2', 'date' => '2026-12-19', 'time' => '19:00', 'title' => 'Midnight, live', 'online' => true, 'place' => null, 'url' => 'youtube.com/live/eyiwetemi', 'cancelled' => false],
            ['id' => 'e3', 'date' => '2027-02-14', 'time' => '11:00', 'title' => 'Ake Arts and Book Festival', 'online' => false, 'place' => 'Abeokuta', 'url' => 'akefestival.org', 'cancelled' => false],
            ['id' => 'p1', 'date' => '2026-11-16', 'time' => '14:00', 'title' => 'Port Harcourt Book Festival', 'online' => false, 'place' => 'Port Harcourt', 'url' => null, 'cancelled' => false],
            ['id' => 'p2', 'date' => '2026-09-21', 'time' => '16:00', 'title' => 'Rain Stories launch', 'online' => false, 'place' => 'Warri', 'url' => null, 'cancelled' => false],
            ['id' => 'p3', 'date' => '2026-05-03', 'time' => '16:00', 'title' => 'Salt Season launch', 'online' => false, 'place' => 'Port Harcourt', 'url' => null, 'cancelled' => false],
            ['id' => 'p4', 'date' => '2026-04-12', 'time' => '19:00', 'title' => 'Poetry night at Bogobiri', 'online' => false, 'place' => 'Lagos', 'url' => null, 'cancelled' => true],
            ['id' => 'p5', 'date' => '2025-12-13', 'time' => '17:00', 'title' => 'Christmas readings, Roving Heights', 'online' => false, 'place' => 'Port Harcourt', 'url' => null, 'cancelled' => false],
            ['id' => 'p6', 'date' => '2025-10-04', 'time' => '15:00', 'title' => 'Warri Arts Day', 'online' => false, 'place' => 'Warri', 'url' => null, 'cancelled' => false],
            ['id' => 'p7', 'date' => '2025-06-21', 'time' => '12:00', 'title' => 'Library afternoon', 'online' => false, 'place' => 'Port Harcourt', 'url' => null, 'cancelled' => false],
            ['id' => 'p8', 'date' => '2025-02-14', 'time' => '18:00', 'title' => 'Love letters, read aloud', 'online' => true, 'place' => null, 'url' => null, 'cancelled' => false],
            ['id' => 'p9', 'date' => '2024-11-30', 'time' => '16:00', 'title' => 'First reading', 'online' => false, 'place' => 'Port Harcourt', 'url' => null, 'cancelled' => false],
        ],
    ];

    public function run(): void
    {
        $this->pages();
        $this->settings();
        $this->notices();
        $this->linkChecks();
    }

    private function pages(): void
    {
        $about = $this->about();
        $inPrint = $this->inPrint();
        $privacy = [
            'updated_on' => SeedData::PRIVACY['upd'],
            'sections' => array_map(
                fn (array $section): array => ['id' => $section['id'], 'heading' => $section['h'], 'html' => $section['html']],
                SeedData::PRIVACY['secs'],
            ),
        ];

        $pages = [
            [SitePageKey::About, $about, now()->subDays(20)],
            [SitePageKey::InPrint, $inPrint, now()->subDays(4)],
            [SitePageKey::Privacy, $privacy, $this->wat('2026-11-23 09:00')],
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
     * @return array<string, mixed>
     */
    private function about(): array
    {
        $about = SeedData::ABOUT;

        return [
            'image' => null,
            'hello' => ['heading' => $about['hello']['h'], 'paragraphs' => $about['hello']['ps']],
            'sections' => array_map(fn (array $section): array => [
                'id' => $section['id'],
                'heading' => $section['h'],
                'paragraphs' => array_map(fn (array $paragraph): array => [
                    'text' => $paragraph['t'],
                    'margin_note' => $paragraph['n'] ?? null,
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
                        'url' => 'https://'.$store['url'],
                        'ok' => $store['ok'],
                    ], $format['stores']),
                ], $book['formats']),
            ], self::PRINT['books']),
            'coming' => array_map(fn (array $book): array => [
                'slug' => $book['slug'],
                'title' => $book['title'],
                'kind' => $book['kind'],
                'cover' => $book['cover'],
                'when' => $book['when'],
                'mode' => $book['mode'],
                'store' => $book['store'],
                'price' => $book['price'] !== null ? $this->priceOf($book['price'], $book['currency']) : null,
                'url' => $book['url'] !== null ? 'https://'.$book['url'] : null,
            ], self::PRINT['coming']),
            'events' => array_map(fn (array $event): array => [
                'id' => $event['id'],
                'title' => $event['title'],
                'starts_at' => Carbon::createFromFormat('Y-m-d H:i', $event['date'].' '.$event['time'], 'Africa/Lagos')
                    ->utc()->toIso8601String(),
                'place' => $event['place'],
                'online' => $event['online'],
                'url' => $event['url'] !== null ? 'https://'.$event['url'] : null,
                'cancelled' => $event['cancelled'],
            ], self::PRINT['events']),
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
            'currency' => $symbol === '₦' ? 'NGN' : 'USD',
        ];
    }

    private function settings(): void
    {
        SiteSetting::put('contact', ['email' => SeedData::CONTACT['email']]);
        SiteSetting::put('footer', ['line' => SeedData::CONTACT['line']]);
        SiteSetting::put('links', array_map(
            fn (array $link): array => ['network' => $link[0], 'handle' => $link[1]],
            SeedData::CONTACT['links'],
        ));
        SiteSetting::put('home_pin', ['slug' => 'the-ember-gate', 'label' => 'Chapter 7 · 19 Nov', 'until' => null]);
        SiteSetting::put('sharing', [
            'site' => ['own' => false, 'line' => 'Novels, short stories, poems and essays, free to read.'],
            'works' => [],
        ]);
        SiteSetting::put('newsletter', ['weekday' => 0, 'time' => '09:00']);
        SiteSetting::put('welcome', [
            'letter' => self::WELCOME_LETTER,
            'works' => ['the-ember-gate', 'salt-season', 'night-bus'],
        ]);
    }

    private function notices(): void
    {
        Notice::forceCreate([
            'text' => 'Salt Season is now in print in the UK, with a new afterword.',
            'link_target' => 'in_print',
            'link_url' => null,
            'starts_at' => $this->wat('2026-11-21 09:00'),
            'ends_at' => null,
            'taken_down_at' => null,
            'closed_count' => 0,
        ]);

        $past = [
            ['2026-11-01 00:00', '2026-11-14 23:59', 'Salt Season is now in print in the UK', 412],
            ['2026-05-03 00:00', '2026-05-10 23:59', 'Salt Season launch, Port Harcourt, 3 May', 188],
        ];

        foreach ($past as [$startsAt, $endsAt, $text, $closed]) {
            Notice::forceCreate([
                'text' => $text,
                'link_target' => null,
                'link_url' => null,
                'starts_at' => $this->wat($startsAt),
                'ends_at' => $this->wat($endsAt),
                'taken_down_at' => $this->wat($endsAt),
                'closed_count' => $closed,
            ]);
        }
    }

    /**
     * The last check of each store link, as the prototype's store lists record it.
     */
    private function linkChecks(): void
    {
        foreach (self::PRINT['books'] as $book) {
            foreach ($book['formats'] as $format) {
                foreach ($format['stores'] as $store) {
                    $url = 'https://'.$store['url'];

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
