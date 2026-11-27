<?php

namespace App\Support;

use App\Enums\CommentAudience;
use App\Enums\UserRole;
use App\Models\Comment;
use App\Models\ReviewerInvite;
use App\Models\User;
use App\Models\Work;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Reviewers page's data: the people reading the drafts (reviewers who have works, then the invites still waiting or
 * run out), each with their works and their notes, and the works they can be given. Every time is West Africa Time.
 *
 * @phpstan-type Cover array{src: string|null, color: string, dark: bool}
 * @phpstan-type WorkChoice array{slug: string, title: string, sub: string, visibility: string, cover: Cover}
 */
class ReviewersPage
{
    /** Days an invite's link works for. */
    public const INVITE_DAYS = 14;

    /**
     * Everything the Reviewers page draws: its people and the works they can be given.
     *
     * @return array{people: list<array<string, mixed>>, works: list<WorkChoice>}
     */
    public static function props(): array
    {
        return ['people' => self::people(), 'works' => self::works()];
    }

    /**
     * The reviewers who have at least one work, most recent note first (no notes last, then by name), and then the
     * invites not yet accepted or cancelled, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function people(): array
    {
        $users = User::query()
            ->where('role', UserRole::Reviewer)
            ->whereHas('reviewableWorks')
            ->with(['reviewableWorks' => fn (BelongsToMany $query) => $query->orderBy('works.id')])
            ->get();

        $stats = self::noteStats($users->pluck('id'));

        $reviewers = $users
            ->map(function (User $user) use ($stats): array {
                $stat = $stats->get($user->id);

                return [
                    'at' => $stat === null ? 0 : Carbon::parse($stat->latest)->getTimestamp(),
                    'name' => (string) $user->name,
                    'person' => self::userPerson($user, (int) ($stat?->total ?? 0), $stat === null ? null : Carbon::parse($stat->latest)),
                ];
            })
            ->all();

        usort($reviewers, fn (array $a, array $b): int => ($b['at'] <=> $a['at']) ?: strcasecmp($a['name'], $b['name']));

        $invites = ReviewerInvite::query()
            ->whereNull('accepted_at')
            ->whereNull('cancelled_at')
            ->with(['works' => fn (BelongsToMany $query) => $query->orderBy('works.id')])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ReviewerInvite $invite): array => self::invitePerson($invite))
            ->all();

        return [...array_column($reviewers, 'person'), ...$invites];
    }

    /**
     * One person as the page draws them after a change: a reviewer or an invite.
     *
     * @return array<string, mixed>
     */
    public static function personFor(User|ReviewerInvite $subject): array
    {
        if ($subject instanceof ReviewerInvite) {
            return self::invitePerson($subject->load(['works' => fn (BelongsToMany $query) => $query->orderBy('works.id')]));
        }

        $subject->load(['reviewableWorks' => fn (BelongsToMany $query) => $query->orderBy('works.id')]);
        $stat = self::noteStats(collect([$subject->id]))->get($subject->id);

        return self::userPerson(
            $subject,
            (int) ($stat?->total ?? 0),
            $stat === null ? null : Carbon::parse($stat->latest),
        );
    }

    /**
     * Every work, in the order the Works list shows them, as the works the reviewers can be given.
     *
     * @return list<WorkChoice>
     */
    public static function works(): array
    {
        $order = array_flip(array_column(WorksAdmin::rows(), 'slug'));

        return Work::query()
            ->with(['cover', 'series'])
            ->get()
            ->sortBy(fn (Work $work): int => $order[$work->slug] ?? PHP_INT_MAX)
            ->values()
            ->map(fn (Work $work): array => [
                'slug' => $work->slug,
                'title' => $work->title,
                'sub' => explode(' · ', WorksAdmin::sub($work))[0],
                'visibility' => $work->visibility->value,
                'cover' => WorkPresenter::cover($work),
            ])
            ->all();
    }

    /**
     * How many top-level review notes each of these people wrote, and when the latest one was written.
     *
     * @param  Collection<int, int>  $userIds
     * @return Collection<int|string, Comment>
     */
    private static function noteStats(Collection $userIds): Collection
    {
        return Comment::query()
            ->where('audience', CommentAudience::Review)
            ->whereNull('parent_id')
            ->whereIn('user_id', $userIds->all())
            ->selectRaw('user_id, count(*) as total, max(created_at) as latest')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');
    }

    /**
     * A reviewer with access to works.
     *
     * @return array<string, mixed>
     */
    private static function userPerson(User $user, int $notes, ?CarbonInterface $latest): array
    {
        return [
            'key' => 'u'.$user->id,
            'kind' => 'user',
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'seed' => $user->avatar_seed,
            'works' => $user->reviewableWorks->pluck('slug')->values()->all(),
            'comments' => $notes,
            'last' => $latest === null ? '' : ReaderPage::ago($latest),
            'state' => 'on',
            'days' => null,
            'note' => null,
        ];
    }

    /**
     * An invite still waiting, or run out: whole days left (at least none) and the note sent with it.
     *
     * @return array<string, mixed>
     */
    private static function invitePerson(ReviewerInvite $invite): array
    {
        $seconds = $invite->expires_at->getTimestamp() - now()->getTimestamp();

        return [
            'key' => 'i'.$invite->id,
            'kind' => 'invite',
            'id' => $invite->id,
            'name' => $invite->name,
            'email' => $invite->email,
            'seed' => $invite->email,
            'works' => $invite->works->pluck('slug')->values()->all(),
            'comments' => 0,
            'last' => '',
            'state' => $invite->expires_at->isPast() ? 'expired' : 'invited',
            'days' => max(0, (int) ceil($seconds / 86400)),
            'note' => $invite->note,
        ];
    }
}
