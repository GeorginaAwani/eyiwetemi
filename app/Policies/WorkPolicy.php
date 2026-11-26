<?php

namespace App\Policies;

use App\Enums\Visibility;
use App\Models\User;
use App\Models\Work;

class WorkPolicy
{
    /**
     * Who can open a work's page or read it.
     * Public: everyone, once something is out. Reviewers: the author and the reviewers it is shared with.
     * Private: only the author.
     */
    public function view(?User $user, Work $work): bool
    {
        if ($user?->isAuthor()) {
            return true;
        }

        return match ($work->visibility) {
            Visibility::Public => $work->first_published_at !== null,
            Visibility::Reviewers => $user !== null && $work->reviewers()->whereKey($user->id)->exists(),
            Visibility::Private => false,
        };
    }
}
