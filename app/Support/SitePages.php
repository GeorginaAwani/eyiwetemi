<?php

namespace App\Support;

use App\Enums\SitePageKey;
use App\Models\LinkCheck;
use App\Models\Media;
use App\Models\SitePage;
use App\Models\Version;
use App\Models\Work;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Throwable;

/**
 * The admin Site page's data: each page's draft and what is live, its versions with what each one changed, the works
 * its In print page can name, the store links and when each was last checked, and her portrait. Also the words a page is
 * made of, which its versions count. Every time is West Africa Time.
 */
class SitePages
{
    /**
     * @return array{pages: array<string, array<string, mixed>>, works: list<array<string, mixed>>, links: array<string, array{ok: bool, checkedOn: string}>, portrait: array{draft: string|null, live: string|null}}
     */
    public static function props(): array
    {
        $records = SitePage::query()->get()->keyBy(fn (SitePage $page): string => $page->key->value);

        $pages = [];
        foreach (SitePageKey::cases() as $key) {
            $pages[$key->value] = self::page($key, $records->get($key->value));
        }

        $about = $records->get(SitePageKey::About->value);
        $inPrint = $records->get(SitePageKey::InPrint->value);

        return [
            'pages' => $pages,
            'works' => self::works(),
            'links' => self::links(
                is_array($inPrint?->draft) ? $inPrint->draft : [],
                is_array($inPrint?->live) ? $inPrint->live : [],
            ),
            'portrait' => [
                'draft' => self::portraitUrl(Arr::get($about?->draft ?? [], 'image')),
                'live' => self::portraitUrl(Arr::get($about?->live ?? [], 'image')),
            ],
        ];
    }

    /**
     * A page as its editor reads it, loaded by its key.
     *
     * @return array{draft: array<string, mixed>, live: array<string, mixed>|null, savedAt: string|null, publishedAt: string|null, versions: list<array{id: int, at: string|null, changes: string, matters: bool}>}
     */
    public static function pageOf(SitePageKey $key): array
    {
        return self::page($key, SitePage::query()->firstWhere('key', $key));
    }

    /**
     * One page as its editor reads it: the draft (or what is live, or an empty page), what is live, when each was saved and
     * published, and its versions newest first.
     *
     * @return array{draft: array<string, mixed>, live: array<string, mixed>|null, savedAt: string|null, publishedAt: string|null, versions: list<array{id: int, at: string|null, changes: string, matters: bool}>}
     */
    public static function page(SitePageKey $key, ?SitePage $record = null): array
    {
        $live = is_array($record?->live) ? SitePageDraft::complete($key, $record->live) : null;
        $stored = is_array($record?->draft) ? $record->draft : ($live ?? SitePageDraft::empty($key));

        return [
            'draft' => SitePageDraft::complete($key, $stored),
            'live' => $live,
            'savedAt' => self::wat($record?->draft_saved_at),
            'publishedAt' => self::wat($record?->published_at),
            'versions' => $record === null ? [] : $record->versions()->reorder()->orderByDesc('id')->get()
                ->pipe(fn ($versions) => $versions->map(fn (Version $version, int $i): array => [
                    'id' => $version->id,
                    'at' => self::wat($version->created_at),
                    'changes' => $i === $versions->count() - 1 && ! self::meta($version)['matters'] ? 'First version' : self::changes($version),
                    'matters' => self::meta($version)['matters'],
                ]))
                ->values()
                ->all(),
        ];
    }

    /**
     * What a version changed, for the history: the line she wrote when it matters, otherwise the words added and cut.
     */
    public static function changes(Version $version): string
    {
        $meta = self::meta($version);

        if ($meta['matters'] && $meta['line'] !== null) {
            return $meta['line'];
        }

        $added = (int) $version->words_added;
        $cut = (int) $version->words_cut;

        return match (true) {
            $added > 0 && $cut > 0 => '+'.$added.' words, −'.$cut,
            $added > 0 => '+'.$added.' words',
            $cut > 0 => '−'.$cut,
            default => 'Small changes',
        };
    }

    /**
     * Whether a version is one that matters (with its line), kept beside the version body under _meta.
     *
     * @return array{matters: bool, line: string|null}
     */
    public static function meta(Version $version): array
    {
        $meta = is_array($version->body['_meta'] ?? null) ? $version->body['_meta'] : [];

        return [
            'matters' => (bool) ($meta['matters'] ?? false),
            'line' => isset($meta['line']) ? (string) $meta['line'] : null,
        ];
    }

    /**
     * The words a page is made of, as its versions count them: headings, paragraphs and notes, In print titles and events,
     * and the words of Privacy and Terms with their tags left out.
     *
     * @param  array<string, mixed>  $content
     * @return list<string>
     */
    public static function words(SitePageKey $key, array $content): array
    {
        preg_match_all("/[\\p{L}\\p{N}’']+/u", self::text($key, $content), $matches);

        return $matches[0];
    }

    /**
     * Words added and cut going from one text to the next: the multiset difference of their words, so a word moved
     * elsewhere counts once each way.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return array{added: int, cut: int}
     */
    public static function wordChange(array $before, array $after): array
    {
        $previous = array_count_values($before);
        $current = array_count_values($after);

        $added = 0;
        foreach ($current as $word => $count) {
            $added += max(0, $count - ($previous[$word] ?? 0));
        }

        $cut = 0;
        foreach ($previous as $word => $count) {
            $cut += max(0, $count - ($current[$word] ?? 0));
        }

        return ['added' => $added, 'cut' => $cut];
    }

    /**
     * The store and pre-order addresses on an In print page's content.
     *
     * @param  array<string, mixed>  $content
     * @return list<string>
     */
    public static function storeAddresses(array $content): array
    {
        $addresses = [];

        foreach ($content['books'] ?? [] as $book) {
            foreach ($book['formats'] ?? [] as $format) {
                foreach ($format['stores'] ?? [] as $store) {
                    $addresses[] = $store['url'] ?? null;
                }
            }
        }

        foreach ($content['coming'] ?? [] as $book) {
            $addresses[] = $book['url'] ?? null;
        }

        return array_values(array_unique(array_filter($addresses, fn (mixed $url): bool => is_string($url) && $url !== '')));
    }

    /**
     * The addresses the live In print page sends readers to: what the daily link check looks at.
     *
     * @return list<string>
     */
    public static function liveStoreAddresses(): array
    {
        $live = SitePage::query()->firstWhere('key', SitePageKey::InPrint)?->live;

        return self::storeAddresses(is_array($live) ? $live : []);
    }

    /**
     * Opens a store or pre-order address and records whether it opened: a 2xx or 3xx after up to three redirects. An address
     * that is not public, or does not answer, is recorded as not working.
     */
    public static function checkLink(string $url): LinkCheck
    {
        $ok = false;
        $status = null;

        try {
            $status = SafeFetch::get($url, 6, 3)->status();
            $ok = $status >= 200 && $status < 400;
        } catch (Throwable) {
        }

        return LinkCheck::query()->updateOrCreate(
            ['url_hash' => hash('sha256', $url)],
            ['url' => $url, 'ok' => $ok, 'status_code' => $status, 'checked_at' => now()],
        );
    }

    /**
     * Formats a moment as West Africa Time for the page, to the minute.
     */
    public static function wat(?CarbonInterface $at): ?string
    {
        return $at?->copy()->setTimezone(self::zone())->format('Y-m-d\TH:i');
    }

    /**
     * A moment she typed in West Africa Time ("2026-12-01 18:00"), as UTC to the minute.
     */
    public static function fromWat(string $local): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $local, self::zone())->startOfMinute()->utc();
    }

    /**
     * Every work, by title: its kind, its colour and picture as the cover shows them, whether readers can see it, and its
     * description as plain text for a share card.
     *
     * @return list<array{slug: string, title: string, kind: string, color: string|null, image: string|null, public: bool, desc: string}>
     */
    private static function works(): array
    {
        $listed = Work::query()->listed()->pluck('id')->flip();

        return Work::query()
            ->with('cover:id,disk,path')
            ->orderBy('title')
            ->get(['id', 'title', 'slug', 'kind', 'cover_color', 'cover_media_id', 'description'])
            ->map(fn (Work $work): array => [
                'slug' => $work->slug,
                'title' => $work->title,
                'kind' => $work->kind->value,
                'color' => $work->cover_color ?: null,
                'image' => WorkPresenter::cover($work)['src'],
                'public' => $listed->has($work->id),
                'desc' => SiteSharing::description($work),
            ])
            ->values()
            ->all();
    }

    /**
     * The checks of the store and pre-order addresses in the draft and in what is live, by address.
     *
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $live
     * @return array<string, array{ok: bool, checkedOn: string}>
     */
    private static function links(array $draft, array $live): array
    {
        $urls = array_values(array_unique([...self::storeAddresses($draft), ...self::storeAddresses($live)]));

        if ($urls === []) {
            return [];
        }

        $checks = LinkCheck::query()
            ->whereIn('url_hash', array_map(fn (string $url): string => hash('sha256', $url), $urls))
            ->get()
            ->keyBy('url_hash');

        $links = [];

        foreach ($urls as $url) {
            $check = $checks->get(hash('sha256', $url));

            if ($check !== null) {
                $links[$url] = ['ok' => $check->ok, 'checkedOn' => $check->checked_at->setTimezone(self::zone())->format('Y-m-d')];
            }
        }

        return $links;
    }

    private static function portraitUrl(mixed $id): ?string
    {
        $media = is_numeric($id) ? Media::query()->find((int) $id, ['id', 'disk', 'path']) : null;

        return $media ? WorkPresenter::mediaUrl($media->disk, $media->path) : null;
    }

    /**
     * The text of a page as a version counts it. Privacy and Terms use their headings and their HTML with the tags left out.
     *
     * @param  array<string, mixed>  $content
     */
    private static function text(SitePageKey $key, array $content): string
    {
        $parts = [];

        if ($key === SitePageKey::About) {
            $parts[] = $content['hello']['heading'] ?? '';
            array_push($parts, ...($content['hello']['paragraphs'] ?? []));

            foreach ($content['sections'] ?? [] as $section) {
                $parts[] = $section['heading'];

                foreach ($section['paragraphs'] ?? [] as $paragraph) {
                    $parts[] = $paragraph['text'];
                    $parts[] = $paragraph['margin_note'] ?? '';
                }
            }

            $parts[] = $content['books'] ?? '';
            $parts[] = $content['home']['quote'] ?? '';
            $parts[] = $content['home']['line'] ?? '';
        } elseif ($key === SitePageKey::InPrint) {
            foreach (['books', 'coming'] as $list) {
                foreach ($content[$list] ?? [] as $book) {
                    $parts[] = $book['title'];
                }
            }

            foreach ($content['coming'] ?? [] as $book) {
                $parts[] = $book['when'];
            }

            foreach ($content['events'] ?? [] as $event) {
                $parts[] = $event['title'];
                $parts[] = $event['place'];
            }
        } else {
            foreach ($content['sections'] ?? [] as $section) {
                $parts[] = $section['heading'];
                $parts[] = html_entity_decode((string) preg_replace('/<[^>]*>/', ' ', (string) $section['html']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return implode("\n", array_map(fn (mixed $part): string => (string) $part, $parts));
    }

    private static function zone(): string
    {
        return (string) config('eyiwetemi.timezone');
    }
}
