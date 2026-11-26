<?php

namespace App\Support;

use App\Enums\WorkKind;
use App\Enums\WorkStatus;
use App\Models\Work;
use App\Models\WorkItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The Library: one page, a tab per kind. Each tab leads with its newest work, then lists the rest
 * newest first ("Earlier"), a page at a time. In the Novels tab a series sits together under its name.
 */
class LibraryPage
{
    /** Rows on the first screen of a tab, counting the lead (the prototype's "14 of 35"). */
    public const PAGE = 14;

    public const TABS = ['all', 'novel', 'story', 'poem', 'essay'];

    /** @var Collection<int, Work> */
    private Collection $works;

    /** @var array<int, array<string, mixed>|null>|null First chapter or piece text by work id, loaded once. */
    private ?array $bodies = null;

    /** @var Collection<int, int>|null Ids of the works the signed-in reader follows, loaded once. */
    private ?Collection $followed = null;

    public function __construct()
    {
        $this->works = WorkPresenter::query()->get()
            ->sortByDesc(fn (Work $work): int => WorkPresenter::updatedAt($work)?->getTimestamp() ?? 0)
            ->sortBy(fn (Work $work): int => $work->status === WorkStatus::ComingSoon ? 1 : 0)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $tab): array
    {
        $counts = LibraryCounts::get();

        return [
            'tab' => in_array($tab, self::TABS, true) ? $tab : 'all',
            'tabs' => [
                ['key' => 'all', 'label' => 'All', 'count' => $counts['all']],
                ['key' => 'novel', 'label' => 'Novels', 'count' => $counts['novel']],
                ['key' => 'story', 'label' => 'Short stories', 'count' => $counts['story']],
                ['key' => 'poem', 'label' => 'Poetry', 'count' => $counts['poem']],
                ['key' => 'essay', 'label' => 'Essays', 'count' => $counts['essay']],
            ],
            'panels' => collect(self::TABS)->mapWithKeys(fn (string $key): array => [$key => $this->panel($key)])->all(),
        ];
    }

    /**
     * The next rows of a tab after the ones already shown.
     *
     * @return array{rows: list<array<string, mixed>>, shown: int, total: int}
     */
    public function more(string $tab, int $shown): array
    {
        $entries = $this->entries($tab);
        $total = $this->worksIn($tab)->count();

        return [...$this->slice($entries, $shown, self::PAGE), 'total' => $total];
    }

    /**
     * @return array{lead: array<string, mixed>|null, rows: list<array<string, mixed>>, shown: int, total: int}
     */
    private function panel(string $tab): array
    {
        $lead = $this->lead($tab);
        $entries = $this->entries($tab);
        $total = $this->worksIn($tab)->count();
        $page = $this->slice($entries, $lead ? 1 : 0, self::PAGE - ($lead ? 1 : 0));

        return [
            'lead' => $lead ? $this->leadPayload($lead) : null,
            'rows' => $page['rows'],
            'shown' => $page['shown'],
            'total' => $total,
        ];
    }

    /**
     * Rows from `$after` works in, taking about `$take` works. A series group is never split.
     *
     * @param  list<array{work: Work|null, group: array<string, mixed>|null, size: int}>  $entries
     * @return array{rows: list<array<string, mixed>>, shown: int}
     */
    private function slice(array $entries, int $after, int $take): array
    {
        $rows = [];
        $seen = 0;
        $shown = $after;

        foreach ($entries as $entry) {
            $start = $seen;
            $seen += $entry['size'];

            if ($start < $after) {
                continue;
            }

            if ($shown - $after >= $take) {
                break;
            }

            $rows[] = $entry['group'] ?? $this->row($entry['work'], false);
            $shown += $entry['size'];
        }

        return ['rows' => $rows, 'shown' => $shown];
    }

    /**
     * A tab's works in order, the lead first. In the Novels tab, works in a series come together as one entry,
     * placed where the series' newest book falls.
     *
     * @return list<array{work: Work|null, group: array<string, mixed>|null, size: int}>
     */
    private function entries(string $tab): array
    {
        $works = $this->worksIn($tab);
        $lead = $this->lead($tab);
        $entries = [];
        $grouped = [];

        if ($lead) {
            $entries[] = ['work' => $lead, 'group' => null, 'size' => 1];
        }

        foreach ($works as $work) {
            if ($work->is($lead) || isset($grouped[$work->id])) {
                continue;
            }

            if ($tab === 'novel' && $work->series_id !== null) {
                $members = $works->filter(fn (Work $w): bool => $w->series_id === $work->series_id && ! $w->is($lead))
                    ->sortBy('series_position')->values();
                $members->each(function (Work $w) use (&$grouped): void {
                    $grouped[$w->id] = true;
                });

                $bookCount = $this->works->where('series_id', $work->series_id)->count();
                $entries[] = [
                    'work' => null,
                    'size' => $members->count(),
                    'group' => [
                        'type' => 'series',
                        'name' => $work->series->name,
                        'href' => '/series/'.$work->series->slug,
                        'sub' => 'A series · '.$bookCount.' '.($bookCount === 1 ? 'book' : 'books'),
                        'rows' => $members->map(fn (Work $w): array => $this->row($w, true))->all(),
                    ],
                ];

                continue;
            }

            $entries[] = ['work' => $work, 'group' => null, 'size' => 1];
        }

        return $entries;
    }

    /**
     * @return Collection<int, Work>
     */
    private function worksIn(string $tab): Collection
    {
        return $tab === 'all'
            ? $this->works
            : $this->works->filter(fn (Work $work): bool => $work->libraryTab() === $tab)->values();
    }

    private function lead(string $tab): ?Work
    {
        return $this->worksIn($tab)->first(fn (Work $work): bool => WorkPresenter::numbered($work)->isNotEmpty());
    }

    /**
     * The lead: a novel with its newest chapters, or a piece with its excerpt (a poem with its first stanza).
     *
     * @return array<string, mixed>
     */
    private function leadPayload(Work $work): array
    {
        $rows = WorkPresenter::numbered($work);
        $card = WorkPresenter::card($work);
        $hasUnits = in_array($work->kind, [WorkKind::Novel, WorkKind::Collection], true);
        $body = $this->firstBody($work);

        return [
            ...$card,
            'kindWord' => $this->kindWord($work),
            'statusLine' => WorkPresenter::statusLine($work),
            'hasUnits' => $hasUnits,
            'count' => $rows->count(),
            'chapters' => $hasUnits ? WorkPresenter::newestItems($work, 3) : [],
            'startHref' => $hasUnits ? WorkPresenter::itemHref($work, $rows->first()['number'], true) : $card['href'],
            'startLabel' => $work->kind === WorkKind::Novel ? 'Start from chapter 1' : ($hasUnits ? 'Start from the first '.WorkPresenter::unit($work) : 'Start reading'),
            'allLabel' => 'All '.WorkPresenter::units($work, $rows->count()),
            'excerpt' => $hasUnits ? null : ($work->description ?: Reading::firstSentence(Reading::firstParagraph($body)) ?? Reading::firstParagraph($body)),
            'stanza' => $work->kind === WorkKind::Poem ? Reading::firstStanza($body) : [],
        ];
    }

    /**
     * One row: picture, title, kind (and series), a third line, the status fact and the date.
     *
     * @return array<string, mixed>
     */
    private function row(Work $work, bool $inGroup): array
    {
        $card = WorkPresenter::card($work);
        $rows = WorkPresenter::numbered($work);
        $updated = WorkPresenter::updatedAt($work);
        $printOnly = $work->free_chapters !== null;
        $series = $card['series'];

        $sub = $this->kindWord($work);
        if ($series !== null) {
            $sub .= $inGroup ? ' · Book '.$series['position'] : ' · Book '.$series['position'].' of '.$series['name'];
        }

        $line = match (true) {
            $work->status === WorkStatus::ComingSoon => ['type' => 'soon', 'text' => 'Tell me when it’s out'],
            $printOnly => ['type' => 'print', 'text' => $work->free_chapters === 1 ? 'Chapter 1 is free to read' : 'Chapters 1–'.$work->free_chapters.' are free to read'],
            $work->kind === WorkKind::Novel && $work->status === WorkStatus::Finished => ['type' => 'finished', 'text' => 'Finished in '.$this->longMonth($updated)],
            in_array($work->kind, [WorkKind::Novel, WorkKind::Collection], true) => ['type' => 'chapter', ...$this->newest($work)],
            default => ['type' => 'excerpt', 'text' => $this->excerpt($work, $rows)],
        };

        return [
            ...$card,
            'type' => 'work',
            'href' => $printOnly && $rows->isNotEmpty() ? WorkPresenter::itemHref($work, $rows->first()['number']) : $card['href'],
            'sub' => $sub,
            'line' => $line,
            'status' => $printOnly ? ['icon' => 'book', 'text' => 'In print'] : $card['status'],
            'printOnly' => $printOnly,
            'following' => $this->followed()->contains($work->id),
            'dt' => $work->status === WorkStatus::ComingSoon ? null
                : (($work->status === WorkStatus::Finished && $work->kind === WorkKind::Novel) || $printOnly ? Reading::month($updated) : $card['date']),
        ];
    }

    /**
     * The works the signed-in reader follows, among all the works this page can show.
     *
     * @return Collection<int, int>
     */
    private function followed(): Collection
    {
        return $this->followed ??= ReaderMarks::followed('work', $this->works->pluck('id'));
    }

    /**
     * @return array{text: string, isNew: bool}
     */
    private function newest(Work $work): array
    {
        $newest = WorkPresenter::newestItems($work, 1)[0] ?? null;

        return ['text' => $newest['label'] ?? '', 'isNew' => $newest['isNew'] ?? false];
    }

    /**
     * @param  Collection<int, array{number: int, item: WorkItem}>  $rows
     */
    private function excerpt(Work $work, Collection $rows): ?string
    {
        if ($work->description) {
            return $work->description;
        }

        $body = $this->firstBody($work);

        return $work->kind === WorkKind::Poem
            ? (Reading::firstStanza($body)[0]['text'] ?? null)
            : Reading::firstSentence(Reading::firstParagraph($body));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function firstBody(Work $work): ?array
    {
        if ($this->bodies === null) {
            $firstIds = $this->works
                ->mapWithKeys(fn (Work $w): array => [$w->id => WorkPresenter::numbered($w)->first()['item']->id ?? null])
                ->filter();
            $texts = WorkItem::query()->whereKey($firstIds->values())->get(['id', 'live_body'])->keyBy('id');
            $this->bodies = $firstIds->map(fn (int $id): ?array => $texts[$id]->live_body ?? null)->all();
        }

        return $this->bodies[$work->id] ?? null;
    }

    private function kindWord(Work $work): string
    {
        return match ($work->kind) {
            WorkKind::Novel => 'Novel',
            WorkKind::Story => 'Short story',
            WorkKind::Poem => 'Poem',
            WorkKind::Essay => 'Essay',
            WorkKind::Collection => 'Collection',
        };
    }

    private function longMonth(?CarbonInterface $at): string
    {
        if ($at === null) {
            return '';
        }

        $local = $at->copy()->setTimezone(config('eyiwetemi.timezone'));

        return $local->year === now(config('eyiwetemi.timezone'))->year ? $local->format('F') : $local->format('F Y');
    }
}
