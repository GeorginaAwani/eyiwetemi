<?php

namespace App\Support;

use App\Enums\StatDimension;
use App\Enums\StatSubject;
use App\Models\DailyBreakdown;
use App\Models\DailyStat;
use App\Models\DailyVisitor;
use App\Models\Note;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Daily totals for the reading site: reads, readers and finishes for each item, note and page, per West Africa day.
 * Nothing is kept per visit and nothing is set on the browser. A reader is recognised only by a fingerprint of their
 * address and browser, salted with a secret that is made fresh each day and expires with it.
 */
class ReadCounter
{
    /** Sites a day can list as where readers came from before new ones count as "Other". */
    private const SOURCES_PER_DAY = 100;

    /** Crawlers, link previews, scripts and headless browsers. A WhatsApp link preview is its fetcher, which starts with the name. */
    private const BOT_AGENT = '/bot|crawl|spider|slurp|facebookexternalhit|preview|headless|curl|wget|python|http-client|^WhatsApp\//i';

    /**
     * Counts one hit from the reading site. The controller answers every hit the same way, so nothing here reports back.
     *
     * @param  array{t: string, e: string, id?: int|string|null, page?: string|null, from?: string|null, ref?: string|null}  $input
     */
    public function record(Request $request, array $input): void
    {
        if ($this->isExcluded($request)) {
            return;
        }

        $day = now(config('eyiwetemi.timezone'))->toDateString();
        $hash = $this->fingerprint($request, $day);
        $id = (int) ($input['id'] ?? 0);
        $reading = $input['e'] === 'read';

        DB::transaction(function () use ($request, $input, $id, $day, $hash, $reading): void {
            match (true) {
                $input['t'] === 'item' && $reading => $this->itemRead($request, $input, $id, $day, $hash),
                $input['t'] === 'item' => $this->itemFinished($id, $day, $hash),
                $input['t'] === 'note' && $reading => $this->noteRead($id, $day, $hash),
                $input['t'] === 'page' && $reading => $this->pageRead((string) $input['page'], $day, $hash),
                default => null,
            };
        });
    }

    /**
     * Hits that are not readers: no browser or a robot's, prefetches, and the site's owner and reviewers while signed in.
     */
    private function isExcluded(Request $request): bool
    {
        $agent = (string) $request->userAgent();

        if ($agent === '' || preg_match(self::BOT_AGENT, $agent) === 1) {
            return true;
        }

        if (preg_match('/prefetch|prerender/i', (string) $request->header('Sec-Purpose', '').' '.(string) $request->header('Purpose', '')) === 1) {
            return true;
        }

        $user = $request->user();

        return $user instanceof User && ($user->isAuthor() || $user->isReviewer());
    }

    /**
     * A reader's fingerprint for the day: a salted hash of their address and browser. It is never stored.
     */
    private function fingerprint(Request $request, string $day): string
    {
        return hash('sha256', $this->salt($day).'|'.$request->ip().'|'.$request->userAgent());
    }

    /**
     * The day's secret, made from the app's own key and the day, so it is never stored and changes every day.
     */
    private function salt(string $day): string
    {
        return hash_hmac('sha256', 'stats-salt|'.$day, (string) config('app.key'));
    }

    /**
     * A reader opens a chapter or piece. Counted once per reader per day. The chapter's own row is kept only when the work
     * has more than one readable item; a single story, poem or essay has only the work's row.
     *
     * @param  array<string, mixed>  $input
     */
    private function itemRead(Request $request, array $input, int $itemId, string $day, string $hash): void
    {
        $item = $this->readableItem($itemId);

        if ($item === null) {
            return;
        }

        $readScope = "r:i:{$itemId}";
        $workScope = "w:{$item->work_id}";
        $new = $this->newToday([$readScope, $workScope, 'site'], $day, $hash);

        if (! in_array($readScope, $new, true)) {
            return;
        }

        $rows = [
            $this->row(StatSubject::Work, $item->work_id, $item->work_id, [
                'reads' => 1,
                'readers' => in_array($workScope, $new, true) ? 1 : 0,
            ]),
            $this->row(StatSubject::Site, 0, null, [
                'reads' => 1,
                'readers' => in_array('site', $new, true) ? 1 : 0,
            ]),
        ];

        if ($this->hasSeveralReadable($item)) {
            $rows[] = $this->row(StatSubject::Chapter, $itemId, $item->work_id, ['reads' => 1, 'readers' => 1]);
        }

        $this->addToStats($day, $rows);
        $this->addToBreakdowns($day, $this->breakdowns($request, $input));
    }

    /**
     * A reader reaches the end of a chapter or piece. Counted once per reader per day, and only for a reader who was
     * counted as reading it today.
     */
    private function itemFinished(int $itemId, string $day, string $hash): void
    {
        $item = $this->readableItem($itemId);

        if ($item === null || ! DailyVisitor::query()->where('day', $day)->where('scope', "r:i:{$itemId}")->where('hash', $hash)->exists()) {
            return;
        }

        if ($this->newToday(["f:i:{$itemId}"], $day, $hash) === []) {
            return;
        }

        $rows = [
            $this->row(StatSubject::Work, $item->work_id, $item->work_id, ['finished' => 1]),
            $this->row(StatSubject::Site, 0, null, ['finished' => 1]),
        ];

        if ($this->hasSeveralReadable($item)) {
            $rows[] = $this->row(StatSubject::Chapter, $itemId, $item->work_id, ['finished' => 1]);
        }

        $this->addToStats($day, $rows);
    }

    /**
     * A reader opens a published note. Counted once per reader per day.
     */
    private function noteRead(int $noteId, string $day, string $hash): void
    {
        if (! Note::query()->released()->whereKey($noteId)->exists() || $this->newToday(["r:n:{$noteId}"], $day, $hash) === []) {
            return;
        }

        $this->addToStats($day, [$this->row(StatSubject::Note, $noteId, null, ['reads' => 1, 'readers' => 1])]);
    }

    /**
     * A reader opens one of the site's pages. Reads count once per reader per page per day; readers count each reader
     * once per day across all of them.
     */
    private function pageRead(string $page, string $day, string $hash): void
    {
        $pageScope = "p:{$page}";
        $new = $this->newToday([$pageScope, 'pages'], $day, $hash);

        if ($new === []) {
            return;
        }

        $this->addToStats($day, [$this->row(StatSubject::Page, 0, null, [
            'reads' => in_array($pageScope, $new, true) ? 1 : 0,
            'readers' => in_array('pages', $new, true) ? 1 : 0,
        ])]);
    }

    /**
     * The item, when a visitor may read it: published and out, in a work the public can open.
     */
    private function readableItem(int $itemId): ?WorkItem
    {
        $item = WorkItem::query()->released()->with('work')->find($itemId);

        return $item !== null && Gate::forUser(null)->allows('view', $item->work) ? $item : null;
    }

    private function hasSeveralReadable(WorkItem $item): bool
    {
        return WorkItem::query()->released()->where('work_id', $item->work_id)->count() > 1;
    }

    /**
     * The scopes this reader had not been counted in today. They are recorded as counted in the same statement.
     *
     * @param  list<string>  $scopes
     * @return list<string>
     */
    private function newToday(array $scopes, string $day, string $hash): array
    {
        return DailyVisitor::query()->toBase()->insertOrIgnoreReturning(
            array_map(fn (string $scope): array => ['day' => $day, 'scope' => $scope, 'hash' => $hash], $scopes),
            ['scope'],
            ['day', 'scope', 'hash'],
        )->pluck('scope')->all();
    }

    /**
     * @param  array{reads?: int, readers?: int, finished?: int}  $counts
     * @return array{subject: StatSubject, subject_id: int, work_id: int|null, reads?: int, readers?: int, finished?: int}
     */
    private function row(StatSubject $subject, int $subjectId, ?int $workId, array $counts): array
    {
        return ['subject' => $subject, 'subject_id' => $subjectId, 'work_id' => $workId] + $counts;
    }

    /**
     * Adds the counts to today's rows. The increments run inside the database, so simultaneous hits are never lost.
     *
     * @param  list<array{subject: StatSubject, subject_id: int, work_id: int|null, reads?: int, readers?: int, finished?: int}>  $rows
     */
    private function addToStats(string $day, array $rows): void
    {
        DailyStat::query()->upsert(
            array_map(fn (array $row): array => [
                'day' => $day,
                'subject' => $row['subject']->value,
                'subject_id' => $row['subject_id'],
                'work_id' => $row['work_id'],
                'reads' => $row['reads'] ?? 0,
                'readers' => $row['readers'] ?? 0,
                'finished' => $row['finished'] ?? 0,
            ], $rows),
            ['day', 'subject', 'subject_id'],
            $this->addUp('daily_stats', ['reads', 'readers', 'finished']),
        );
    }

    /**
     * @return list<array{dimension: StatDimension, value: string}>
     */
    private function breakdowns(Request $request, array $input): array
    {
        $labels = [
            ['dimension' => StatDimension::Source, 'value' => ReadAttribution::source($request, $input['from'] ?? null, $input['ref'] ?? null)],
            ['dimension' => StatDimension::Device, 'value' => ReadAttribution::device($request)],
        ];

        $country = ReadAttribution::country($request);

        if ($country !== null) {
            $labels[] = ['dimension' => StatDimension::Country, 'value' => $country];
        }

        return $labels;
    }

    /**
     * @param  list<array{dimension: StatDimension, value: string}>  $labels
     */
    private function addToBreakdowns(string $day, array $labels): void
    {
        $labels = array_map(fn (array $label): array => $this->boundedSource($day, $label), $labels);

        DailyBreakdown::query()->upsert(
            array_map(fn (array $label): array => [
                'day' => $day,
                'dimension' => $label['dimension']->value,
                'value' => $label['value'],
                'reads' => 1,
            ], $labels),
            ['day', 'dimension', 'value'],
            $this->addUp('daily_breakdowns', ['reads']),
        );
    }

    /**
     * Where readers came from is whatever site sent them, so a day keeps at most SOURCES_PER_DAY of them;
     * after that a new one counts as "Other" (the counting endpoint is open to anyone, and each site is a row).
     *
     * @param  array{dimension: StatDimension, value: string}  $label
     * @return array{dimension: StatDimension, value: string}
     */
    private function boundedSource(string $day, array $label): array
    {
        if ($label['dimension'] !== StatDimension::Source) {
            return $label;
        }

        $known = DailyBreakdown::query()->where('day', $day)->where('dimension', StatDimension::Source->value);

        if ((clone $known)->where('value', $label['value'])->exists() || $known->count() < self::SOURCES_PER_DAY) {
            return $label;
        }

        return ['dimension' => StatDimension::Source, 'value' => 'Other'];
    }

    /**
     * Upsert rules that add the incoming counts to the stored ones.
     *
     * @param  list<string>  $columns
     * @return array<string, Expression>
     */
    private function addUp(string $table, array $columns): array
    {
        return collect($columns)
            ->mapWithKeys(fn (string $column): array => [$column => DB::raw("{$table}.{$column} + excluded.{$column}")])
            ->all();
    }
}
