<?php

namespace App\Support;

use App\Enums\SitePageKey;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * What a site page's draft may hold, by page: the rules her saves are checked against, the shape the draft is kept in
 * (only the keys each page knows), and an empty page to start from. Privacy and Terms sections are HTML from her
 * editor, cleaned on the way in.
 */
class SitePageDraft
{
    private const FORMATS = ['Paperback', 'Hardback', 'Ebook', 'Audiobook', 'Signed'];

    private const CURRENCIES = ['NGN', 'USD', 'GBP', 'EUR'];

    /**
     * A page with nothing on it yet, as the page's editor starts from.
     *
     * @return array<string, mixed>
     */
    public static function empty(SitePageKey $key): array
    {
        return match ($key) {
            SitePageKey::About => [
                'image' => null,
                'hello' => ['heading' => '', 'paragraphs' => []],
                'sections' => [],
                'books' => '',
                'home' => ['quote' => '', 'line' => ''],
            ],
            SitePageKey::InPrint => ['books' => [], 'coming' => [], 'events' => []],
            SitePageKey::Privacy, SitePageKey::Terms => [
                'updated_on' => now(self::zone())->format('Y-m-d'),
                'sections' => [],
                'change' => null,
            ],
        };
    }

    /**
     * A stored page with the keys it may have missed filled in (a seeded page has no change, a seeded coming book no out).
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public static function complete(SitePageKey $key, array $stored): array
    {
        $page = array_replace(self::empty($key), $stored);

        if ($key === SitePageKey::InPrint) {
            $page['coming'] = array_map(fn (array $book): array => ['out' => null, ...$book], $page['coming']);
        }

        return $page;
    }

    /**
     * The validation rules for a save of the page's draft (under the key draft).
     *
     * @return array<string, array<int, mixed>|string>
     */
    public static function rules(SitePageKey $key): array
    {
        return match ($key) {
            SitePageKey::About => self::aboutRules(),
            SitePageKey::InPrint => self::inPrintRules(),
            SitePageKey::Privacy, SitePageKey::Terms => self::contentsRules(),
        };
    }

    /**
     * What is saved as typed, before it is checked: web addresses on In print get their https:// when she left it off.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public static function prepare(SitePageKey $key, array $draft): array
    {
        if ($key !== SitePageKey::InPrint) {
            return $draft;
        }

        foreach (self::listOf($draft, 'books') as $b => $book) {
            foreach (self::listOf($book, 'formats') as $f => $format) {
                foreach (self::listOf($format, 'stores') as $s => $store) {
                    $draft['books'][$b]['formats'][$f]['stores'][$s]['url'] = self::address($store['url'] ?? null);
                }
            }
        }

        foreach (self::listOf($draft, 'coming') as $c => $book) {
            $draft['coming'][$c]['url'] = self::address($book['url'] ?? null);
        }

        return $draft;
    }

    /**
     * A web address as she means it: trimmed, with https:// in front when she left the scheme off. Empty is none.
     */
    public static function address(mixed $url): mixed
    {
        if (! is_string($url)) {
            return $url;
        }

        $url = trim($url);

        if ($url === '') {
            return null;
        }

        return preg_match('#^https?://#i', $url) === 1 ? $url : 'https://'.$url;
    }

    /**
     * A checked draft, in the shape it is stored. Only the keys the page knows are kept.
     *
     * @param  array<string, mixed>  $draft  validated against rules()
     * @return array<string, mixed>
     */
    public static function normalise(SitePageKey $key, array $draft): array
    {
        return match ($key) {
            SitePageKey::About => self::aboutShape($draft),
            SitePageKey::InPrint => self::inPrintShape($draft),
            SitePageKey::Privacy, SitePageKey::Terms => self::contentsShape($draft),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function aboutRules(): array
    {
        return [
            'draft' => ['required', 'array'],
            'draft.image' => ['nullable', 'integer', Rule::exists('media', 'id')],
            'draft.hello' => ['required', 'array'],
            'draft.hello.heading' => ['present', 'string', 'max:120'],
            'draft.hello.paragraphs' => ['present', 'array', 'max:20'],
            'draft.hello.paragraphs.*' => ['string', 'max:5000'],
            'draft.sections' => ['present', 'array', 'max:20'],
            'draft.sections.*.id' => ['required', 'string', 'max:32', Rule::regex('/^[a-z0-9-]+$/'), 'distinct'],
            'draft.sections.*.heading' => ['present', 'string', 'max:120'],
            'draft.sections.*.paragraphs' => ['present', 'array', 'max:40'],
            'draft.sections.*.paragraphs.*.text' => ['present', 'string', 'max:5000'],
            'draft.sections.*.paragraphs.*.margin_note' => ['nullable', 'string', 'max:200'],
            'draft.sections.*.paragraphs.*.pull_quote' => ['boolean'],
            'draft.books' => ['present', 'string', 'max:600'],
            'draft.home' => ['required', 'array'],
            'draft.home.quote' => ['present', 'string', 'max:240'],
            'draft.home.line' => ['present', 'string', 'max:400'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function inPrintRules(): array
    {
        return [
            'draft' => ['required', 'array'],
            'draft.books' => ['present', 'array', 'max:60'],
            'draft.books.*.slug' => ['required', 'string', 'max:200', 'distinct', Rule::exists('works', 'slug')],
            'draft.books.*.title' => ['present', 'string', 'max:200'],
            'draft.books.*.kind' => ['present', 'string', 'max:40'],
            'draft.books.*.cover' => ['required', 'string', Rule::regex('/^#[0-9A-Fa-f]{6}$/')],
            'draft.books.*.formats' => ['present', 'array', 'max:8'],
            'draft.books.*.formats.*.format' => ['required', 'string', Rule::in(self::FORMATS)],
            'draft.books.*.formats.*.stores' => ['present', 'array', 'max:12'],
            'draft.books.*.formats.*.stores.*.store' => ['present', 'string', 'max:60'],
            'draft.books.*.formats.*.stores.*.price' => ['nullable', 'array', 'required_array_keys:amount,currency'],
            'draft.books.*.formats.*.stores.*.price.amount' => ['integer', 'min:0'],
            'draft.books.*.formats.*.stores.*.price.currency' => [Rule::in(self::CURRENCIES)],
            'draft.books.*.formats.*.stores.*.url' => ['nullable', 'string', 'max:2048', Rule::regex('#^https?://#i')],
            'draft.books.*.formats.*.stores.*.ok' => ['boolean'],
            'draft.coming' => ['present', 'array', 'max:30'],
            'draft.coming.*.slug' => ['required', 'string', 'max:200', 'distinct', Rule::exists('works', 'slug')],
            'draft.coming.*.title' => ['present', 'string', 'max:200'],
            'draft.coming.*.kind' => ['present', 'string', 'max:40'],
            'draft.coming.*.cover' => ['required', 'string', Rule::regex('/^#[0-9A-Fa-f]{6}$/')],
            'draft.coming.*.when' => ['present', 'string', 'max:40'],
            'draft.coming.*.out' => ['nullable', 'array', 'required_array_keys:d,m,y'],
            'draft.coming.*.out.d' => [Rule::regex('/^(|0[1-9]|[12][0-9]|3[01])$/')],
            'draft.coming.*.out.m' => [Rule::regex('/^(|0[1-9]|1[0-2])$/')],
            'draft.coming.*.out.y' => [Rule::regex('/^(|[0-9]{4})$/')],
            'draft.coming.*.mode' => ['required', Rule::in(['pre', 'follow'])],
            'draft.coming.*.store' => ['present', 'string', 'max:60'],
            'draft.coming.*.price' => ['nullable', 'array', 'required_array_keys:amount,currency'],
            'draft.coming.*.price.amount' => ['integer', 'min:0'],
            'draft.coming.*.price.currency' => [Rule::in(self::CURRENCIES)],
            'draft.coming.*.url' => ['nullable', 'string', 'max:2048', Rule::regex('#^https?://#i')],
            'draft.events' => ['present', 'array', 'max:300'],
            'draft.events.*.id' => ['required', 'string', 'max:32', 'distinct'],
            'draft.events.*.title' => ['present', 'string', 'max:160'],
            'draft.events.*.starts_at' => ['required', 'date'],
            'draft.events.*.place' => ['present', 'string', 'max:120'],
            'draft.events.*.online' => ['boolean'],
            'draft.events.*.url' => ['nullable', 'string', 'max:2048', Rule::regex('#^https?://#i')],
            'draft.events.*.cancelled' => ['boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function contentsRules(): array
    {
        return [
            'draft' => ['required', 'array'],
            'draft.updated_on' => ['required', 'date_format:Y-m-d'],
            'draft.sections' => ['present', 'array', 'max:40'],
            'draft.sections.*.id' => ['required', 'string', 'max:40', Rule::regex('/^[a-z0-9-]+$/'), 'distinct'],
            'draft.sections.*.heading' => ['present', 'string', 'max:160'],
            'draft.sections.*.html' => ['present', 'string', 'max:30000'],
            'draft.change' => ['nullable', 'array'],
            'draft.change.on' => ['date_format:Y-m-d'],
            'draft.change.line' => ['string', 'max:120'],
            'draft.change.ids' => ['array', 'max:40'],
            'draft.change.ids.*' => ['string', 'max:40'],
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private static function aboutShape(array $draft): array
    {
        return [
            'image' => isset($draft['image']) ? (int) $draft['image'] : null,
            'hello' => [
                'heading' => $draft['hello']['heading'],
                'paragraphs' => array_values($draft['hello']['paragraphs']),
            ],
            'sections' => array_values(array_map(fn (array $section): array => [
                'id' => $section['id'],
                'heading' => $section['heading'],
                'paragraphs' => array_values(array_map(fn (array $paragraph): array => [
                    'text' => $paragraph['text'],
                    'margin_note' => self::orNull($paragraph['margin_note'] ?? null),
                    'pull_quote' => self::flag($paragraph['pull_quote'] ?? false),
                ], $section['paragraphs'])),
            ], $draft['sections'])),
            'books' => $draft['books'],
            'home' => ['quote' => $draft['home']['quote'], 'line' => $draft['home']['line']],
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private static function inPrintShape(array $draft): array
    {
        return [
            'books' => array_values(array_map(fn (array $book): array => [
                'slug' => $book['slug'],
                'title' => $book['title'],
                'kind' => $book['kind'],
                'cover' => $book['cover'],
                'formats' => array_values(array_map(fn (array $format): array => [
                    'format' => $format['format'],
                    'stores' => array_values(array_map(fn (array $store): array => [
                        'store' => $store['store'],
                        'price' => self::price($store['price'] ?? null),
                        'url' => self::orNull($store['url'] ?? null),
                        'ok' => self::flag($store['ok'] ?? false),
                    ], $format['stores'])),
                ], $book['formats'])),
            ], $draft['books'])),
            'coming' => array_values(array_map(fn (array $book): array => [
                'slug' => $book['slug'],
                'title' => $book['title'],
                'kind' => $book['kind'],
                'cover' => $book['cover'],
                'when' => $book['when'],
                'out' => self::out($book['out'] ?? null),
                'mode' => $book['mode'],
                'store' => $book['store'],
                'price' => self::price($book['price'] ?? null),
                'url' => self::orNull($book['url'] ?? null),
            ], $draft['coming'])),
            'events' => array_values(array_map(fn (array $event): array => [
                'id' => (string) $event['id'],
                'title' => $event['title'],
                'starts_at' => Carbon::parse((string) $event['starts_at'])->utc()->toIso8601String(),
                'place' => $event['place'],
                'online' => self::flag($event['online'] ?? false),
                'url' => self::orNull($event['url'] ?? null),
                'cancelled' => self::flag($event['cancelled'] ?? false),
            ], $draft['events'])),
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private static function contentsShape(array $draft): array
    {
        $change = $draft['change'] ?? null;

        return [
            'updated_on' => $draft['updated_on'],
            'sections' => array_values(array_map(fn (array $section): array => [
                'id' => $section['id'],
                'heading' => $section['heading'],
                'html' => SafeHtml::clean($section['html']),
            ], $draft['sections'])),
            'change' => is_array($change) && ! empty($change['on']) ? [
                'on' => $change['on'],
                'line' => (string) ($change['line'] ?? ''),
                'ids' => array_values(array_map('strval', $change['ids'] ?? [])),
            ] : null,
        ];
    }

    /**
     * @return array{amount: int, currency: string}|null
     */
    private static function price(mixed $price): ?array
    {
        return is_array($price) ? ['amount' => (int) $price['amount'], 'currency' => (string) $price['currency']] : null;
    }

    /**
     * @return array{d: string, m: string, y: string}|null
     */
    private static function out(mixed $out): ?array
    {
        return is_array($out) ? ['d' => (string) ($out['d'] ?? ''), 'm' => (string) ($out['m'] ?? ''), 'y' => (string) ($out['y'] ?? '')] : null;
    }

    private static function orNull(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : $text;
    }

    private static function flag(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The entries of a list under $key that are arrays themselves.
     *
     * @param  array<string, mixed>  $parent
     * @return array<int|string, array<string, mixed>>
     */
    private static function listOf(array $parent, string $key): array
    {
        return array_filter(is_array($parent[$key] ?? null) ? $parent[$key] : [], is_array(...));
    }

    private static function zone(): string
    {
        return (string) config('eyiwetemi.timezone');
    }
}
