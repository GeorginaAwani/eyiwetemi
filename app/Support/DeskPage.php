<?php

namespace App\Support;

use App\Enums\CommentAudience;
use App\Enums\CommentKind;
use App\Enums\CommentStatus;
use App\Enums\PublishStatus;
use App\Enums\SitePageKey;
use App\Enums\StatSubject;
use App\Enums\UserRole;
use App\Enums\WorkItemType;
use App\Enums\WorkKind;
use App\Models\Comment;
use App\Models\DailyStat;
use App\Models\Like;
use App\Models\LinkCheck;
use App\Models\Note;
use App\Models\Notice;
use App\Models\SitePage;
use App\Models\SiteSetting;
use App\Models\Subscriber;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use App\Models\WritingDay;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use NumberFormatter;

/**
 * The admin desk's data: the draft to go back to, the reader comments and reviewer marks waiting, the week's reads
 * and words, what is scheduled, the notes in hand, and the site items that need a look. Every time is West Africa Time.
 *
 * @phpstan-type Cover array{src: string|null, color: string, dark: bool}
 */
class DeskPage
{
    /** Columns of a work the desk reads: its address, title, kind and picture. */
    private const WORK_COLUMNS = ['id', 'title', 'slug', 'kind', 'collection_kind', 'cover_media_id', 'cover_color'];

    /** Columns of a chapter, piece or part the desk reads. */
    private const ITEM_COLUMNS = ['id', 'work_id', 'piece_work_id', 'type', 'position', 'title', 'status', 'draft_words', 'draft_saved_at', 'scheduled_at'];

    /** Words for 0 to 19, used when intl is not installed. */
    private const ONES = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];

    /** Tens words for 20 to 90, used when intl is not installed. */
    private const TENS = [2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty', 6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety'];

    /**
     * Everything the desk page draws, in the order it shows it.
     *
     * @return array{
     *     lead: array{href: string, cover: Cover, kick: string, title: string, meta: string}|null,
     *     comments: array{count: int, rows: list<array{href: string, who: string, on: string, ago: string, text: string}>},
     *     reviewers: array{count: int, rows: list<array{href: string, who: string, ago: string, text: string}>},
     *     week: array{reads: int, before: int, spark: list<int>, top: array{href: string, title: string, cover: Cover, reads: int}|null, words: int, subscribers: int, newSubscribers: int},
     *     scheduled: array{count: int, rows: list<array{href: string, label: string, at: string}>},
     *     notes: array{rows: list<array{href: string, text: string, meta: string, scheduled: bool}>, hearts: int},
     *     site: list<array{kind: string, before: string, bold: string|null, after: string, href: string, action: string}>,
     * }
     */
    public static function props(): array
    {
        return [
            'lead' => self::lead(),
            'comments' => self::comments(),
            'reviewers' => self::reviewers(),
            'week' => self::week(),
            'scheduled' => self::scheduled(),
            'notes' => self::notes(),
            'site' => self::site(),
        ];
    }

    /**
     * The works with a draft being written: the piece touched last in each, newest first.
     *
     * @return list<array{href: string, title: string, meta: string}>
     */
    public static function writingNow(int $take = 4): array
    {
        $latest = self::draftItems()
            ->with(['work' => fn (Relation $query) => $query->select(self::WORK_COLUMNS)])
            ->orderByDesc('draft_saved_at')
            ->orderByDesc('id')
            ->get()
            ->unique('work_id')
            ->take($take)
            ->values();

        $items = self::itemsByWork($latest->pluck('work_id')->all());

        return $latest->map(function (WorkItem $item) use ($items): array {
            $work = $item->work;
            $unit = self::unitNumber($work, $item, $items->get($work->id, collect()));

            return [
                'href' => self::writePath($work, $item),
                'title' => $work->title,
                'meta' => ($unit !== null ? ucfirst($unit).' · ' : '').self::when($item->draft_saved_at),
            ];
        })->values()->all();
    }

    /**
     * The draft to go back to: the one saved last, whether it is a draft or a published piece with edits not out.
     *
     * @return array{href: string, cover: Cover, kick: string, title: string, meta: string}|null
     */
    private static function lead(): ?array
    {
        $item = self::draftItems()
            ->with([
                'work' => fn (Relation $query) => $query->select(self::WORK_COLUMNS)->with('cover'),
                'pieceWork' => fn (Relation $query) => $query->select(['id', 'title']),
            ])
            ->orderByDesc('draft_saved_at')
            ->orderByDesc('id')
            ->first();

        if ($item === null) {
            return null;
        }

        $work = $item->work;
        $items = self::itemsByWork([$work->id])->get($work->id, collect());

        return [
            'href' => self::writePath($work, $item),
            'cover' => WorkPresenter::cover($work),
            'kick' => self::kick($item, $work, $items),
            'title' => $item->title ?: ($item->pieceWork?->title ?? $work->title),
            'meta' => ($item->status === PublishStatus::Draft ? 'Draft' : 'Edits not out')
                .' · '.self::wordCount((int) $item->draft_words)
                .' · '.self::when($item->draft_saved_at),
        ];
    }

    /**
     * Reader comments on the site: how many are unseen, and the three newest of those.
     *
     * @return array{count: int, rows: list<array{href: string, who: string, on: string, ago: string, text: string}>}
     */
    private static function comments(): array
    {
        $readers = self::readerComments();

        $newest = (clone $readers)
            ->whereNull('seen_at')
            ->with([
                'user' => fn (Relation $query) => $query->select(['id', 'username']),
                'work' => fn (Relation $query) => $query->select(self::WORK_COLUMNS),
                'workItem' => fn (Relation $query) => $query->select(self::ITEM_COLUMNS),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        $items = self::itemsByWork($newest->pluck('work_id')->all());

        return [
            'count' => self::newReaderThreads()->count(),
            'rows' => $newest->map(function (Comment $comment) use ($items): array {
                $work = $comment->work;

                return [
                    'href' => '/comments?w='.$work->slug,
                    'who' => $comment->user?->username ?? 'Someone',
                    'on' => self::onLabel($work, $comment->workItem, $items->get($work->id, collect())),
                    'ago' => self::commentAgo($comment->created_at),
                    'text' => (string) $comment->body,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Reviewers' open marks on the drafts: how many are unseen, and the two reviewers who left the newest ones.
     *
     * @return array{count: int, rows: list<array{href: string, who: string, ago: string, text: string}>}
     */
    private static function reviewers(): array
    {
        $marks = self::reviewerMarks()
            ->with([
                'user' => fn (Relation $query) => $query->select(['id', 'name']),
                'work' => fn (Relation $query) => $query->select(self::WORK_COLUMNS),
                'workItem' => fn (Relation $query) => $query->select(self::ITEM_COLUMNS),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $items = self::itemsByWork($marks->pluck('work_id')->unique()->all());

        $rows = $marks->groupBy('user_id')->take(2)->map(function (Collection $group) use ($items): array {
            /** @var Comment $newest */
            $newest = $group->first();
            $work = $newest->work;
            $reviewer = $newest->user;

            $place = $group->filter(fn (Comment $mark): bool => (int) $mark->work_id === (int) $newest->work_id
                && (int) $mark->work_item_id === (int) $newest->work_item_id);
            $notes = $place->filter(fn (Comment $mark): bool => $mark->kind !== CommentKind::Edit)->count();
            $edits = $place->count() - $notes;

            $phrases = [];

            if ($notes > 0) {
                $phrases[] = $notes === 1 ? '1 note' : $notes.' notes';
            }

            if ($edits > 0) {
                $phrases[] = $edits === 1 ? '1 edit' : $edits.' edits';
            }

            return [
                'href' => '/comments?tab=rev&who='.rawurlencode($reviewer->name),
                'who' => $reviewer->name,
                'ago' => self::commentAgo($newest->created_at),
                'text' => 'Left '.implode(' and ', $phrases).' on '.self::placeLabel($work, $newest->workItem, $items->get($work->id, collect())),
            ];
        })->values()->all();

        return [
            'count' => self::reviewerMarks()->whereNull('seen_at')->count(),
            'rows' => $rows,
        ];
    }

    /**
     * Reviewers' open top-level notes and marks on the drafts, from anyone but the writer.
     *
     * @return Builder<Comment>
     */
    public static function reviewerMarks(): Builder
    {
        return Comment::query()
            ->where('audience', CommentAudience::Review)
            ->where('status', CommentStatus::Open)
            ->whereNull('parent_id')
            ->whereNotNull('user_id')
            ->whereNotIn('user_id', self::authorsQuery());
    }

    /**
     * The week so far (the last seven days, today last) against the seven days before it, with the top work and the
     * subscriber counts. Reads are the site's daily total, which is the sum of every work's reads.
     *
     * @return array{reads: int, before: int, spark: list<int>, top: array{href: string, title: string, cover: Cover, reads: int}|null, words: int, subscribers: int, newSubscribers: int}
     */
    private static function week(): array
    {
        $today = now(self::zone())->startOfDay();
        $weekStart = $today->copy()->subDays(6);
        $previousStart = $weekStart->copy()->subDays(7);

        $dailyReads = DailyStat::query()
            ->where('subject', StatSubject::Site)
            ->whereBetween('day', [$previousStart->toDateString(), $today->toDateString()])
            ->get()
            ->mapWithKeys(fn (DailyStat $stat): array => [$stat->day->toDateString() => (int) $stat->reads]);

        $spark = array_map(fn (string $day): int => $dailyReads->get($day, 0), self::days($weekStart, 7));

        return [
            'reads' => array_sum($spark),
            'before' => array_sum(array_map(fn (string $day): int => $dailyReads->get($day, 0), self::days($previousStart, 7))),
            'spark' => $spark,
            'top' => self::topWork($weekStart, $today),
            'words' => (int) WritingDay::query()
                ->whereBetween('day', [$weekStart->toDateString(), $today->toDateString()])
                ->sum('words_added'),
            'subscribers' => Subscriber::query()->confirmed()->count(),
            'newSubscribers' => Subscriber::query()->confirmed()->where('confirmed_at', '>=', $weekStart->utc())->count(),
        ];
    }

    /**
     * What is scheduled to go out from now on: how many, and the soonest three.
     *
     * @return array{count: int, rows: list<array{href: string, label: string, at: string}>}
     */
    private static function scheduled(): array
    {
        $upcoming = WorkItem::query()
            ->where('status', PublishStatus::Scheduled)
            ->where('scheduled_at', '>', now()->utc());

        $soonest = (clone $upcoming)
            ->select(self::ITEM_COLUMNS)
            ->with(['work' => fn (Relation $query) => $query->select(self::WORK_COLUMNS)])
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->limit(3)
            ->get();

        $items = self::itemsByWork($soonest->pluck('work_id')->all());

        return [
            'count' => (clone $upcoming)->count(),
            'rows' => $soonest->map(function (WorkItem $item) use ($items): array {
                $work = $item->work;
                $unit = self::unitNumber($work, $item, $items->get($work->id, collect()));

                return [
                    'href' => self::writePath($work, $item),
                    'label' => $unit === null ? $work->title : $work->title.', '.$unit,
                    'at' => self::stamp($item->scheduled_at, false),
                ];
            })->values()->all(),
        ];
    }

    /**
     * The next note going out and the note edited last, with the hearts the notes got since the desk last looked.
     *
     * @return array{rows: list<array{href: string, text: string, meta: string, scheduled: bool}>, hearts: int}
     */
    private static function notes(): array
    {
        $scheduled = Note::query()
            ->where('status', PublishStatus::Scheduled)
            ->where('scheduled_at', '>', now()->utc())
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->first(['id', 'draft_text', 'scheduled_at']);

        $drafted = Note::query()
            ->where('status', PublishStatus::Draft)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first(['id', 'draft_text']);

        $rows = [];

        if ($scheduled !== null) {
            $rows[] = [
                'href' => '/notes?n='.$scheduled->id,
                'text' => (string) $scheduled->draft_text,
                'meta' => self::stamp($scheduled->scheduled_at, true),
                'scheduled' => true,
            ];
        }

        if ($drafted !== null) {
            $rows[] = [
                'href' => '/notes?n='.$drafted->id,
                'text' => (string) $drafted->draft_text,
                'meta' => 'Draft',
                'scheduled' => false,
            ];
        }

        return ['rows' => $rows, 'hearts' => self::hearts()];
    }

    /**
     * What needs a look on the site: store links that did not open, a notice up now, and a Home pin with a date.
     *
     * @return list<array{kind: string, before: string, bold: string|null, after: string, href: string, action: string}>
     */
    private static function site(): array
    {
        return [...self::brokenLinks(), ...self::liveNotice(), ...self::homePin()];
    }

    /**
     * Store links in the published In print page whose last check failed.
     *
     * @return list<array{kind: string, before: string, bold: string|null, after: string, href: string, action: string}>
     */
    private static function brokenLinks(): array
    {
        $stores = [];

        foreach (self::publishedBooks() as $book) {
            foreach ($book['formats'] ?? [] as $format) {
                foreach ($format['stores'] ?? [] as $store) {
                    if (! empty($store['url'])) {
                        $stores[] = ['book' => $book, 'store' => $store, 'hash' => hash('sha256', (string) $store['url'])];
                    }
                }
            }
        }

        $broken = LinkCheck::query()
            ->whereIn('url_hash', array_column($stores, 'hash'))
            ->where('ok', false)
            ->pluck('url_hash')
            ->all();

        return collect($stores)
            ->filter(fn (array $row): bool => in_array($row['hash'], $broken, true))
            ->map(fn (array $row): array => [
                'kind' => 'broken',
                'before' => 'The '.($row['store']['store'] ?? '').' link for ',
                'bold' => (string) ($row['book']['title'] ?? ''),
                'after' => ' didn’t open',
                'href' => '/site?tab=print&open='.rawurlencode((string) ($row['book']['slug'] ?? '')),
                'action' => 'Fix it',
            ])
            ->values()
            ->all();
    }

    /**
     * The notice that is up now, if one is.
     *
     * @return list<array{kind: string, before: string, bold: string|null, after: string, href: string, action: string}>
     */
    private static function liveNotice(): array
    {
        $notice = Notice::query()->live()->latest('starts_at')->first();

        if ($notice === null) {
            return [];
        }

        return [[
            'kind' => 'notice',
            'before' => $notice->ends_at !== null ? 'Notice up until '.self::stamp($notice->ends_at, true) : 'Notice up',
            'bold' => null,
            'after' => '',
            'href' => '/site?tab=notice',
            'action' => 'Edit',
        ]];
    }

    /**
     * The Home pin, while its date is still ahead and its work is listed.
     *
     * @return list<array{kind: string, before: string, bold: string|null, after: string, href: string, action: string}>
     */
    private static function homePin(): array
    {
        $pin = SiteSetting::get('home_pin');

        if (! is_array($pin) || ! isset($pin['slug'], $pin['until'])) {
            return [];
        }

        $until = Carbon::parse($pin['until']);
        $work = $until->isFuture()
            ? Work::query()->listed()->where('slug', $pin['slug'])->first(['id', 'title', 'slug'])
            : null;

        if ($work === null) {
            return [];
        }

        return [[
            'kind' => 'pin',
            'before' => '',
            'bold' => $work->title,
            'after' => ' leads Home until '.self::dayStamp($until),
            'href' => '/site?tab=home',
            'action' => 'Change',
        ]];
    }

    /**
     * The chapter, piece or part a draft is, as the lead names it: a collection piece, a part or a chapter in words,
     * or a single work's kind.
     *
     * @param  Collection<int, WorkItem>  $items
     */
    private static function kick(WorkItem $item, Work $work, Collection $items): string
    {
        if ($work->kind === WorkKind::Collection) {
            return $work->title.', '.WorkPresenter::unit($work).' '.self::numberOf($item, $items);
        }

        if ($item->type === WorkItemType::Part) {
            return $work->title.', part '.self::spell(self::rank($item, $items, [WorkItemType::Part]));
        }

        if ($work->kind === WorkKind::Novel && $item->type === WorkItemType::Chapter) {
            return $work->title.', chapter '.self::spell(self::numberOf($item, $items));
        }

        return WorkPresenter::kindLabel($work);
    }

    /**
     * Chapters, pieces and parts with a draft not yet out: drafts, and published ones whose draft was saved after
     * they went live. Items with no saved draft are left out.
     *
     * @return Builder<WorkItem>
     */
    private static function draftItems(): Builder
    {
        return WorkItem::query()
            ->select(self::ITEM_COLUMNS)
            ->whereNotNull('draft_saved_at')
            ->where(fn (Builder $query) => $query
                ->where('status', PublishStatus::Draft)
                ->orWhere(fn (Builder $query) => $query
                    ->where('status', PublishStatus::Published)
                    ->whereColumn('draft_saved_at', '>', 'live_updated_at')));
    }

    /**
     * Readers' comments that count as new: top-level comments not yet seen, or with a reader's reply not yet seen.
     * Seeing a comment sees its replies too, so a thread is new once, however many replies come in.
     *
     * @return Builder<Comment>
     */
    public static function newReaderThreads(): Builder
    {
        return self::readerComments()
            ->whereNull('parent_id')
            ->where(fn (Builder $query) => $query->whereNull('seen_at')
                ->orWhereHas('replies', fn (Builder $reply) => $reply->whereNull('seen_at')->whereNull('hidden_at')
                    ->where(fn (Builder $who) => $who->whereNull('user_id')->orWhereNotIn('user_id', self::authorsQuery()))));
    }

    /**
     * Public comments that are not hidden and not the writer's own.
     *
     * @return Builder<Comment>
     */
    public static function readerComments(): Builder
    {
        return Comment::query()
            ->where('audience', CommentAudience::Public)
            ->whereNull('hidden_at')
            ->where(fn (Builder $query) => $query->whereNull('user_id')->orWhereNotIn('user_id', self::authorsQuery()));
    }

    /**
     * The writer's account ids, as a subquery.
     *
     * @return Builder<User>
     */
    private static function authorsQuery(): Builder
    {
        return User::query()->select('id')->where('role', UserRole::Author);
    }

    /**
     * The chapters, pieces and parts of the given works in reading order, keyed by work id.
     *
     * @param  array<int, int|string>  $workIds
     * @return Collection<int|string, Collection<int, WorkItem>>
     */
    private static function itemsByWork(array $workIds): Collection
    {
        return WorkItem::query()
            ->whereIn('work_id', $workIds)
            ->orderBy('position')
            ->get(['id', 'work_id', 'type', 'position'])
            ->groupBy('work_id');
    }

    /**
     * The most-read work this week, or null when none was read.
     *
     * @return array{href: string, title: string, cover: Cover, reads: int}|null
     */
    private static function topWork(CarbonInterface $from, CarbonInterface $to): ?array
    {
        $row = DailyStat::query()
            ->where('subject', StatSubject::Work)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('subject_id, sum(reads) as total_reads')
            ->groupBy('subject_id')
            ->havingRaw('sum(reads) > 0')
            ->orderByDesc('total_reads')
            ->orderBy('subject_id')
            ->first();

        $work = $row === null ? null : Work::query()->with('cover')->find((int) $row->subject_id);

        if ($work === null) {
            return null;
        }

        return [
            'href' => '/stats?r=7&open='.$work->slug,
            'title' => $work->title,
            'cover' => WorkPresenter::cover($work),
            'reads' => (int) $row->total_reads,
        ];
    }

    /**
     * The unique readers the desk has been told about since it last looked at hearts, or the last seven days.
     */
    private static function hearts(): int
    {
        $seenAt = SiteSetting::get('desk.hearts_seen_at');
        $since = is_string($seenAt) && $seenAt !== '' ? Carbon::parse($seenAt) : now()->subDays(7);

        return Like::query()
            ->where('likeable_type', (new Note)->getMorphClass())
            ->where('created_at', '>', $since->utc())
            ->count();
    }

    /**
     * The published In print books, from the live page.
     *
     * @return list<array<string, mixed>>
     */
    private static function publishedBooks(): array
    {
        $live = SitePage::query()->firstWhere('key', SitePageKey::InPrint)?->live;

        return is_array($live) ? array_values($live['books'] ?? []) : [];
    }

    /**
     * The label for a comment's place: "The Ember Gate · Chapter 7", or the title alone when there is one chapter or piece.
     *
     * @param  Collection<int, WorkItem>  $items
     */
    private static function onLabel(Work $work, ?WorkItem $item, Collection $items): string
    {
        $unit = $item !== null && self::hasSeveralNumbered($items) ? self::unitNumber($work, $item, $items) : null;

        return $unit === null ? $work->title : $work->title.' · '.ucfirst($unit);
    }

    /**
     * The place a reviewer's marks sit: "The Ember Gate, chapter 7", or the title alone when there is one chapter or piece.
     *
     * @param  Collection<int, WorkItem>  $items
     */
    private static function placeLabel(Work $work, ?WorkItem $item, Collection $items): string
    {
        $unit = $item !== null && self::hasSeveralNumbered($items) ? self::unitNumber($work, $item, $items) : null;

        return $unit === null ? $work->title : $work->title.', '.$unit;
    }

    /**
     * "chapter 7" or "poem 13" for a chapter or piece of a novel or collection, or null for anything else.
     *
     * @param  Collection<int, WorkItem>  $items
     */
    public static function unitNumber(Work $work, WorkItem $item, Collection $items): ?string
    {
        if (! in_array($work->kind, [WorkKind::Novel, WorkKind::Collection], true) || $item->type === WorkItemType::Part) {
            return null;
        }

        return WorkPresenter::unit($work).' '.self::numberOf($item, $items);
    }

    /**
     * The number of a chapter or piece among its work's chapters and pieces, parts left out.
     *
     * @param  Collection<int, WorkItem>  $items
     */
    private static function numberOf(WorkItem $item, Collection $items): int
    {
        return self::rank($item, $items, [WorkItemType::Chapter, WorkItemType::Piece]);
    }

    /**
     * The item's place among the items of the given types, counted from one.
     *
     * @param  Collection<int, WorkItem>  $items
     * @param  list<WorkItemType>  $types
     */
    private static function rank(WorkItem $item, Collection $items, array $types): int
    {
        $index = $items
            ->filter(fn (WorkItem $sibling): bool => in_array($sibling->type, $types, true))
            ->values()
            ->search(fn (WorkItem $sibling): bool => (int) $sibling->id === (int) $item->id);

        return $index === false ? 1 : $index + 1;
    }

    /**
     * Whether a work has more than one chapter or piece to tell apart.
     *
     * @param  Collection<int, WorkItem>  $items
     */
    private static function hasSeveralNumbered(Collection $items): bool
    {
        return $items
            ->filter(fn (WorkItem $item): bool => in_array($item->type, [WorkItemType::Chapter, WorkItemType::Piece], true))
            ->count() > 1;
    }

    /**
     * The seven-day span starting at a day, as dates.
     *
     * @return list<string>
     */
    private static function days(CarbonInterface $from, int $count): array
    {
        return array_map(
            fn (int $offset): string => $from->copy()->addDays($offset)->toDateString(),
            range(0, $count - 1),
        );
    }

    /**
     * A chapter or piece number in words: "eight", "twenty-one".
     */
    private static function spell(int $number): string
    {
        $spelled = extension_loaded('intl')
            ? (new NumberFormatter('en', NumberFormatter::SPELLOUT))->format($number)
            : false;

        return $spelled !== false ? $spelled : self::spellSmall($number);
    }

    /**
     * Words for numbers up to 999, for when intl is not installed.
     */
    private static function spellSmall(int $number): string
    {
        if ($number < 20) {
            return self::ONES[$number] ?? (string) $number;
        }

        if ($number < 100) {
            return self::TENS[intdiv($number, 10)].($number % 10 !== 0 ? '-'.self::ONES[$number % 10] : '');
        }

        if ($number < 1000) {
            return self::ONES[intdiv($number, 100)].' hundred'.($number % 100 !== 0 ? ' '.self::spellSmall($number % 100) : '');
        }

        return (string) $number;
    }

    /**
     * How long ago a comment was, as the comment list writes it: "Now", "5 min", "2 h", "Yesterday", "3 d", "2 w".
     */
    private static function commentAgo(CarbonInterface $at): string
    {
        $seconds = max(0, now()->getTimestamp() - $at->getTimestamp());

        return match (true) {
            $seconds < 60 => 'Now',
            $seconds < 3600 => intdiv($seconds, 60).' min',
            $seconds < 86400 => intdiv($seconds, 3600).' h',
            $seconds < 2 * 86400 => 'Yesterday',
            $seconds < 7 * 86400 => intdiv($seconds, 86400).' d',
            default => (int) round($seconds / (7 * 86400)).' w',
        };
    }

    /**
     * When a draft was last saved, as the writing list writes it: "Just now", "25 min ago", "2 h ago", "Yesterday",
     * "Mon" within the week, then "12 Nov" (with the year when it is not this year).
     */
    public static function when(CarbonInterface $at): string
    {
        $zone = self::zone();
        $seconds = max(0, now()->getTimestamp() - $at->getTimestamp());

        if ($seconds < 60) {
            return 'Just now';
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).' min ago';
        }

        $local = $at->copy()->setTimezone($zone);
        $today = now($zone)->startOfDay();
        $days = (int) $local->copy()->startOfDay()->diffInDays($today, true);

        if ($days === 0) {
            return intdiv($seconds, 3600).' h ago';
        }

        if ($days === 1) {
            return 'Yesterday';
        }

        if ($days < 7) {
            return $local->format('D');
        }

        return $local->format($local->year === $today->year ? 'j M' : 'j M Y');
    }

    /**
     * A day and time: "Fri 28 Nov, 20:00", or "Sat 28 Nov, 9:00 am" when twelve-hour; the year follows the date when it is not this year.
     */
    private static function stamp(CarbonInterface $at, bool $twelveHour): string
    {
        return self::dayStamp($at).', '.$at->copy()->setTimezone(self::zone())->format($twelveHour ? 'g:i a' : 'H:i');
    }

    /**
     * A day alone: "Fri 28 Nov", with the year when it is not this year.
     */
    private static function dayStamp(CarbonInterface $at): string
    {
        $local = $at->copy()->setTimezone(self::zone());

        return $local->format($local->year === now(self::zone())->year ? 'D j M' : 'D j M Y');
    }

    /**
     * "Draft · 1,840 words" wording for the lead's meta.
     */
    private static function wordCount(int $words): string
    {
        return $words === 1 ? '1 word' : number_format($words).' words';
    }

    /**
     * The editor address of a chapter, piece or part.
     */
    private static function writePath(Work $work, WorkItem $item): string
    {
        return '/works/'.$work->slug.'/write/'.$item->id;
    }

    private static function zone(): string
    {
        return (string) config('eyiwetemi.timezone');
    }
}
