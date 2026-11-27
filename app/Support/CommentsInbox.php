<?php

namespace App\Support;

use App\Enums\CommentAudience;
use App\Enums\CommentKind;
use App\Enums\CommentStatus;
use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Enums\WorkItemType;
use App\Models\Comment;
use App\Models\Like;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The comments inbox: readers' comments grouped by chapter or piece, reviewers' notes grouped the same way (open and
 * done apart), the tab counts, the works each tab lists, and the mail switches. Shapes are what the Comments page draws.
 *
 * @phpstan-type Cover array{src: string|null, color: string, dark: bool}
 */
class CommentsInbox
{
    /** Reader groups on one page of the readers list. */
    public const GROUPS_PER_PAGE = 5;

    /** Newest comments a reader group shows even when none of them is new. */
    private const LEAD = 2;

    /** Columns of a work's chapters and pieces the inbox reads. */
    private const ITEM_COLUMNS = ['id', 'work_id', 'type', 'position', 'status', 'title'];

    /** What a comment's writer, and its replies and their writers, load with. */
    private const WITH_USERS = ['user:id,name,username,avatar_seed,role', 'replies.user:id,name,username,avatar_seed,role'];

    /**
     * New readers' comments (a thread counts once, however many replies are new) and new open reviewer notes.
     *
     * @return array{r: int, v: int}
     */
    public static function counts(): array
    {
        return [
            'r' => DeskPage::newReaderThreads()->count(),
            'v' => DeskPage::reviewerMarks()->whereNull('seen_at')->count(),
        ];
    }

    /**
     * The works each tab lists, with what is new in each. Most new first, then by title.
     *
     * @return array{r: list<array{slug: string, title: string, new: int}>, v: list<array{slug: string, title: string, new: int}>}
     */
    public static function works(): array
    {
        $readers = self::fromOthers(Comment::query()
            ->where('audience', CommentAudience::Public)
            ->whereNull('parent_id')
            ->whereNotNull('work_item_id'));

        return [
            'r' => self::workOptions($readers, DeskPage::newReaderThreads()),
            'v' => self::workOptions(DeskPage::reviewerMarks(), DeskPage::reviewerMarks()->whereNull('seen_at')),
        ];
    }

    /**
     * Whether mail goes out each day about readers' comments, and at once about reviewers' notes.
     *
     * @return array{r: bool, v: bool}
     */
    public static function mail(): array
    {
        return [
            'r' => (bool) SiteSetting::get('mail.readers_daily', true),
            'v' => (bool) SiteSetting::get('mail.reviewers_now', true),
        ];
    }

    /**
     * The writer's name and photo, as the inbox shows her.
     *
     * @return array{name: string, photo: string|null}
     */
    public static function me(User $author): array
    {
        return ['name' => $author->name, 'photo' => ReaderPage::authorPhoto()];
    }

    /**
     * Marks comments and notes seen. Only those written by readers or reviewers count; the writer's own are left alone.
     *
     * @param  list<int>  $ids
     */
    public static function markSeen(array $ids): void
    {
        self::fromOthers(Comment::query())
            ->where(fn (Builder $query) => $query->whereIn('id', $ids)->orWhereIn('parent_id', $ids))
            ->whereNull('seen_at')
            ->update(['seen_at' => now()]);
    }

    /**
     * The readers' comments grouped by chapter or piece, the places with the newest comment first, five to a page.
     * Without a search each group leads with its new comments and its two newest; with one, every match shows.
     *
     * @return array{groups: list<array<string, mixed>>, total: int, more: bool, hits: int|null}
     */
    public static function readerGroups(User $author, ?string $slug, ?string $search, int $page): array
    {
        $matching = self::readerThreads($slug, $search);

        $places = (clone $matching)
            ->selectRaw('work_item_id, max(created_at) as newest')
            ->groupBy('work_item_id')
            ->orderByRaw('max(created_at) desc')
            ->orderByRaw('work_item_id desc')
            ->get()
            ->pluck('work_item_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        $total = $places->count();
        $pageIds = $places->forPage($page, self::GROUPS_PER_PAGE)->values();
        $hits = $search === null ? null : (clone $matching)->count();
        $more = $total > $page * self::GROUPS_PER_PAGE;

        if ($pageIds->isEmpty()) {
            return ['groups' => [], 'total' => $total, 'more' => $more, 'hits' => $hits];
        }

        $all = (clone $matching)
            ->whereIn('work_item_id', $pageIds->all())
            ->with(self::WITH_USERS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $liked = self::likedIds($author, $all->pluck('id'));
        $comments = $all->groupBy('work_item_id');
        $newCounts = DeskPage::newReaderThreads()
            ->whereIn('work_item_id', $pageIds->all())
            ->selectRaw('work_item_id, count(*) as total')
            ->groupBy('work_item_id')
            ->pluck('total', 'work_item_id');

        $workIds = $all->pluck('work_id')->unique()->values()->all();
        $works = Work::query()->with(['cover', 'series'])->whereIn('id', $workIds)->get()->keyBy('id');
        $itemsByWork = self::itemsByWork($workIds);
        $groups = [];

        foreach ($pageIds as $itemId) {
            $top = $comments->get($itemId, collect())->values();

            if ($top->isEmpty()) {
                continue;
            }

            $workId = (int) $top->first()->work_id;
            $items = $itemsByWork->get($workId, collect());
            $item = $items->firstWhere('id', $itemId);
            $work = $works->get($workId);

            if ($item === null || $work === null) {
                continue;
            }

            $lead = $search === null
                ? $top->filter(fn (Comment $comment, int $index): bool => $index < self::LEAD || self::isNew($comment))->values()
                : $top;

            $groups[] = [
                'key' => (string) $itemId,
                'item' => $itemId,
                'work' => ['slug' => $work->slug, 'title' => $work->title, 'cover' => WorkPresenter::cover($work)],
                'title' => self::placeTitle($work, $item, $items),
                'sub' => self::placeSub($work, $item, $items),
                'new' => (int) ($newCounts[$itemId] ?? 0),
                'open' => self::readerUrl($work, $item, $items),
                'comments' => $lead->map(fn (Comment $comment): array => self::comment($comment, $liked))->values()->all(),
                'earlier' => $top->count() - $lead->count(),
            ];
        }

        return ['groups' => $groups, 'total' => $total, 'more' => $more, 'hits' => $hits];
    }

    /**
     * Every top-level reader comment on one chapter or piece, newest first, narrowed by a search.
     *
     * @return array{comments: list<array<string, mixed>>}
     */
    public static function itemComments(User $author, int $itemId, ?string $search): array
    {
        $comments = self::readerThreads(null, $search)
            ->where('work_item_id', $itemId)
            ->with(self::WITH_USERS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $liked = self::likedIds($author, $comments->pluck('id'));

        return ['comments' => $comments->map(fn (Comment $comment): array => self::comment($comment, $liked))->values()->all()];
    }

    /**
     * The reviewers' notes grouped by chapter or piece (book notes by work), open and done apart, newest place first.
     * Within a place, marks come in the order of the text, then the comments on the whole piece, newest first.
     *
     * @return array{open: list<array<string, mixed>>, done: list<array<string, mixed>>, hits: int|null}
     */
    public static function reviewerGroups(?string $slug, ?string $who, ?string $search): array
    {
        $notes = self::reviewNotes($slug, $who, $search)
            ->with(['user:id,name,username,avatar_seed', 'replies.user:id,name,username,avatar_seed,role', 'work.cover', 'work.series'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $itemsByWork = self::itemsByWork($notes->pluck('work_id')->unique()->values()->all());
        $bodies = WorkItem::query()
            ->whereIn('id', $notes->pluck('work_item_id')->filter()->unique()->values()->all())
            ->get(['id', 'draft_body'])
            ->pluck('draft_body', 'id');

        return [
            'open' => self::noteGroups($notes->filter(fn (Comment $note): bool => $note->status === CommentStatus::Open), $itemsByWork, $bodies),
            'done' => self::noteGroups($notes->reject(fn (Comment $note): bool => $note->status === CommentStatus::Open), $itemsByWork, $bodies),
            'hits' => $search === null ? null : $notes->count(),
        ];
    }

    /**
     * One reviewer note as the notes list draws it, with its place in the text and its replies.
     *
     * @return array<string, mixed>
     */
    public static function note(Comment $note): array
    {
        $note->loadMissing(['user:id,name,username,avatar_seed,role', 'replies.user:id,name,username,avatar_seed,role', 'work.cover', 'work.series', 'workItem']);

        return self::noteShape($note, $note->workItem?->draft_body, $note->work);
    }

    /**
     * The open reviewer notes on one chapter or piece, oldest first, as the writing screen marks them: each note as the
     * inbox draws it, with the block and character its words start at.
     *
     * @return list<array<string, mixed>>
     */
    public static function itemNotes(WorkItem $item): array
    {
        return self::fromOthers(Comment::query())
            ->where('audience', CommentAudience::Review)
            ->whereNull('parent_id')
            ->where('work_item_id', $item->id)
            ->where('status', CommentStatus::Open)
            ->oldest()
            ->oldest('id')
            ->get()
            ->map(fn (Comment $note): array => [
                ...self::note($note),
                'block' => $note->anchor['block_id'] ?? null,
                'start' => (int) ($note->anchor['start'] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * One reply as the inbox draws it.
     *
     * @return array{id: int, who: string, seed: string, me: bool, at: string, text: string}
     */
    public static function reply(Comment $reply): array
    {
        return [
            'id' => $reply->id,
            'who' => self::who($reply->user),
            'seed' => self::seed($reply->user),
            'me' => $reply->user?->isAuthor() ?? false,
            'at' => ReaderPage::ago($reply->created_at),
            'text' => (string) $reply->body,
        ];
    }

    /**
     * Top-level public comments from anyone but the writer, hidden ones included, narrowed to a work and a search.
     *
     * @return Builder<Comment>
     */
    private static function readerThreads(?string $slug, ?string $search): Builder
    {
        return self::fromOthers(Comment::query()
            ->where('audience', CommentAudience::Public)
            ->whereNull('parent_id')
            ->whereNotNull('work_item_id'))
            ->when($slug !== null, fn (Builder $query): Builder => $query->whereHas('work', fn (Builder $work) => $work->where('slug', $slug)))
            ->when($search !== null, fn (Builder $query): Builder => self::matching($query, (string) $search));
    }

    /**
     * Top-level reviewer notes from anyone but the writer, narrowed to a work, a reviewer by exact name, and a search.
     *
     * @return Builder<Comment>
     */
    private static function reviewNotes(?string $slug, ?string $who, ?string $search): Builder
    {
        return self::fromOthers(Comment::query()
            ->where('audience', CommentAudience::Review)
            ->whereNull('parent_id')
            ->whereNotNull('user_id'))
            ->when($slug !== null, fn (Builder $query): Builder => $query->whereHas('work', fn (Builder $work) => $work->where('slug', $slug)))
            ->when($who !== null, fn (Builder $query): Builder => $query->whereHas('user', fn (Builder $user) => $user->where('name', $who)))
            ->when($search !== null, fn (Builder $query): Builder => self::matching($query, (string) $search));
    }

    /**
     * Keeps the comments whose text, quote, writer, replies, work or chapter title has the search in it, ignoring case.
     *
     * @param  Builder<Comment>  $query
     * @return Builder<Comment>
     */
    private static function matching(Builder $query, string $search): Builder
    {
        $like = '%'.addcslashes($search, '%_\\').'%';

        return $query->where(fn (Builder $match) => $match
            ->where('body', 'ilike', $like)
            ->orWhereRaw("anchor->>'quote' ilike ?", [$like])
            ->orWhereHas('user', fn (Builder $user) => $user->where('username', 'ilike', $like)->orWhere('name', 'ilike', $like))
            ->orWhereHas('replies', fn (Builder $reply) => $reply->where('body', 'ilike', $like))
            ->orWhereHas('work', fn (Builder $work) => $work->where('title', 'ilike', $like))
            ->orWhereHas('workItem', fn (Builder $item) => $item->where('title', 'ilike', $like)));
    }

    /**
     * Narrows a comments query to what anyone but the writer wrote (or what no account wrote any more).
     *
     * @param  Builder<Comment>  $query
     * @return Builder<Comment>
     */
    private static function fromOthers(Builder $query): Builder
    {
        return $query->where(fn (Builder $others) => $others
            ->whereNull('user_id')
            ->orWhereNotIn('user_id', User::query()->select('id')->where('role', UserRole::Author)));
    }

    /**
     * Works for one tab: those with a member, with how many of their members are unseen.
     *
     * @param  Builder<Comment>  $members
     * @param  Builder<Comment>  $unseen
     * @return list<array{slug: string, title: string, new: int}>
     */
    private static function workOptions(Builder $members, Builder $unseen): array
    {
        $new = (clone $unseen)->selectRaw('work_id, count(*) as total')->groupBy('work_id')->pluck('total', 'work_id');

        return Work::query()
            ->whereIn('id', (clone $members)->select('work_id'))
            ->get(['id', 'slug', 'title'])
            ->map(fn (Work $work): array => ['slug' => $work->slug, 'title' => $work->title, 'new' => (int) ($new[$work->id] ?? 0)])
            ->sort(fn (array $a, array $b): int => [-$a['new'], mb_strtolower($a['title'])] <=> [-$b['new'], mb_strtolower($b['title'])])
            ->values()
            ->all();
    }

    /**
     * Each reviewer place: its notes in the order of the text, the place's title and address.
     *
     * @param  Collection<int, Comment>  $notes
     * @param  Collection<int|string, Collection<int, WorkItem>>  $itemsByWork
     * @param  Collection<int|string, array<string, mixed>>  $bodies
     * @return list<array<string, mixed>>
     */
    private static function noteGroups(Collection $notes, Collection $itemsByWork, Collection $bodies): array
    {
        $groups = $notes
            ->groupBy(fn (Comment $note): string => $note->work_item_id !== null ? (string) $note->work_item_id : 'w'.$note->work_id)
            ->map(function (Collection $group) use ($itemsByWork, $bodies): array {
                /** @var Comment $first */
                $first = $group->first();
                $work = $first->work;
                $items = $itemsByWork->get($work->id, collect());
                $item = $first->work_item_id === null ? null : $items->firstWhere('id', $first->work_item_id);
                $doc = $first->work_item_id === null ? null : $bodies->get($first->work_item_id);

                return [
                    'key' => $first->work_item_id !== null ? (string) $first->work_item_id : 'w'.$work->id,
                    'item' => $item?->id,
                    'work' => ['slug' => $work->slug, 'title' => $work->title, 'cover' => WorkPresenter::cover($work)],
                    'title' => self::placeTitle($work, $item, $items),
                    'sub' => self::placeSub($work, $item, $items),
                    'new' => $group->filter(fn (Comment $note): bool => $note->seen_at === null)->count(),
                    'open' => self::writeUrl($work, $item, $items),
                    'notes' => self::inDocumentOrder($group, $doc)
                        ->map(fn (Comment $note): array => self::noteShape($note, $doc, $work))
                        ->values()
                        ->all(),
                    'newest' => max(array_map(fn (Comment $note): int => $note->created_at->getTimestamp(), $group->all())),
                ];
            })
            ->values()
            ->all();

        usort($groups, fn (array $a, array $b): int => $b['newest'] <=> $a['newest']);

        return array_map(function (array $group): array {
            unset($group['newest']);

            return $group;
        }, $groups);
    }

    /**
     * Line and edit marks in the order they sit in the text, then the comments on the whole piece, newest first.
     *
     * @param  Collection<int, Comment>  $group
     * @param  array<string, mixed>|null  $doc
     * @return Collection<int, Comment>
     */
    private static function inDocumentOrder(Collection $group, ?array $doc): Collection
    {
        $notes = $group->all();

        usort($notes, function (Comment $a, Comment $b) use ($doc): int {
            $rankA = self::rank($a);
            $rankB = self::rank($b);

            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            if ($rankA === 0) {
                return [self::blockIndex($doc, $a->anchor['block_id'] ?? null), (int) ($a->anchor['start'] ?? 0)]
                    <=> [self::blockIndex($doc, $b->anchor['block_id'] ?? null), (int) ($b->anchor['start'] ?? 0)];
            }

            return [$b->created_at->getTimestamp(), $b->id] <=> [$a->created_at->getTimestamp(), $a->id];
        });

        return collect($notes);
    }

    private static function rank(Comment $note): int
    {
        return match ($note->kind) {
            CommentKind::Line, CommentKind::Edit => 0,
            CommentKind::Comment => 1,
            default => 2,
        };
    }

    /**
     * @param  array<string, mixed>|null  $doc
     */
    private static function blockIndex(?array $doc, mixed $blockId): int
    {
        if ($blockId === null) {
            return PHP_INT_MAX;
        }

        foreach ($doc['content'] ?? [] as $index => $block) {
            if ((string) ($block['attrs']['id'] ?? '') === (string) $blockId) {
                return $index;
            }
        }

        return PHP_INT_MAX;
    }

    /**
     * One reader comment as the inbox draws it: its writer, when, the likes, and its replies oldest first.
     *
     * @param  array<int, true>  $liked  the comment ids the writer has liked
     * @return array<string, mixed>
     */
    private static function comment(Comment $comment, array $liked): array
    {
        return [
            'id' => $comment->id,
            'who' => self::who($comment->user),
            'seed' => self::seed($comment->user),
            'at' => ReaderPage::ago($comment->created_at),
            'text' => (string) $comment->body,
            'likes' => (int) $comment->likes_count,
            'liked' => isset($liked[$comment->id]),
            'hidden' => $comment->hidden_at !== null,
            'new' => self::isNew($comment),
            'replies' => $comment->replies->map(fn (Comment $reply): array => self::reply($reply))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function noteShape(Comment $note, ?array $doc, Work $work): array
    {
        $context = self::context($note, $doc);
        $isEdit = $note->kind === CommentKind::Edit;

        return [
            'id' => $note->id,
            'who' => $note->user?->name ?: 'Someone',
            'seed' => $note->user?->avatar_seed ?: ($note->user?->name ?: 'reviewer'),
            'at' => ReaderPage::ago($note->created_at),
            'kind' => $note->kind->value,
            'scope' => match ($note->kind) {
                CommentKind::Comment => 'On the whole '.self::unitWord($work),
                CommentKind::Book => 'On the whole work',
                default => null,
            },
            'pre' => $context['pre'],
            'quote' => $context['quote'],
            'post' => $context['post'],
            'del' => $isEdit ? (string) ($note->suggestion['deleted'] ?? '') : '',
            'ins' => $isEdit ? (string) ($note->suggestion['inserted'] ?? '') : '',
            'text' => (string) $note->body,
            'status' => $note->status->value,
            'new' => $note->seen_at === null,
            'replies' => $note->replies->map(fn (Comment $reply): array => self::reply($reply))->values()->all(),
        ];
    }

    /**
     * The quote a mark sits on, with the rest of its sentence before and after it. A mark whose text is gone shows only the quote.
     *
     * @param  array<string, mixed>|null  $doc
     * @return array{pre: string, quote: string, post: string}
     */
    private static function context(Comment $note, ?array $doc): array
    {
        $anchor = $note->anchor ?? [];
        $quote = (string) ($anchor['quote'] ?? '');

        /* an accepted edit is quoted as the line now reads */
        if ($note->kind === CommentKind::Edit && $note->status === CommentStatus::Accepted && isset($note->suggestion['inserted'])) {
            $quote = (string) $note->suggestion['inserted'];
        }

        $bare = ['pre' => '', 'quote' => $quote, 'post' => ''];
        $block = $doc === null ? null : self::block($doc, (string) ($anchor['block_id'] ?? ''));

        if ($quote === '' || $block === null) {
            return $bare;
        }

        $text = Prose::plain($block);
        $at = ReviewEdit::locate($text, $quote, (int) ($anchor['start'] ?? 0));

        if ($at === null) {
            return $bare;
        }

        return [
            'pre' => self::sentenceStart(mb_substr($text, 0, $at)),
            'quote' => $quote,
            'post' => self::sentenceEnd(mb_substr($text, $at + mb_strlen($quote))),
        ];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>|null
     */
    private static function block(array $doc, string $blockId): ?array
    {
        if ($blockId === '') {
            return null;
        }

        foreach ($doc['content'] ?? [] as $block) {
            if ((string) ($block['attrs']['id'] ?? '') === $blockId) {
                return $block;
            }
        }

        return null;
    }

    /**
     * The text before a quote from the sentence's start: after the last full stop, question or exclamation mark before it.
     */
    private static function sentenceStart(string $before): string
    {
        if (preg_match('/^.*[.!?]/su', $before, $match) === 1) {
            return ltrim(mb_substr($before, mb_strlen($match[0])));
        }

        return ltrim($before);
    }

    /**
     * The text after a quote up to and including the next full stop, question or exclamation mark, or to the end.
     */
    private static function sentenceEnd(string $after): string
    {
        return preg_match('/^.*?[.!?]/su', $after, $match) === 1 ? $match[0] : $after;
    }

    /**
     * The itemised chapters and pieces of the given works, in reading order, keyed by work id.
     *
     * @param  list<int|string>  $workIds
     * @return Collection<int|string, Collection<int, WorkItem>>
     */
    private static function itemsByWork(array $workIds): Collection
    {
        return WorkItem::query()
            ->whereIn('work_id', $workIds)
            ->orderBy('position')
            ->get(self::ITEM_COLUMNS)
            ->groupBy('work_id');
    }

    /**
     * The people who liked a set of comments, as the writer's likes.
     *
     * @return array<int, true>
     */
    private static function likedIds(User $author, Collection $commentIds): array
    {
        return Like::query()
            ->where('user_id', $author->id)
            ->where('likeable_type', (new Comment)->getMorphClass())
            ->whereIn('likeable_id', $commentIds->all())
            ->pluck('likeable_id')
            ->mapWithKeys(fn (mixed $id): array => [(int) $id => true])
            ->all();
    }

    private static function isNew(Comment $comment): bool
    {
        if ($comment->hidden_at !== null) {
            return false;
        }

        return $comment->seen_at === null || $comment->replies->contains(
            fn (Comment $reply): bool => $reply->seen_at === null && $reply->hidden_at === null && $reply->user?->role !== UserRole::Author,
        );
    }

    private static function who(?User $user): string
    {
        return $user?->username ?: ($user?->name ?: 'Someone');
    }

    private static function seed(?User $user): string
    {
        return $user?->avatar_seed ?: ($user?->username ?: 'reader');
    }

    /**
     * The place a comment or note is on, as its title: the chapter or piece's own title, or its number, or the work's title.
     */
    private static function placeTitle(Work $work, ?WorkItem $item, Collection $items): string
    {
        if ($item === null || $work->kind->isSingle()) {
            return $work->title;
        }

        if (filled($item->title)) {
            return $item->title;
        }

        $unit = DeskPage::unitNumber($work, $item, $items);

        return $unit === null ? $work->title : ucfirst($unit);
    }

    /**
     * The line under a place's title: the work, and the chapter or piece's number when it has its own title.
     */
    private static function placeSub(Work $work, ?WorkItem $item, Collection $items): string
    {
        if ($item === null || $work->kind->isSingle()) {
            return self::kindLabel($work);
        }

        if (blank($item->title)) {
            return $work->title;
        }

        $unit = DeskPage::unitNumber($work, $item, $items);

        return $unit === null ? $work->title : $work->title.' · '.ucfirst($unit);
    }

    /**
     * "Novel", "Short story", "Poem", "Collection": the work's kind, as WorksAdmin names it.
     */
    private static function kindLabel(Work $work): string
    {
        return explode(' · ', WorksAdmin::sub($work))[0];
    }

    /**
     * The word a chapter or piece goes by in "On the whole chapter": a poem, a story, an essay, or a chapter for a novel.
     */
    private static function unitWord(Work $work): string
    {
        return $work->kind->isSingle() ? $work->kind->value : strtolower(WorkPresenter::unit($work));
    }

    /**
     * The public address a reader comment's place opens at: the chapter's number as readers see it, or the work's page.
     */
    private static function readerUrl(Work $work, ?WorkItem $item, Collection $items): string
    {
        $base = rtrim((string) config('app.url'), '/').'/library/'.$work->slug;

        if ($item === null || $work->kind->isSingle()) {
            return $base.'#comments';
        }

        return $base.'/'.self::readerNumber($item, $items).'#comments';
    }

    /**
     * The chapter's number among the released chapters and pieces, which is the number its reading page has.
     */
    private static function readerNumber(WorkItem $item, Collection $items): int
    {
        $released = $items
            ->filter(fn (WorkItem $sibling): bool => in_array($sibling->type, [WorkItemType::Chapter, WorkItemType::Piece], true)
                && in_array($sibling->status, [PublishStatus::Published, PublishStatus::Unpublished], true))
            ->values();

        $index = $released->search(fn (WorkItem $sibling): bool => (int) $sibling->id === (int) $item->id);

        return $index === false ? 1 : $index + 1;
    }

    /**
     * The address a reviewer note's place opens at in the writing screen; a book note opens at its first chapter or piece.
     */
    private static function writeUrl(Work $work, ?WorkItem $item, Collection $items): string
    {
        if ($item !== null) {
            return '/works/'.$work->slug.'/write/'.$item->id;
        }

        $first = $items->first(fn (WorkItem $candidate): bool => in_array($candidate->type, [WorkItemType::Chapter, WorkItemType::Piece], true));

        return $first === null ? WorksAdmin::writeHref($work, null) : '/works/'.$work->slug.'/write/'.$first->id;
    }
}
