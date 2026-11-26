<?php

namespace App\Support;

use App\Enums\CommentAudience;
use App\Enums\Visibility;
use App\Enums\WorkKind;
use App\Enums\WorkStatus;
use App\Models\Comment;
use App\Models\Follow;
use App\Models\Like;
use App\Models\Note;
use App\Models\ReadingProgress;
use App\Models\Series;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Your Profile (only you see it): your place in what you're reading, what you follow, what you liked and
 * what you said. The full lists use the same rows.
 */
class ProfilePage
{
    /** How many rows each full list holds; "Show more" reveals them a page at a time. */
    public const LIST_LIMIT = 500;

    /** @var Collection<int, Work>|null */
    private ?Collection $works = null;

    /** @var array<int, array{number: int, item: WorkItem, work: Work}>|null */
    private ?array $itemIndex = null;

    public function __construct(private User $user) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $following = $this->following(4);
        $likes = $this->likes(3);
        $comments = $this->comments(2);

        return [
            'me' => $this->me(),
            'shared' => $this->shared(),
            'place' => $this->place(),
            'following' => ['count' => $this->followCount(), 'rows' => $following],
            'likes' => ['count' => $this->likeCount(), 'groups' => $this->group($likes)],
            'comments' => ['count' => $this->commentCount(), 'rows' => $comments],
        ];
    }

    /**
     * One full list: following, likes or comments.
     *
     * @return array<string, mixed>
     */
    public function list(string $which): array
    {
        return match ($which) {
            'following' => ['count' => $this->followCount(), 'rows' => $this->following(self::LIST_LIMIT)],
            'likes' => ['count' => $this->likeCount(), 'groups' => $this->group($this->likes(self::LIST_LIMIT))],
            default => ['count' => $this->commentCount(), 'rows' => $this->comments(self::LIST_LIMIT)],
        };
    }

    /**
     * @return array{name: string, username: string, seed: string, joined: string}
     */
    private function me(): array
    {
        $joined = $this->user->created_at->copy()->setTimezone(config('eyiwetemi.timezone'));

        return [
            'name' => $this->user->name,
            'username' => $this->user->username,
            'seed' => $this->user->avatar_seed,
            'joined' => $joined->year === now(config('eyiwetemi.timezone'))->year ? $joined->format('F') : $joined->format('F Y'),
        ];
    }

    /**
     * Your place: the novel you read last, where you are in it, and up to three others you're partway through.
     *
     * @return array<string, mixed>|null
     */
    private function place(): ?array
    {
        $progress = ReadingProgress::query()
            ->where('user_id', $this->user->id)
            ->latest('updated_at')
            ->limit(40)
            ->get(['id', 'work_item_id', 'percent', 'finished_at', 'updated_at']);

        $seen = [];
        $places = [];
        foreach ($progress as $row) {
            $found = $this->itemIndex()[$row->work_item_id] ?? null;
            if (! $found || isset($seen[$found['work']->id])) {
                continue;
            }
            $seen[$found['work']->id] = true;
            $percent = (int) $row->percent;

            /* a finished chapter: your place is the start of the next one; caught up, it isn't a place to go back to */
            if ($row->finished_at !== null || $percent >= 100) {
                $next = WorkPresenter::numbered($found['work'])->firstWhere('number', $found['number'] + 1);
                if (! $next) {
                    continue;
                }
                $found = ['number' => $next['number'], 'item' => $next['item'], 'work' => $found['work']];
                $percent = 0;
            }
            $places[] = ['percent' => $percent, ...$found];
        }

        $lead = collect($places)->first(fn (array $p): bool => $p['work']->kind === WorkKind::Novel);
        if (! $lead) {
            return null;
        }

        $work = $lead['work'];
        $item = $lead['item'];
        $percent = $lead['percent'];
        $left = (int) ceil(Reading::minutes((int) $item->live_words) * (100 - $percent) / 100);

        return [
            'work' => ['title' => $work->title, 'href' => WorkPresenter::href($work), 'cover' => WorkPresenter::cover($work), 'kindLabel' => WorkPresenter::kindLabel($work), 'kind' => $work->kind->value, 'isCollection' => false],
            'label' => ucfirst(WorkPresenter::unit($work)).' '.$lead['number'],
            'title' => $item->title,
            'href' => WorkPresenter::itemHref($work, $lead['number']),
            'percent' => $percent,
            'left' => $left > 0 ? $left.' min left in this '.WorkPresenter::unit($work) : 'At the end of this '.WorkPresenter::unit($work),
            'also' => collect($places)
                ->reject(fn (array $p): bool => $p['work']->id === $work->id)
                ->take(3)
                ->map(fn (array $p): array => [
                    ...$this->picture($p['work']),
                    'label' => $p['work']->kind === WorkKind::Novel || $p['work']->kind === WorkKind::Collection
                        ? ucfirst(WorkPresenter::unit($p['work'])).' '.$p['number']
                        : WorkPresenter::kindLabel($p['work']),
                    'href' => $this->itemLink($p['work'], $p['number']),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Shared with me (reviewers): works the author shared with you, newest first, with your marks still open.
     *
     * @return list<array<string, mixed>>
     */
    private function shared(): array
    {
        $works = $this->user->reviewableWorks()
            ->with(WorkPresenter::with())
            ->orderByPivot('created_at', 'desc')
            ->get();

        if ($works->isEmpty()) {
            return [];
        }

        $open = Comment::query()
            ->where('user_id', $this->user->id)
            ->where('audience', CommentAudience::Review)
            ->whereNull('parent_id')
            ->whereNull('resolved_at')
            ->whereIn('work_id', $works->pluck('id'))
            ->selectRaw('work_id, count(*) as open')
            ->groupBy('work_id')
            ->pluck('open', 'work_id');

        return $works->map(fn (Work $work): array => [
            'id' => $work->id,
            ...$this->picture($work),
            'private' => $work->visibility !== Visibility::Public,
            'shared' => 'Shared '.Reading::day($work->pivot->created_at),
            'open' => (int) ($open[$work->id] ?? 0),
        ])->values()->all();
    }

    /**
     * What you follow, newest first: works and series, each with where you are or what's new.
     *
     * @return list<array<string, mixed>>
     */
    private function following(int $take): array
    {
        $follows = Follow::query()
            ->where('user_id', $this->user->id)
            ->latest('id')
            ->limit($take)
            ->get(['id', 'followable_type', 'followable_id']);

        $series = Series::query()
            ->whereIn('id', $follows->where('followable_type', 'series')->pluck('followable_id'))
            ->get(['id', 'name', 'slug'])
            ->keyBy('id');

        return $follows->map(function (Follow $follow) use ($series): ?array {
            if ($follow->followable_type === 'series') {
                $one = $series->get($follow->followable_id);
                $books = $this->works()->where('series_id', $follow->followable_id)->sortBy('series_position')->values();

                return $one ? [
                    'type' => 'series',
                    'id' => $one->id,
                    'title' => $one->name,
                    'href' => '/series/'.$one->slug,
                    'picture' => $books->first() ? $this->picture($books->first())['picture'] : ['src' => null, 'color' => '#C46F8A'],
                    'status' => ['icon' => 'layers', 'text' => $books->count().' '.($books->count() === 1 ? 'book' : 'books')],
                    'extra' => null,
                ] : null;
            }

            $work = $this->works()->firstWhere('id', $follow->followable_id);

            return $work ? [
                'type' => 'work',
                'id' => $work->id,
                ...$this->picture($work),
                'status' => $this->followStatus($work),
                'extra' => $this->followExtra($work),
            ] : null;
        })->filter()->values()->all();
    }

    /**
     * "8 chapters so far", "Finished · in print", "Coming soon".
     *
     * @return array{icon: string|null, text: string}
     */
    private function followStatus(Work $work): array
    {
        $count = WorkPresenter::numbered($work)->count();

        return match (true) {
            $work->status === WorkStatus::ComingSoon => ['icon' => 'clock', 'text' => 'Coming soon'],
            $work->kind !== WorkKind::Novel && $work->kind !== WorkKind::Collection => WorkPresenter::status($work),
            $work->status === WorkStatus::Finished => ['icon' => 'check', 'text' => 'Finished · '.(InPrint::fromPrice($work->slug) !== null ? 'in print' : WorkPresenter::units($work, $count))],
            default => ['icon' => 'pen', 'text' => WorkPresenter::units($work, $count).' so far'],
        };
    }

    /**
     * A new chapter you haven't reached (out in the last two weeks), or where you are.
     *
     * @return array{label: string, href: string, pill: string}|null
     */
    private function followExtra(Work $work): ?array
    {
        $numbered = WorkPresenter::numbered($work);
        $newest = $numbered->last();
        $unit = ucfirst(WorkPresenter::unit($work));
        $mine = ReadingProgress::query()
            ->where('user_id', $this->user->id)
            ->whereIn('work_item_id', $numbered->map(fn (array $row): int => $row['item']->id))
            ->latest('updated_at')
            ->value('work_item_id');
        $here = $mine ? $numbered->first(fn (array $row): bool => $row['item']->id === $mine) : null;

        if ($newest && $newest['item']->published_at->gte(now()->subDays(14)) && (! $here || $here['number'] < $newest['number'])) {
            return ['label' => $unit.' '.$newest['number'], 'href' => WorkPresenter::itemHref($work, $newest['number']), 'pill' => 'new'];
        }

        return $here ? ['label' => $unit.' '.$here['number'], 'href' => WorkPresenter::itemHref($work, $here['number']), 'pill' => 'you’re here'] : null;
    }

    /**
     * Chapters, pieces and notes you liked, newest first (comments you liked aren't listed).
     *
     * @return list<array<string, mixed>>
     */
    private function likes(int $take): array
    {
        $likes = Like::query()
            ->where('user_id', $this->user->id)
            ->whereIn('likeable_type', ['work_item', 'note', 'work'])
            ->latest('id')
            ->limit($take)
            ->get(['id', 'likeable_type', 'likeable_id', 'created_at']);

        $notes = Note::query()
            ->whereIn('id', $likes->where('likeable_type', 'note')->pluck('likeable_id'))
            ->get(['id', 'live_text', 'published_at', 'work_id'])
            ->keyBy('id');

        return $likes->map(function (Like $like) use ($notes): ?array {
            $row = match ($like->likeable_type) {
                'note' => $this->likedNote($notes->get($like->likeable_id)),
                'work' => $this->likedWork($this->works()->firstWhere('id', $like->likeable_id)),
                default => $this->likedItem($like->likeable_id),
            };

            return $row ? [
                'type' => $like->likeable_type,
                'id' => $like->likeable_id,
                ...$row,
                'when' => $this->when($like->created_at),
                'at' => $like->created_at,
            ] : null;
        })->filter()->values()->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function likedItem(int $itemId): ?array
    {
        $found = $this->itemIndex()[$itemId] ?? null;
        if (! $found) {
            return null;
        }
        $work = $found['work'];

        if ($work->kind !== WorkKind::Novel && $work->kind !== WorkKind::Collection) {
            return $this->likedWork($work);
        }

        return [
            'picture' => $this->picture($work)['picture'],
            'label' => ucfirst(WorkPresenter::unit($work)).' '.$found['number'].($found['item']->title ? ' · '.$found['item']->title : ''),
            'href' => WorkPresenter::itemHref($work, $found['number']),
            'thumb' => $work->title,
            'small' => $work->title,
            'quote' => false,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function likedWork(?Work $work): ?array
    {
        return $work ? [
            'picture' => $this->picture($work)['picture'],
            'label' => $work->title,
            'href' => WorkPresenter::href($work),
            'thumb' => $work->title,
            'small' => WorkPresenter::kindLabel($work),
            'quote' => false,
        ] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function likedNote(?Note $note): ?array
    {
        if (! $note || $note->published_at === null) {
            return null;
        }
        $work = $note->work_id ? $this->works()->firstWhere('id', $note->work_id) : null;

        return [
            'picture' => $work ? $this->picture($work)['picture'] : null,
            'label' => 'Note · '.Reading::day($note->published_at),
            'href' => '/notes#n-'.$note->id,
            'thumb' => $work?->title ?? 'Note',
            'small' => '“'.Reading::firstSentence($note->live_text).'”',
            'quote' => true,
        ];
    }

    /**
     * Likes under "This week", then by month.
     *
     * @param  list<array<string, mixed>>  $likes
     * @return list<array{label: string, rows: list<array<string, mixed>>}>
     */
    private function group(array $likes): array
    {
        $zone = config('eyiwetemi.timezone');
        $weekAgo = now()->subDays(7);

        return collect($likes)
            ->groupBy(function (array $like) use ($zone, $weekAgo): string {
                /** @var CarbonInterface $at */
                $at = $like['at'];
                if ($at->gte($weekAgo)) {
                    return 'This week';
                }
                $local = $at->copy()->setTimezone($zone);

                return $local->year === now($zone)->year ? $local->format('F') : $local->format('F Y');
            })
            ->map(fn (Collection $rows, string $label): array => [
                'label' => $label,
                'rows' => $rows->map(fn (array $row): array => collect($row)->except('at')->all())->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Your comments, newest first, each with its likes, replies and the latest reply.
     *
     * @return list<array<string, mixed>>
     */
    private function comments(int $take): array
    {
        $comments = Comment::query()
            ->where('user_id', $this->user->id)
            ->where('audience', CommentAudience::Public)
            ->whereNull('hidden_at')
            ->latest('id')
            ->limit($take)
            ->withCount(['replies' => fn ($query) => $query->whereNull('hidden_at')])
            ->get(['id', 'work_id', 'work_item_id', 'parent_id', 'body', 'likes_count', 'created_at']);

        $latest = Comment::query()
            ->whereIn('parent_id', $comments->pluck('id'))
            ->whereNull('hidden_at')
            ->with(['user:id,name,username,avatar_seed,role', 'replyToUser:id,username'])
            ->orderBy('id')
            ->get(['id', 'parent_id', 'user_id', 'reply_to_user_id', 'body', 'created_at'])
            ->groupBy('parent_id')
            ->map(fn (Collection $replies): Comment => $replies->last());

        $photo = ReaderPage::authorPhoto();

        return $comments->map(function (Comment $comment) use ($latest, $photo): ?array {
            $work = $this->works()->firstWhere('id', $comment->work_id);
            if (! $work) {
                return null;
            }
            $found = $comment->work_item_id ? ($this->itemIndex()[$comment->work_item_id] ?? null) : null;
            $isNovelLike = $work->kind === WorkKind::Novel || $work->kind === WorkKind::Collection;
            $reply = $latest->get($comment->id);

            return [
                'id' => $comment->id,
                'picture' => $this->picture($work)['picture'],
                'thumb' => $work->title,
                'where' => $found && $isNovelLike ? ucfirst(WorkPresenter::unit($work)).' '.$found['number'].' · '.$work->title : $work->title,
                'href' => ($found && $isNovelLike ? WorkPresenter::itemHref($work, $found['number']) : WorkPresenter::href($work)).'#comments',
                'when' => $this->when($comment->created_at),
                'text' => (string) $comment->body,
                'likes' => (int) $comment->likes_count,
                'replies' => (int) $comment->replies_count,
                'reply' => $reply && $reply->user ? [
                    'name' => $reply->user->isAuthor() ? 'Georgina' : explode(' ', $reply->user->name)[0],
                    'author' => $reply->user->isAuthor(),
                    'photo' => $reply->user->isAuthor() ? $photo : null,
                    'seed' => $reply->user->avatar_seed,
                    'tag' => $reply->reply_to_user_id === $this->user->id ? '@'.$this->user->username : null,
                    'text' => (string) $reply->body,
                ] : null,
            ];
        })->filter()->values()->all();
    }

    private function followCount(): int
    {
        return Follow::query()->where('user_id', $this->user->id)->count();
    }

    private function likeCount(): int
    {
        return Like::query()->where('user_id', $this->user->id)->whereIn('likeable_type', ['work_item', 'note', 'work'])->count();
    }

    private function commentCount(): int
    {
        return Comment::query()->where('user_id', $this->user->id)->where('audience', CommentAudience::Public)->whereNull('hidden_at')->count();
    }

    /**
     * "2 h" today, otherwise the day ("19 Nov").
     */
    private function when(CarbonInterface $at): string
    {
        $minutes = (int) $at->diffInMinutes(now());

        return match (true) {
            $minutes < 60 => max(1, $minutes).' min',
            $minutes < 24 * 60 => intdiv($minutes, 60).' h',
            default => (string) Reading::day($at),
        };
    }

    /**
     * @return array{title: string, href: string, picture: array{src: string|null, color: string, dark: bool}}
     */
    private function picture(Work $work): array
    {
        return ['title' => $work->title, 'href' => WorkPresenter::href($work), 'picture' => WorkPresenter::cover($work)];
    }

    private function itemLink(Work $work, int $number): string
    {
        return $work->kind === WorkKind::Novel || $work->kind === WorkKind::Collection
            ? WorkPresenter::itemHref($work, $number)
            : WorkPresenter::href($work);
    }

    /**
     * Every work a reader can open, with what the presenter needs.
     *
     * @return Collection<int, Work>
     */
    private function works(): Collection
    {
        return $this->works ??= WorkPresenter::query()->get();
    }

    /**
     * Released chapters and pieces by id, with their number and work.
     *
     * @return array<int, array{number: int, item: WorkItem, work: Work}>
     */
    private function itemIndex(): array
    {
        if ($this->itemIndex === null) {
            $this->itemIndex = [];
            foreach ($this->works() as $work) {
                foreach (WorkPresenter::numbered($work) as $row) {
                    $this->itemIndex[$row['item']->id] = ['number' => $row['number'], 'item' => $row['item'], 'work' => $work];
                }
            }
        }

        return $this->itemIndex;
    }
}
