<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Enums\Visibility;
use App\Models\ReviewerInvite;
use App\Models\User;
use App\Models\Work;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Accepting a reviewer invite: the person reads the invite's works as a reviewer, and the invite is used up.
 */
class ReviewerInvites
{
    /**
     * Makes the user a reviewer of the invite's works and marks the invite accepted. A reader becomes a reviewer; an
     * author keeps their role. A Private work the invite is for opens to reviewers, as giving a work does.
     */
    public static function accept(ReviewerInvite $invite, User $user): void
    {
        DB::transaction(function () use ($invite, $user): void {
            /* a model just created has no role in memory yet (the column default), so only an author is kept as is */
            if ($user->role !== UserRole::Author) {
                $user->forceFill(['role' => UserRole::Reviewer])->save();
            }

            $works = $invite->works()->get();
            $user->reviewableWorks()->syncWithoutDetaching($works->pluck('id')->all());

            foreach ($works as $work) {
                if ($work->visibility === Visibility::Private) {
                    $work->forceFill(['visibility' => Visibility::Reviewers])->save();
                }
            }

            $invite->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save();
        });
    }

    /**
     * The work an invite opens on: the first one it was given, in the order they were given.
     */
    public static function firstWork(ReviewerInvite $invite): ?Work
    {
        return $invite->works()
            ->orderBy('reviewer_invite_work.created_at')
            ->orderBy('works.id')
            ->first();
    }

    /**
     * The sign-in listener: a guest who opened an invite and then signed in or made an account is a reviewer now, if the
     * invite is still waiting. Sign-ins on the admin host don't take it up, and an author keeps their account as it is.
     */
    public static function acceptFromSession(User $user, Request $request): void
    {
        if (! $request->hasSession() || $request->getHost() === config('eyiwetemi.admin_domain')) {
            return;
        }

        $inviteId = $request->session()->pull('review.invite');
        $invite = $inviteId === null ? null : ReviewerInvite::query()->find($inviteId);

        if ($invite === null || ! $invite->isPending() || $user->isAuthor()) {
            return;
        }

        self::accept($invite, $user);
    }
}
