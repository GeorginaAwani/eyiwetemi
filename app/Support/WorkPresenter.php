<?php

namespace App\Support;

use App\Enums\PublishStatus;
use App\Enums\WorkItemType;
use App\Enums\WorkKind;
use App\Enums\WorkStatus;
use App\Models\Work;
use App\Models\WorkItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Turns works into the small shapes the reading site's pages draw: titles, pictures, status facts,
 * chapter rows and addresses. Expects works loaded with `cover`, `series` and `releasedItems`.
 */
class WorkPresenter
{
    /**
     * Eager loads every presenter call needs.
     *
     * @return array<string, mixed>
     */
    public static function with(): array
    {
        return [
            'cover',
            'series',
            'items' => fn ($query) => $query
                ->whereIn('type', [WorkItemType::Chapter, WorkItemType::Piece])
                ->whereIn('status', [PublishStatus::Published, PublishStatus::Unpublished])
                ->select(['id', 'work_id', 'type', 'position', 'title', 'status', 'published_at', 'live_words', 'description']),
        ];
    }

    /**
     * Address of a work's own page (novels, collections) or of a single piece's reading page.
     */
    public static function href(Work $work): string
    {
        return '/library/'.$work->slug;
    }

    /**
     * Address of the n-th chapter or piece of a novel or collection.
     */
    public static function itemHref(Work $work, int $number, bool $fromStart = false): string
    {
        return '/library/'.$work->slug.'/'.$number.($fromStart ? '?start' : '');
    }

    /**
     * What a work's chapters or pieces are called: chapter, story, poem or essay.
     */
    public static function unit(Work $work): string
    {
        return match (true) {
            $work->kind === WorkKind::Collection => $work->collection_kind?->value ?? 'piece',
            default => 'chapter',
        };
    }

    public static function units(Work $work, int $count): string
    {
        $unit = self::unit($work);
        $plural = $unit === 'story' ? 'stories' : $unit.'s';

        return $count.' '.($count === 1 ? $unit : $plural);
    }

    /**
     * "A novel", "A short story", "A poem", "An essay", "A collection".
     */
    public static function kindLabel(Work $work): string
    {
        return match ($work->kind) {
            WorkKind::Novel => 'A novel',
            WorkKind::Story => 'A short story',
            WorkKind::Poem => 'A poem',
            WorkKind::Essay => 'An essay',
            WorkKind::Collection => 'A collection',
        };
    }

    /**
     * Released chapters or pieces, numbered as readers see them (an unpublished one keeps its number).
     *
     * @return Collection<int, array{number: int, item: WorkItem}>
     */
    public static function numbered(Work $work): Collection
    {
        return $work->items
            ->values()
            ->map(fn (WorkItem $item, int $i): array => ['number' => $i + 1, 'item' => $item])
            ->filter(fn (array $row): bool => $row['item']->status === PublishStatus::Published
                && $row['item']->published_at !== null
                && $row['item']->published_at->lte(now()))
            ->values();
    }

    /**
     * When the work last had something new out.
     */
    public static function updatedAt(Work $work): ?CarbonInterface
    {
        return self::numbered($work)->max(fn (array $row): CarbonInterface => $row['item']->published_at) ?? $work->last_published_at;
    }

    public static function minutes(Work $work): int
    {
        return (int) self::numbered($work)->sum(fn (array $row): int => Reading::minutes((int) $row['item']->live_words));
    }

    /**
     * The picture: an uploaded cover, or a coloured front with the title on it.
     *
     * @return array{src: string|null, color: string, dark: bool}
     */
    public static function cover(Work $work): array
    {
        $color = $work->cover_color ?: '#C24E77';

        return [
            'src' => $work->cover ? self::mediaUrl($work->cover->disk, $work->cover->path) : null,
            'color' => $color,
            'dark' => self::isLight($color, 0.6),
        ];
    }

    /**
     * The status as an icon and one short fact: pen "7 chapters", check "12 chapters", clock "Coming soon".
     *
     * @return array{icon: string|null, text: string}
     */
    public static function status(Work $work): array
    {
        $count = self::numbered($work)->count();

        return match (true) {
            $work->status === WorkStatus::ComingSoon => ['icon' => 'clock', 'text' => 'Coming soon'],
            $work->kind !== WorkKind::Novel && $work->kind !== WorkKind::Collection => ['icon' => null, 'text' => Reading::duration(self::minutes($work))],
            $work->kind === WorkKind::Collection => ['icon' => 'layers', 'text' => 'Collection · '.self::units($work, $count)],
            $work->status === WorkStatus::Finished => ['icon' => 'check', 'text' => self::units($work, $count)],
            default => ['icon' => 'pen', 'text' => self::units($work, $count)],
        };
    }

    /**
     * The longer status line on a lead: "In progress · 7 chapters so far", "Finished · 12 chapters".
     */
    public static function statusLine(Work $work): string
    {
        $count = self::numbered($work)->count();

        return match ($work->status) {
            WorkStatus::ComingSoon => 'Coming soon',
            WorkStatus::Finished => 'Finished · '.self::units($work, $count),
            default => 'In progress · '.self::units($work, $count).' so far',
        };
    }

    /**
     * @return array{name: string, href: string, position: int}|null
     */
    public static function series(Work $work): ?array
    {
        if ($work->series === null || $work->series_position === null) {
            return null;
        }

        return ['name' => $work->series->name, 'href' => '/series/'.$work->series->slug, 'position' => (int) $work->series_position];
    }

    /**
     * The fields every card shares.
     *
     * @return array<string, mixed>
     */
    public static function card(Work $work): array
    {
        return [
            'id' => $work->id,
            'kind' => $work->kind->value,
            'kindLabel' => self::kindLabel($work),
            'tab' => $work->libraryTab(),
            'title' => $work->title,
            'href' => self::href($work),
            'cover' => self::cover($work),
            'isCollection' => $work->kind === WorkKind::Collection,
            'status' => self::status($work),
            'series' => self::series($work),
            'inPrint' => InPrint::fromPrice($work->slug),
            'minutes' => self::minutes($work),
            'date' => Reading::day(self::updatedAt($work)),
        ];
    }

    /**
     * Newest chapters first, as Home and the work page list them.
     *
     * @return list<array{number: int, title: string|null, label: string, minutes: int, href: string, isNew: bool}>
     */
    public static function newestItems(Work $work, int $take): array
    {
        $rows = self::numbered($work);
        $newest = $rows->last();
        $unit = ucfirst(self::unit($work));

        return $rows->reverse()->take($take)->map(fn (array $row): array => [
            'number' => $row['number'],
            'title' => $row['item']->title,
            'label' => $unit.' '.$row['number'].($row['item']->title ? ' · '.$row['item']->title : ''),
            'minutes' => Reading::minutes((int) $row['item']->live_words),
            'href' => self::itemHref($work, $row['number']),
            'isNew' => $row === $newest && $row['item']->published_at->gt(now()->subDays(14)),
        ])->values()->all();
    }

    /**
     * Every chapter or piece readers can see listed, oldest first. One that was pulled back keeps its
     * number and shows as not available.
     *
     * @return list<array{number: int, title: string|null, label: string, minutes: int, href: string|null, isNew: bool, unavailable: bool}>
     */
    public static function allItems(Work $work): array
    {
        $unit = ucfirst(self::unit($work));
        $released = self::numbered($work);
        $newest = $released->last();

        return $work->items->values()->map(function (WorkItem $item, int $i) use ($work, $unit, $newest): array {
            $number = $i + 1;
            $out = $item->status === PublishStatus::Published && $item->published_at?->lte(now());

            return [
                'number' => $number,
                'title' => $item->title,
                'label' => $item->title ?? $unit.' '.$number,
                'minutes' => Reading::minutes((int) $item->live_words),
                'href' => $out ? self::itemHref($work, $number) : null,
                'isNew' => $out && $newest !== null && $newest['item']->is($item) && $item->published_at->gt(now()->subDays(14)),
                'unavailable' => ! $out,
            ];
        })->all();
    }

    /**
     * Works readers can see, with what the presenter needs.
     *
     * @return Builder<Work>
     */
    public static function query(): Builder
    {
        return Work::query()->listed()->with(self::with());
    }

    /**
     * The text of one chapter or piece (left out of the list query so long novels stay light).
     *
     * @return array<string, mixed>|null
     */
    public static function body(WorkItem $item): ?array
    {
        return WorkItem::query()->whereKey($item->id)->first(['id', 'live_body'])?->live_body;
    }

    /**
     * Address of an uploaded file. The local public disk is served from this site, so its address stays relative.
     */
    public static function mediaUrl(string $disk, string $path): string
    {
        return $disk === 'public' ? '/storage/'.ltrim($path, '/') : Storage::disk($disk)->url($path);
    }

    /**
     * Light colours take dark lettering, as in the prototype: fronts only when very pale
     * (blush, rose 300); the pile's narrower spines from pink up.
     */
    public static function isLight(string $hex, float $threshold = 0.525): bool
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) !== 6) {
            return false;
        }

        [$r, $g, $b] = array_map(fn (string $c): float => hexdec($c) / 255, str_split($hex, 2));

        return (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) > $threshold;
    }
}
