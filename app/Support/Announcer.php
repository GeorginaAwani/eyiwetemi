<?php

namespace App\Support;

use App\Enums\CommentAudience;
use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Enums\WorkItemType;
use App\Models\Comment;
use App\Models\Follow;
use App\Models\Note;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use App\Notifications\CommentReply;
use App\Notifications\NewChapter;
use App\Notifications\NewNote;
use App\Notifications\NewWork;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Tells readers what just came out. Works out who hears about each release from follows, the "new work"
 * switch and reply targets, then sends it. Authors never get notices, and nobody is told about their own action.
 */
class Announcer
{
    /**
     * Sends everything released in (from, to] and returns how many notices went out.
     */
    public function announce(CarbonInterface $from, CarbonInterface $to, bool $mail = true): int
    {
        $sent = 0;

        foreach ($this->releases($from, $to, $mail) as $delivery) {
            Notification::send($delivery['users'], $delivery['notification']);
            $sent += $delivery['users']->count();
        }

        return $sent;
    }

    /**
     * Tells the reader whose comment was answered that there is a reply.
     */
    public function reply(Comment $reply): void
    {
        foreach ($this->replyDeliveries($reply) as $delivery) {
            Notification::send($delivery['users'], $delivery['notification']);
        }
    }

    /**
     * Everything released in (from, to], as deliveries: the notice, who gets it, and when it came out.
     *
     * @return Collection<int, array{notification: \Illuminate\Notifications\Notification, users: Collection<int, User>, at: CarbonInterface}>
     */
    public function releases(CarbonInterface $from, CarbonInterface $to, bool $mail = true): Collection
    {
        $items = WorkItem::query()
            ->whereIn('type', [WorkItemType::Chapter, WorkItemType::Piece])
            ->where('status', PublishStatus::Published)
            ->where('published_at', '>', $from)
            ->where('published_at', '<=', $to)
            ->get(['id', 'work_id', 'published_at']);

        $notes = Note::query()
            ->where('status', PublishStatus::Published)
            ->where('notify_followers', true)
            ->whereNotNull('work_id')
            ->where('published_at', '>', $from)
            ->where('published_at', '<=', $to)
            ->get(['id', 'work_id', 'published_at']);

        $firstReleasedWorkIds = Work::query()
            ->where('first_published_at', '>', $from)
            ->where('first_published_at', '<=', $to)
            ->pluck('id');

        $workIds = $items->pluck('work_id')->merge($notes->pluck('work_id'))->merge($firstReleasedWorkIds)->unique()->values();
        $works = WorkPresenter::query()->whereIn('id', $workIds)->get()->keyBy('id');
        $followersByWork = $works->mapWithKeys(fn (Work $work): array => [$work->id => $this->followersOf($work)]);

        $deliveries = collect();
        $newReaders = null;

        foreach ($works as $work) {
            $followers = $followersByWork[$work->id];
            $released = WorkPresenter::numbered($work)
                ->filter(fn (array $row): bool => $this->inWindow($row['item']->published_at, $from, $to))
                ->values();
            $firstReleaseItem = $released->firstWhere('number', 1);
            $workFirstReleasedInWindow = $this->inWindow($work->first_published_at, $from, $to);

            if ($firstReleaseItem !== null || $workFirstReleasedInWindow) {
                $firstAt = $workFirstReleasedInWindow ? $work->first_published_at : $firstReleaseItem['item']->published_at;
                $newReaders ??= $this->newReaders($mail);

                $deliveries->push([
                    'notification' => new NewWork($work->id, 'chapters', $mail),
                    'users' => $this->reaching($followers, 'chapters', $mail),
                    'at' => $firstAt,
                ]);
                $deliveries->push([
                    'notification' => new NewWork($work->id, 'new', $mail),
                    'users' => $newReaders->whereNotIn('id', $followers->modelKeys())->values(),
                    'at' => $firstAt,
                ]);
            }

            foreach ($released as $row) {
                if ($row['number'] === 1) {
                    continue;
                }

                $deliveries->push([
                    'notification' => new NewChapter($row['item']->id, 'chapters', $mail),
                    'users' => $this->reaching($followers, 'chapters', $mail),
                    'at' => $row['item']->published_at,
                ]);
            }
        }

        foreach ($notes as $note) {
            if (! $works->has($note->work_id)) {
                continue;
            }

            $deliveries->push([
                'notification' => new NewNote($note->id, 'notes', $mail),
                'users' => $this->reaching($followersByWork[$note->work_id], 'notes', $mail),
                'at' => $note->published_at,
            ]);
        }

        return $deliveries->filter(fn (array $delivery): bool => $delivery['users']->isNotEmpty())->values();
    }

    /**
     * The reply notice for one comment, if it should go out: a public, visible reply to a visible comment
     * whose author is a reader or reviewer and not the person replying.
     *
     * @return Collection<int, array{notification: \Illuminate\Notifications\Notification, users: Collection<int, User>, at: CarbonInterface}>
     */
    public function replyDeliveries(Comment $reply, bool $mail = true): Collection
    {
        $reply->loadMissing('parent.user');
        $parent = $reply->parent;

        if ($parent === null
            || $parent->user === null
            || $reply->audience !== CommentAudience::Public
            || $reply->hidden_at !== null
            || $parent->hidden_at !== null
            || $parent->user->isAuthor()
            || $parent->user->id === $reply->user_id
            || ! $this->reaches($parent->user, 'replies', $mail)) {
            return collect();
        }

        return collect([[
            'notification' => new CommentReply($reply->id, 'replies', $mail),
            'users' => collect([$parent->user]),
            'at' => $reply->created_at,
        ]]);
    }

    /**
     * Readers (not authors) who follow a work or its series.
     *
     * @return Collection<int, User>
     */
    private function followersOf(Work $work): Collection
    {
        $followerIds = Follow::query()
            ->where(fn (Builder $query): Builder => $query->where('followable_type', 'work')->where('followable_id', $work->id))
            ->when($work->series_id !== null, fn (Builder $query): Builder => $query->orWhere(
                fn (Builder $query): Builder => $query->where('followable_type', 'series')->where('followable_id', $work->series_id)
            ))
            ->pluck('user_id');

        return User::query()->whereIn('id', $followerIds)->where('role', '!=', UserRole::Author)->get();
    }

    /**
     * Readers, not authors, who asked for news of new works (site or email). Checked once per run.
     *
     * @return Collection<int, User>
     */
    private function newReaders(bool $mail): Collection
    {
        $candidates = User::query()
            ->where('role', '!=', UserRole::Author)
            ->where(function (Builder $query) use ($mail): void {
                $query->whereRaw("(preferences->'notifications'->>'new.site') = 'true'");

                if ($mail) {
                    $query->orWhereRaw("(preferences->'notifications'->>'new.email') = 'true'");
                }
            })
            ->get();

        return $this->reaching($candidates, 'new', $mail);
    }

    /**
     * The users who get this preference on the site, or by email when $mail is on.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    private function reaching(Collection $users, string $preference, bool $mail): Collection
    {
        return $users->filter(fn (User $user): bool => $this->reaches($user, $preference, $mail))->values();
    }

    private function reaches(User $user, string $preference, bool $mail): bool
    {
        $switches = Preferences::notificationsFor($user);

        return $switches[$preference.'.site'] || ($mail && $switches[$preference.'.email']);
    }

    private function inWindow(?CarbonInterface $at, CarbonInterface $from, CarbonInterface $to): bool
    {
        return $at !== null && $at->greaterThan($from) && $at->lessThanOrEqualTo($to);
    }
}
