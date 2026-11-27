<?php

namespace App\Support;

use App\Enums\CollectionKind;
use App\Enums\PublishStatus;
use App\Enums\StatDimension;
use App\Enums\StatSubject;
use App\Enums\Visibility;
use App\Enums\WorkItemType;
use App\Enums\WorkKind;
use App\Models\DailyBreakdown;
use App\Models\DailyStat;
use App\Models\Follow;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The admin stats page's data: the site's reads, readers and finishes over the range it is on (the last 7 or 30 days,
 * the last 12 months, or all time) against the period before it, then each public work with its chapters and how far
 * readers go on, the notes and the page visits, and where readers come from. Every time is West Africa Time.
 *
 * @phpstan-type Totals array{reads: int, readers: int, finished: int}
 * @phpstan-type Series array{reads: list<int>, readers: list<int>, finished: list<int>}
 * @phpstan-type Entry array{current: Totals, previous: Totals, series: Series}
 * @phpstan-type Span array{from: Carbon, to: Carbon, previous: array{from: Carbon, to: Carbon}|null, monthly: bool, keys: list<string>}
 */
class StatsPage
{
    /** The ranges the page offers, as the query string names them. */
    public const RANGES = ['7', '30', '12', 'all'];

    private const DEFAULT_RANGE = '30';

    /** The icon key of each named source. Any other source is a website's host, drawn as a link. */
    private const SOURCE_KEYS = [
        'Direct' => 'direct',
        'Instagram' => 'ig',
        'X' => 'x_',
        'Newsletter' => 'mail',
        'Google' => 'gg',
        'WhatsApp' => 'wa',
        'Facebook' => 'fb',
        'Goodreads' => 'gr',
        'TikTok' => 'tt',
    ];

    /**
     * The whole stats page for one range: the site's figures and chart, the works read in it, the notes, the page
     * visits, and the sources, countries and devices. An unknown range is read as the last 30 days.
     *
     * @return array{
     *     range: string,
     *     empty: bool,
     *     figures: array{reads: array{v: int, before: int|null}, readers: array{v: int, before: int|null}, finished: array{v: int, before: int|null}},
     *     chart: array{buckets: list<array{lab: string, tip: string, ev: string|null, y?: int}>, reads: list<int>, readers: list<int>, finished: list<int>},
     *     works: list<array{slug: string, title: string, kind: string, cover: array{src: string|null, color: string, dark: bool}, likes: int, follows: int, reads: int, readers: int, finished: int, before: int|null, series: Series, multi: bool, keep: list<float>|null, chapters: list<array{no: int, title: string, reads: int, fin: int}>|null}>,
     *     notes: array{reads: int, count: int},
     *     pages: array{visits: int},
     *     sources: list<array{key: string, label: string, v: int}>,
     *     countries: list<array{code: string, name: string, v: int}>,
     *     phone: int|null,
     * }
     */
    public static function props(string $range): array
    {
        $range = in_array($range, self::RANGES, true) ? $range : self::DEFAULT_RANGE;
        $today = now(self::zone())->startOfDay();
        $span = self::span($range, $today);
        $previous = $span['previous'];
        $fromDay = $span['from']->toDateString();
        $toDay = $span['to']->toDateString();
        $previousFrom = $previous === null ? null : $previous['from']->toDateString();
        $previousTo = $previous === null ? null : $previous['to']->toDateString();
        $keyIndex = array_flip($span['keys']);
        $count = count($span['keys']);

        $site = self::emptyEntry($count);
        $works = [];

        foreach (self::dailyRows($span) as $row) {
            $day = (string) $row->day;
            $current = $day >= $fromDay && $day <= $toDay;
            $prior = $previousFrom !== null && $day >= $previousFrom && $day <= $previousTo;
            $bucket = $current ? ($keyIndex[$span['monthly'] ? substr($day, 0, 7) : $day] ?? null) : null;

            if ((string) $row->subject === StatSubject::Site->value) {
                self::record($site, $row, $current, $prior, $bucket);

                continue;
            }

            $workId = (int) $row->subject_id;
            $works[$workId] ??= self::emptyEntry($count);
            self::record($works[$workId], $row, $current, $prior, $bucket);
        }

        $publicWorks = self::publicWorks();
        $totals = self::rangeTotals($span);
        $follows = self::followCounts($publicWorks);
        $events = self::eventsByDay($publicWorks, $span);

        $workRows = $publicWorks
            ->filter(fn (Work $work): bool => ($works[$work->id]['current']['reads'] ?? 0) > 0)
            ->map(fn (Work $work): array => self::workRow($work, $works[$work->id], $previous !== null, $totals['chapters'], $follows[$work->id] ?? 0))
            ->values()
            ->all();

        usort($workRows, fn (array $a, array $b): int => $b['reads'] <=> $a['reads'] ?: strcmp($a['title'], $b['title']));

        $notes = array_values($totals['notes']);

        return [
            'range' => $range,
            'empty' => ! DailyStat::query()->where('subject', StatSubject::Work)->exists(),
            'figures' => [
                'reads' => self::figure($site, 'reads', $previous !== null),
                'readers' => self::figure($site, 'readers', $previous !== null),
                'finished' => self::figure($site, 'finished', $previous !== null),
            ],
            'chart' => [
                'buckets' => self::buckets($span, $today, $events),
                'reads' => $site['series']['reads'],
                'readers' => $site['series']['readers'],
                'finished' => $site['series']['finished'],
            ],
            'works' => $workRows,
            'notes' => [
                'reads' => array_sum(array_column($notes, 'reads')),
                'count' => count(array_filter($notes, fn (array $note): bool => $note['reads'] > 0)),
            ],
            'pages' => ['visits' => $totals['pages']],
            ...self::audience($span),
        ];
    }

    /**
     * The days or months a range covers, the period before it (none for all time), and the chart's bucket keys.
     *
     * @return Span
     */
    private static function span(string $range, Carbon $today): array
    {
        $from = match ($range) {
            '7', '30' => $today->copy()->subDays((int) $range - 1),
            '12' => $today->copy()->startOfMonth()->subMonths(11),
            default => self::firstDay() ?? $today->copy(),
        };
        $monthly = $range === '12' || $range === 'all';
        $previous = null;

        if ($range !== 'all') {
            $length = (int) $from->diffInDays($today) + 1;
            $previous = ['from' => $from->copy()->subDays($length), 'to' => $from->copy()->subDay()];
        }

        return [
            'from' => $from,
            'to' => $today,
            'previous' => $previous,
            'monthly' => $monthly,
            'keys' => self::keys($from, $today, $monthly),
        ];
    }

    /**
     * One bucket key per day ("2026-11-27") or per month ("2026-11") from the range's start through today.
     *
     * @return list<string>
     */
    private static function keys(Carbon $from, Carbon $to, bool $monthly): array
    {
        $keys = [];
        $cursor = $monthly ? $from->copy()->startOfMonth() : $from->copy();
        $last = $monthly ? $to->copy()->startOfMonth() : $to->copy();

        while ($cursor->lte($last)) {
            $keys[] = $cursor->format($monthly ? 'Y-m' : 'Y-m-d');

            if ($monthly) {
                $cursor->addMonth();
            } else {
                $cursor->addDay();
            }
        }

        return $keys;
    }

    /**
     * The site and work rows for the range and the period before it, in one query.
     *
     * @return Collection<int, object>
     */
    private static function dailyRows(array $span): Collection
    {
        $from = $span['previous']['from'] ?? $span['from'];

        return DailyStat::query()
            ->whereIn('subject', [StatSubject::Site, StatSubject::Work])
            ->whereBetween('day', [$from->toDateString(), $span['to']->toDateString()])
            ->toBase()
            ->get(['day', 'subject', 'subject_id', 'reads', 'readers', 'finished']);
    }

    /**
     * The chapter, note and page totals over the range, keyed by chapter and note id.
     *
     * @return array{chapters: array<int, Totals>, notes: array<int, Totals>, pages: int}
     */
    private static function rangeTotals(array $span): array
    {
        $rows = DailyStat::query()
            ->whereIn('subject', [StatSubject::Chapter, StatSubject::Note, StatSubject::Page])
            ->whereBetween('day', [$span['from']->toDateString(), $span['to']->toDateString()])
            ->groupBy('subject', 'subject_id')
            ->selectRaw('subject, subject_id, sum(reads) as reads, sum(readers) as readers, sum(finished) as finished')
            ->toBase()
            ->get();

        $totals = ['chapters' => [], 'notes' => [], 'pages' => 0];

        foreach ($rows as $row) {
            $subject = (string) $row->subject;
            $entry = ['reads' => (int) $row->reads, 'readers' => (int) $row->readers, 'finished' => (int) $row->finished];

            if ($subject === StatSubject::Chapter->value) {
                $totals['chapters'][(int) $row->subject_id] = $entry;
            } elseif ($subject === StatSubject::Note->value) {
                $totals['notes'][(int) $row->subject_id] = $entry;
            } else {
                $totals['pages'] += $entry['reads'];
            }
        }

        return $totals;
    }

    /**
     * Where the range's readers came from, the countries they were in and the share on phones.
     *
     * @param  Span  $span
     * @return array{sources: list<array{key: string, label: string, v: int}>, countries: list<array{code: string, name: string, v: int}>, phone: int|null}
     */
    private static function audience(array $span): array
    {
        $rows = DailyBreakdown::query()
            ->whereBetween('day', [$span['from']->toDateString(), $span['to']->toDateString()])
            ->groupBy('dimension', 'value')
            ->selectRaw('dimension, value, sum(reads) as reads')
            ->toBase()
            ->get();

        $sources = [];
        $countries = [];
        $phone = 0;
        $computer = 0;

        foreach ($rows as $row) {
            $value = (string) $row->value;
            $reads = (int) $row->reads;
            $dimension = (string) $row->dimension;

            if ($dimension === StatDimension::Source->value) {
                $sources[] = ['key' => self::SOURCE_KEYS[$value] ?? 'link', 'label' => $value, 'v' => $reads];
            } elseif ($dimension === StatDimension::Country->value) {
                $code = strtolower($value);
                $countries[] = ['code' => $code, 'name' => self::countryName($code), 'v' => $reads];
            } elseif ($value === 'phone') {
                $phone += $reads;
            } elseif ($value === 'computer') {
                $computer += $reads;
            }
        }

        usort($sources, fn (array $a, array $b): int => $b['v'] <=> $a['v'] ?: strcmp($a['label'], $b['label']));
        usort($countries, fn (array $a, array $b): int => $b['v'] <=> $a['v'] ?: strcmp($a['name'], $b['name']));

        return [
            'sources' => array_values($sources),
            'countries' => array_values($countries),
            'phone' => $phone + $computer > 0 ? (int) round($phone / ($phone + $computer) * 100) : null,
        ];
    }

    /**
     * The name of a country from its two-letter code, in English. The code itself when intl cannot name it.
     */
    private static function countryName(string $code): string
    {
        $name = extension_loaded('intl') ? \Locale::getDisplayRegion('-'.$code, 'en') : '';

        return $name !== '' ? $name : strtoupper($code);
    }

    /**
     * An empty entry for one site or work: the current range's totals, the period before it, and the series per bucket.
     *
     * @return Entry
     */
    private static function emptyEntry(int $count): array
    {
        return [
            'current' => ['reads' => 0, 'readers' => 0, 'finished' => 0],
            'previous' => ['reads' => 0, 'readers' => 0, 'finished' => 0],
            'series' => [
                'reads' => array_fill(0, $count, 0),
                'readers' => array_fill(0, $count, 0),
                'finished' => array_fill(0, $count, 0),
            ],
        ];
    }

    /**
     * Adds one daily row to an entry's totals, its period before and its bucket.
     *
     * @param  Entry  $entry
     */
    private static function record(array &$entry, object $row, bool $current, bool $prior, ?int $bucket): void
    {
        $values = ['reads' => (int) $row->reads, 'readers' => (int) $row->readers, 'finished' => (int) $row->finished];

        if ($current) {
            foreach ($values as $metric => $value) {
                $entry['current'][$metric] += $value;

                if ($bucket !== null) {
                    $entry['series'][$metric][$bucket] += $value;
                }
            }
        }

        if ($prior) {
            foreach ($values as $metric => $value) {
                $entry['previous'][$metric] += $value;
            }
        }
    }

    /**
     * One of the three figures: the range's total and the period before it (null for all time).
     *
     * @param  Entry  $entry
     * @return array{v: int, before: int|null}
     */
    private static function figure(array $entry, string $metric, bool $hasPrevious): array
    {
        return [
            'v' => $entry['current'][$metric],
            'before' => $hasPrevious ? $entry['previous'][$metric] : null,
        ];
    }

    /**
     * The chart's buckets: a day with what went up that day, or a month (with its year) for the 12-month and all-time ranges.
     *
     * @param  Span  $span
     * @param  array<string, string>  $events
     * @return list<array{lab: string, tip: string, ev: string|null, y?: int}>
     */
    private static function buckets(array $span, Carbon $today, array $events): array
    {
        $currentMonth = $today->format('Y-m');

        return array_map(function (string $key) use ($span, $currentMonth, $events): array {
            if ($span['monthly']) {
                $month = Carbon::createFromFormat('Y-m-d', $key.'-01', self::zone())->startOfDay();
                $tip = $month->format('F Y').($key === $currentMonth ? ', so far' : '');

                return ['lab' => $month->format('M'), 'tip' => $tip, 'ev' => null, 'y' => (int) $month->format('Y')];
            }

            $day = Carbon::createFromFormat('Y-m-d', $key, self::zone())->startOfDay();

            return ['lab' => $day->format('j M'), 'tip' => $day->format('D j M'), 'ev' => $events[$key] ?? null];
        }, $span['keys']);
    }

    /**
     * What went up on each day of a day-by-day chart: a chapter or piece that came out, or a work's first release.
     * Two at most, joined with a middle dot. Monthly charts carry no such line.
     *
     * @param  Collection<int, Work>  $works
     * @param  Span  $span
     * @return array<string, string>
     */
    private static function eventsByDay(Collection $works, array $span): array
    {
        if ($span['monthly']) {
            return [];
        }

        $wanted = array_flip($span['keys']);
        $lines = [];

        foreach ($works as $work) {
            $dated = $work->items->filter(fn (WorkItem $item): bool => $item->published_at !== null)->values();

            if ($dated->isEmpty()) {
                continue;
            }

            $multi = self::publishedItems($work)->count() > 1;
            $first = $dated->sortBy(fn (WorkItem $item): int => $item->published_at->getTimestamp())->first();
            $numbers = self::numbersOf($work);

            foreach ($dated as $item) {
                $day = $item->published_at->copy()->setTimezone(self::zone())->toDateString();

                if (! isset($wanted[$day])) {
                    continue;
                }

                $lines[$day][] = [
                    'at' => $item->published_at->getTimestamp(),
                    'id' => $item->id,
                    'text' => $multi && $item->id !== $first->id
                        ? $work->title.', '.WorkPresenter::unit($work).' '.$numbers[$item->id].' went up'
                        : $work->title.' went up',
                ];
            }
        }

        $events = [];

        foreach ($lines as $day => $items) {
            usort($items, fn (array $a, array $b): int => [$a['at'], $a['id']] <=> [$b['at'], $b['id']]);
            $events[$day] = implode(' · ', array_column(array_slice($items, 0, 2), 'text'));
        }

        return $events;
    }

    /**
     * Every public work with what the page draws about it, the picture and the counts of likes and follows.
     *
     * @return Collection<int, Work>
     */
    private static function publicWorks(): Collection
    {
        return Work::query()
            ->where('visibility', Visibility::Public)
            ->with([
                'cover',
                'items' => fn (Relation $query) => $query
                    ->whereIn('type', [WorkItemType::Chapter, WorkItemType::Piece])
                    ->whereIn('status', [PublishStatus::Published, PublishStatus::Unpublished])
                    ->select(['id', 'work_id', 'type', 'position', 'title', 'status', 'published_at']),
            ])
            ->get(['id', 'title', 'slug', 'kind', 'collection_kind', 'cover_media_id', 'cover_color', 'likes_count']);
    }

    /**
     * How many readers follow each work, keyed by work id.
     *
     * @param  Collection<int, Work>  $works
     * @return array<int, int>
     */
    private static function followCounts(Collection $works): array
    {
        return Follow::query()
            ->where('followable_type', (new Work)->getMorphClass())
            ->whereIn('followable_id', $works->pluck('id')->all())
            ->groupBy('followable_id')
            ->selectRaw('followable_id, count(*) as total')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->followable_id => (int) $row->total])
            ->all();
    }

    /**
     * One work's row for the page: its counts over the range, its series, and its chapters with the keep-going curve for novels.
     *
     * @param  Entry  $entry
     * @param  array<int, Totals>  $chapters
     * @return array{slug: string, title: string, kind: string, cover: array{src: string|null, color: string, dark: bool}, likes: int, follows: int, reads: int, readers: int, finished: int, before: int|null, series: Series, multi: bool, keep: list<float>|null, chapters: list<array{no: int, title: string, reads: int, fin: int}>|null}
     */
    private static function workRow(Work $work, array $entry, bool $hasPrevious, array $chapters, int $follows): array
    {
        $published = self::publishedItems($work);
        $multi = $published->count() > 1;

        return [
            'slug' => $work->slug,
            'title' => $work->title,
            'kind' => self::kindLabel($work),
            'cover' => WorkPresenter::cover($work),
            'likes' => (int) $work->likes_count,
            'follows' => $follows,
            'reads' => $entry['current']['reads'],
            'readers' => $entry['current']['readers'],
            'finished' => $entry['current']['finished'],
            'before' => $hasPrevious ? $entry['previous']['reads'] : null,
            'series' => $entry['series'],
            'multi' => $multi,
            'keep' => $multi && $work->kind === WorkKind::Novel ? self::keepCurve($published, $chapters) : null,
            'chapters' => $multi ? self::chapterRows($work, $published, $chapters) : null,
        ];
    }

    /**
     * The share of the first chapter's readers still reading each chapter in reading order, as percentages to one decimal.
     * Null when the first chapter had no readers in the range, since there is then nothing to compare with.
     *
     * @param  Collection<int, WorkItem>  $published
     * @param  array<int, Totals>  $chapters
     * @return list<float>|null
     */
    private static function keepCurve(Collection $published, array $chapters): ?array
    {
        $firstReaders = $chapters[$published->first()->id]['readers'] ?? 0;

        if ($firstReaders === 0) {
            return null;
        }

        return $published
            ->map(fn (WorkItem $item): float => round(($chapters[$item->id]['readers'] ?? 0) / $firstReaders * 100, 1))
            ->values()
            ->all();
    }

    /**
     * The published chapters or pieces, newest first, with their reading number, title, reads and share finished.
     *
     * @param  Collection<int, WorkItem>  $published
     * @param  array<int, Totals>  $chapters
     * @return list<array{no: int, title: string, reads: int, fin: int}>
     */
    private static function chapterRows(Work $work, Collection $published, array $chapters): array
    {
        $numbers = self::numbersOf($work);

        return $published
            ->reverse()
            ->values()
            ->map(function (WorkItem $item) use ($numbers, $chapters): array {
                $totals = $chapters[$item->id] ?? ['reads' => 0, 'readers' => 0, 'finished' => 0];

                return [
                    'no' => $numbers[$item->id],
                    'title' => (string) $item->title,
                    'reads' => $totals['reads'],
                    'fin' => $totals['reads'] > 0 ? (int) round($totals['finished'] / $totals['reads'] * 100) : 0,
                ];
            })
            ->all();
    }

    /**
     * The published chapters or pieces of a work, in reading order.
     *
     * @return Collection<int, WorkItem>
     */
    private static function publishedItems(Work $work): Collection
    {
        return $work->items
            ->filter(fn (WorkItem $item): bool => $item->status === PublishStatus::Published && $item->published_at !== null)
            ->values();
    }

    /**
     * The reading number of each chapter or piece a work's items list (chapters and pieces, parts left out), keyed by item id.
     *
     * @return array<int, int>
     */
    private static function numbersOf(Work $work): array
    {
        return $work->items
            ->values()
            ->mapWithKeys(fn (WorkItem $item, int $index): array => [$item->id => $index + 1])
            ->all();
    }

    /**
     * What a work is called on the page: a novel, a short story, a poem, an essay, or a poetry, story or essay collection.
     */
    private static function kindLabel(Work $work): string
    {
        return match ($work->kind) {
            WorkKind::Novel => 'Novel',
            WorkKind::Story => 'Short story',
            WorkKind::Poem => 'Poem',
            WorkKind::Essay => 'Essay',
            WorkKind::Collection => match ($work->collection_kind) {
                CollectionKind::Poem => 'Poetry collection',
                CollectionKind::Essay => 'Essay collection',
                default => 'Story collection',
            },
        };
    }

    /**
     * The first day with any stats, in West Africa Time, or null when there are none.
     */
    private static function firstDay(): ?Carbon
    {
        $first = DailyStat::query()->min('day');

        return $first === null ? null : Carbon::parse((string) $first, self::zone())->startOfDay();
    }

    private static function zone(): string
    {
        return (string) config('eyiwetemi.timezone');
    }
}
