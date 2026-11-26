<?php

namespace App\Support;

use App\Enums\CollectionKind;
use App\Enums\HeadingStyle;
use App\Enums\PublishStatus;
use App\Enums\SitePageKey;
use App\Enums\UserRole;
use App\Enums\Visibility;
use App\Enums\WorkItemType;
use App\Enums\WorkKind;
use App\Enums\WorkStatus;
use App\Models\Genre;
use App\Models\ReviewerInvite;
use App\Models\Series;
use App\Models\SitePage;
use App\Models\SlugHistory;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The admin's works: the list (one line per work with its status, who can see it, length and last edit),
 * a work's Settings, and what the New work dialog offers (series, collections).
 *
 * @phpstan-type Fact array{icon: string, text: string, rose?: bool}
 */
class WorksAdmin
{
    /** Words on a printed page (5.5 × 8.5 in), as the writing screen counts them. */
    public const WORDS_PER_PAGE = 280;

    /** Poem lines on a printed page. */
    public const LINES_PER_PAGE = 30;

    /** A part's own page, and the blank behind it. */
    public const PART_PAGES = 2;

    /** Fronts a new work starts with until she adds a cover. */
    public const NEW_COLOURS = ['#DD6F94', '#977AA0', '#C46F8A', '#7E3550', '#C24E77'];

    /**
     * Every work, the most recently edited first.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        $works = Work::query()
            ->with(['cover', 'series'])
            ->withCount('reviewers')
            ->get();

        $items = WorkItem::query()
            ->whereIn('work_id', $works->pluck('id'))
            ->get(['id', 'work_id', 'type', 'position', 'status', 'draft_words', 'live_words', 'draft_text', 'live_text', 'draft_saved_at', 'live_updated_at', 'published_at', 'piece_work_id'])
            ->groupBy('work_id');

        $inPrint = self::inPrint();

        return $works
            ->map(function (Work $work) use ($items, $inPrint): array {
                $own = $items->get($work->id, collect());
                $edited = self::editedAt($work, $own);

                return [
                    'slug' => $work->slug,
                    'href' => '/works/'.$work->slug,
                    'title' => $work->title,
                    'tab' => $work->libraryTab(),
                    'sub' => self::sub($work),
                    'cover' => WorkPresenter::cover($work),
                    'status' => self::statusFact($work, $inPrint),
                    'visibility' => self::visibilityFact($work),
                    'length' => self::length($work, $own),
                    'edited' => DeskPage::when($edited),
                    'at' => $edited->getTimestamp(),
                ];
            })
            ->sortByDesc('at')
            ->map(fn (array $row): array => collect($row)->except('at')->all())
            ->values()
            ->all();
    }

    /**
     * A work's Settings, as its page shows them.
     *
     * @return array<string, mixed>
     */
    public static function settings(Work $work): array
    {
        $work->loadMissing(['cover', 'genres', 'series']);
        $items = $work->items()->get(['id', 'type', 'position', 'status', 'title']);
        $chapters = $items->where('type', '!=', WorkItemType::Part);
        $inPrint = self::inPrint()[$work->slug] ?? null;

        return [
            'slug' => $work->slug,
            'title' => $work->title,
            'kind' => $work->kind->value,
            'collectionKind' => $work->collection_kind?->value ?? CollectionKind::Story->value,
            'description' => $work->description ?? '',
            'cover' => WorkPresenter::cover($work),
            'hasCover' => $work->cover_media_id !== null,
            'genres' => $work->genres->pluck('name')->all(),
            'status' => $work->status->value,
            'visibility' => $work->visibility->value,
            'seriesId' => $work->series_id,
            'seriesPosition' => $work->series_position ?? 1,
            'collectionId' => $work->collection_id,
            'headingStyle' => ($work->heading_style ?? HeadingStyle::ChapterNumber)->value,
            'showChapterTitles' => $work->show_chapter_titles,
            'showPov' => $work->show_pov,
            'partWord' => $work->part_word ?? 'Part',
            'partStyle' => ($work->part_style ?? HeadingStyle::ChapterWord)->value,
            'showPartNumbers' => $work->show_part_numbers,
            'sceneBreak' => $work->scene_break->value,
            'contentNotes' => $work->content_notes ?? '',
            'published' => [
                'live' => $chapters->filter(fn (WorkItem $item): bool => $item->status === PublishStatus::Published)->count(),
                'drafts' => $chapters->filter(fn (WorkItem $item): bool => $item->status !== PublishStatus::Published)->count(),
                'ever' => $work->first_published_at !== null,
            ],
            'writeHref' => self::writeHref($work, $items->first()),
            'inPrint' => $inPrint === null ? null : [
                'coming' => $inPrint === 'coming',
                'href' => '/site?tab=print&open='.$work->slug,
            ],
        ];
    }

    /**
     * Everything the Settings pickers choose from.
     *
     * @return array<string, mixed>
     */
    public static function choices(Work $work): array
    {
        return [
            'series' => self::seriesChoices(),
            'collections' => self::collectionChoices(),
            'genres' => Genre::query()->orderBy('position')->orderBy('name')->pluck('name')->all(),
            'reviewers' => self::reviewersOn($work),
            'people' => User::query()
                ->where('role', UserRole::Reviewer)
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'avatar_seed'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'seed' => $user->avatar_seed])
                ->all(),
        ];
    }

    /**
     * What the New work dialog offers: series for a novel, collections for a poem, story or essay.
     *
     * @return array{series: list<array{id: int, name: string, books: int}>, collections: list<array{id: int, title: string, slug: string, kind: string}>}
     */
    public static function newWorkChoices(): array
    {
        return ['series' => self::seriesChoices(), 'collections' => self::collectionChoices()];
    }

    /**
     * The people reading this work: reviewers with access, then invites still waiting (or run out).
     *
     * @return list<array{kind: string, id: int, name: string, seed: string, state: string}>
     */
    public static function reviewersOn(Work $work): array
    {
        $users = $work->reviewers()->orderBy('work_reviewer.created_at')->get(['users.id', 'users.name', 'users.avatar_seed'])
            ->map(fn (User $user): array => ['kind' => 'user', 'id' => $user->id, 'name' => $user->name, 'seed' => $user->avatar_seed, 'state' => 'on']);

        $invites = ReviewerInvite::query()
            ->whereNull('accepted_at')
            ->whereNull('cancelled_at')
            ->whereHas('works', fn ($query) => $query->whereKey($work->id))
            ->orderBy('created_at')
            ->get()
            ->map(fn (ReviewerInvite $invite): array => [
                'kind' => 'invite',
                'id' => $invite->id,
                'name' => $invite->name,
                'seed' => $invite->email,
                'state' => $invite->expires_at->isPast() ? 'expired' : 'invited',
            ]);

        return $users->concat($invites)->values()->all();
    }

    /**
     * Title as it is compared for uniqueness: lowercase, spaces collapsed.
     */
    public static function titleKey(string $title): string
    {
        return mb_strtolower(self::cleanTitle($title));
    }

    public static function cleanTitle(string $title): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim(\Normalizer::normalize($title, \Normalizer::FORM_C) ?: $title));
    }

    /**
     * The work already called this, if there is one (other than the one being renamed).
     */
    public static function titleTaken(string $title, ?Work $except = null): ?Work
    {
        $key = self::titleKey($title);

        if ($key === '') {
            return null;
        }

        return Work::query()
            ->where('title_key', $key)
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->first(['id', 'title', 'slug']);
    }

    /**
     * A free address for a title: "the-tide-house", then "the-tide-house-2"… Old addresses of other works stay theirs.
     */
    public static function slugFor(string $title, ?Work $except = null): string
    {
        $base = Str::slug(Str::ascii($title)) ?: 'untitled';
        $slug = $base;
        $n = 2;

        while (self::slugUsed($slug, $except)) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /**
     * Where Continue / Start writing opens: the first chapter or piece.
     */
    public static function writeHref(Work $work, ?WorkItem $item): string
    {
        return $item ? '/works/'.$work->slug.'/write/'.$item->id : '/works/'.$work->slug.'?tab=chapters';
    }

    /**
     * Novel · Book 2 of The Harbour books; Short story; Collection · Poetry.
     */
    public static function sub(Work $work): string
    {
        return match ($work->kind) {
            WorkKind::Novel => 'Novel'.($work->series ? ' · Book '.($work->series_position ?? 1).' of '.$work->series->name : ''),
            WorkKind::Story => 'Short story',
            WorkKind::Poem => 'Poem',
            WorkKind::Essay => 'Essay',
            WorkKind::Collection => 'Collection · '.match ($work->collection_kind) {
                CollectionKind::Poem => 'Poetry',
                CollectionKind::Essay => 'Essays',
                default => 'Short stories',
            },
        };
    }

    /**
     * @param  array<string, string>  $inPrint
     * @return Fact
     */
    private static function statusFact(Work $work, array $inPrint): array
    {
        $single = ! in_array($work->kind, [WorkKind::Novel, WorkKind::Collection], true);

        return match (true) {
            $work->status === WorkStatus::ComingSoon => ['icon' => 'clock', 'text' => 'Coming soon'],
            ($inPrint[$work->slug] ?? null) === 'print' => ['icon' => 'book', 'text' => 'In print'],
            $work->first_published_at === null => ['icon' => 'draft', 'text' => 'Draft', 'rose' => true],
            $single => ['icon' => 'done', 'text' => 'Published'],
            $work->status === WorkStatus::Finished => ['icon' => 'done', 'text' => 'Finished'],
            default => ['icon' => 'pen', 'text' => 'In progress', 'rose' => true],
        };
    }

    /**
     * @return Fact
     */
    private static function visibilityFact(Work $work): array
    {
        return match ($work->visibility) {
            Visibility::Private => ['icon' => 'lock', 'text' => 'Private'],
            Visibility::Reviewers => ['icon' => 'people', 'text' => 'Reviewers'.($work->reviewers_count ? ' '.$work->reviewers_count : '')],
            Visibility::Public => ['icon' => 'globe', 'text' => 'Public'],
        };
    }

    /**
     * "7 chapters · 96 pages", "12 poems · 30 pages", "1,120 words · 4 pages", "14 lines · 1 page".
     *
     * @param  Collection<int, WorkItem>  $items
     */
    private static function length(Work $work, Collection $items): string
    {
        $pages = (int) $items->sum(fn (WorkItem $item): int => self::pagesOf($work, $item));
        $pagesText = $pages === 1 ? '1 page' : number_format($pages).' pages';

        if (in_array($work->kind, [WorkKind::Novel, WorkKind::Collection], true)) {
            $count = $items->where('type', '!=', WorkItemType::Part)->count();

            return WorkPresenter::units($work, $count).' · '.$pagesText;
        }

        $item = $items->first();

        if ($work->kind === WorkKind::Poem) {
            $lines = $item ? self::lines($item) : 0;

            return ($lines === 1 ? '1 line' : number_format($lines).' lines').' · '.$pagesText;
        }

        $words = $item ? self::words($item) : 0;

        return ($words === 1 ? '1 word' : number_format($words).' words').' · '.$pagesText;
    }

    /**
     * Printed pages: a chapter starts a new page, a part takes its own two, a poem runs thirty lines to a page.
     */
    public static function pagesOf(Work $work, WorkItem $item): int
    {
        if ($item->type === WorkItemType::Part) {
            return self::PART_PAGES;
        }

        $poem = $work->kind === WorkKind::Poem || ($work->kind === WorkKind::Collection && $work->collection_kind === CollectionKind::Poem);

        if ($poem) {
            return max(1, (int) ceil(self::lines($item) / self::LINES_PER_PAGE));
        }

        return max(1, (int) ceil(self::words($item) / self::WORDS_PER_PAGE));
    }

    public static function words(WorkItem $item): int
    {
        return (int) ($item->draft_text !== null ? $item->draft_words : $item->live_words);
    }

    private static function lines(WorkItem $item): int
    {
        $text = (string) ($item->draft_text ?? $item->live_text ?? '');

        return count(array_filter(preg_split('/\R/u', $text) ?: [], fn (string $line): bool => trim($line) !== ''));
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     */
    private static function editedAt(Work $work, Collection $items): CarbonInterface
    {
        $times = $items->flatMap(fn (WorkItem $item): array => [$item->draft_saved_at, $item->live_updated_at, $item->published_at])->filter();

        return $times->max() ?? $work->created_at ?? now();
    }

    /**
     * Works in print or announced on In print: slug => 'print' | 'coming'.
     *
     * @return array<string, string>
     */
    private static function inPrint(): array
    {
        return once(function (): array {
            $live = SitePage::query()->firstWhere('key', SitePageKey::InPrint)?->live ?? [];

            return collect($live['books'] ?? [])->mapWithKeys(fn (array $book): array => [$book['slug'] => 'print'])
                ->merge(collect($live['coming'] ?? [])->mapWithKeys(fn (array $book): array => [$book['slug'] => 'coming']))
                ->all();
        });
    }

    /**
     * @return list<array{id: int, name: string, books: int}>
     */
    private static function seriesChoices(): array
    {
        return Series::query()->withCount('works')->orderBy('name')->get()
            ->map(fn (Series $series): array => ['id' => $series->id, 'name' => $series->name, 'books' => $series->works_count])
            ->all();
    }

    /**
     * @return list<array{id: int, title: string, slug: string, kind: string}>
     */
    private static function collectionChoices(): array
    {
        return Work::query()->where('kind', WorkKind::Collection)->orderBy('title')->get(['id', 'title', 'slug', 'collection_kind'])
            ->map(fn (Work $work): array => ['id' => $work->id, 'title' => $work->title, 'slug' => $work->slug, 'kind' => $work->collection_kind?->value ?? CollectionKind::Story->value])
            ->all();
    }

    private static function slugUsed(string $slug, ?Work $except): bool
    {
        $own = Work::query()->where('slug', $slug)->when($except, fn ($query) => $query->whereKeyNot($except->id))->exists();

        if ($own) {
            return true;
        }

        return SlugHistory::query()
            ->where('sluggable_type', (new Work)->getMorphClass())
            ->where('slug', $slug)
            ->when($except, fn ($query) => $query->where('sluggable_id', '!=', $except->id))
            ->exists();
    }
}
