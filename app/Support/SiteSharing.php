<?php

namespace App\Support;

use App\Models\Media;
use App\Models\SiteSetting;
use App\Models\Work;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Her share cards: a line for the site and for each work (what a link preview says), and her own picture when she has one.
 * Kept under the sharing setting as {site, works}, each entry {own, line, image}: image is a media id, and own says whether
 * the picture is used instead of the drawn card.
 *
 * @phpstan-type Entry array{own: bool, line: string, image: int|null}
 */
class SiteSharing
{
    /** A work's description, as the line a preview falls back to, is at most this many characters. */
    private const DESCRIPTION_MAX = 200;

    /**
     * The sharing prop: the site's entry and each work's, with picture addresses.
     *
     * @return array{site: array{own: bool, line: string, image: string|null}, works: array<string, array{own: bool, line: string, image: string|null}>}
     */
    public static function props(): array
    {
        $settings = self::settings();
        $site = self::entry($settings, null);
        $works = [];

        foreach (Work::query()->pluck('slug') as $slug) {
            $works[$slug] = self::entry($settings, $slug);
        }

        $urls = self::urls([$site['image'], ...array_column($works, 'image')]);

        return [
            'site' => self::shown($site, $urls),
            'works' => array_map(fn (array $entry): array => self::shown($entry, $urls), $works),
        ];
    }

    /**
     * The line the site's card and its link previews say: her line for the site, else the footer's line.
     */
    public static function siteLine(): string
    {
        $line = self::entry(self::settings(), null)['line'];

        return $line !== '' ? $line : SiteChrome::line();
    }

    /**
     * The line a work's link preview says: her line for it, else its description.
     */
    public static function workLine(Work $work): string
    {
        $line = self::entry(self::settings(), $work->slug)['line'];

        return $line !== '' ? $line : self::description($work);
    }

    /**
     * A work's description as plain text, at most 200 characters.
     */
    public static function description(Work $work): string
    {
        return Str::limit(Str::squish(strip_tags((string) $work->description)), self::DESCRIPTION_MAX);
    }

    /**
     * Her own picture for the site or a work, when it is set and used and its file is still there.
     */
    public static function ownPicture(?string $slug): ?Media
    {
        $entry = self::entry(self::settings(), $slug);

        if (! $entry['own'] || $entry['image'] === null) {
            return null;
        }

        $media = Media::query()->find($entry['image']);

        return $media !== null && Storage::disk($media->disk)->exists($media->path) ? $media : null;
    }

    /**
     * The media id of the picture set for the site or a work, if any.
     */
    public static function imageId(?string $slug): ?int
    {
        return self::entry(self::settings(), $slug)['image'];
    }

    /**
     * Whether any share entry still uses a picture.
     */
    public static function usesPicture(int $id): bool
    {
        $settings = self::settings();

        return collect([$settings['site'], ...array_values($settings['works'])])->contains(fn (array $entry): bool => $entry['image'] === $id);
    }

    /**
     * Changes the entry for the site (null) or for a work: any of own, line and image.
     *
     * @param  array{own?: bool, line?: string, image?: int}  $changes
     */
    public static function change(?string $slug, array $changes): void
    {
        $settings = self::settings();
        $entry = [...self::entry($settings, $slug), ...$changes];

        if ($slug === null) {
            $settings['site'] = $entry;
        } else {
            $settings['works'][$slug] = $entry;
        }

        SiteSetting::put('sharing', $settings);
    }

    /**
     * The stored settings, each entry filled in with its defaults.
     *
     * @return array{site: Entry, works: array<string, Entry>}
     */
    private static function settings(): array
    {
        $stored = SiteSetting::get('sharing');
        $stored = is_array($stored) ? $stored : [];
        $works = is_array($stored['works'] ?? null) ? $stored['works'] : [];

        return [
            'site' => self::normalise($stored['site'] ?? null),
            'works' => array_map(fn (mixed $entry): array => self::normalise($entry), $works),
        ];
    }

    /**
     * The entry for the site (null) or for a work, with defaults where nothing is stored.
     *
     * @param  array{site: Entry, works: array<string, Entry>}  $settings
     * @return Entry
     */
    private static function entry(array $settings, ?string $slug): array
    {
        return $slug === null ? $settings['site'] : ($settings['works'][$slug] ?? self::normalise(null));
    }

    /**
     * @return Entry
     */
    private static function normalise(mixed $entry): array
    {
        $entry = is_array($entry) ? $entry : [];

        return [
            'own' => (bool) ($entry['own'] ?? false),
            'line' => (string) ($entry['line'] ?? ''),
            'image' => is_numeric($entry['image'] ?? null) ? (int) $entry['image'] : null,
        ];
    }

    /**
     * @param  Entry  $entry
     * @param  array<int, string>  $urls
     * @return array{own: bool, line: string, image: string|null}
     */
    private static function shown(array $entry, array $urls): array
    {
        return [
            'own' => $entry['own'],
            'line' => $entry['line'],
            'image' => $entry['image'] === null ? null : ($urls[$entry['image']] ?? null),
        ];
    }

    /**
     * The addresses of the media the ids name.
     *
     * @param  array<int, int|null>  $ids
     * @return array<int, string>
     */
    private static function urls(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (mixed $id): bool => $id !== null)));

        if ($ids === []) {
            return [];
        }

        return Media::query()->whereIn('id', $ids)->get(['id', 'disk', 'path'])
            ->mapWithKeys(fn (Media $media): array => [$media->id => WorkPresenter::mediaUrl($media->disk, $media->path)])
            ->all();
    }
}
