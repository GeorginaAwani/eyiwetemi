<?php

namespace App\Models;

use Database\Factories\ReviewerInviteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['email', 'name', 'note', 'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'accepted_user_id', 'cancelled_at'])]
#[Hidden(['token_hash'])]
class ReviewerInvite extends Model
{
    /** @use HasFactory<ReviewerInviteFactory> */
    use HasFactory, Prunable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Get the query for invites that are no longer needed.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where(function (Builder $query): void {
            $query->where(function (Builder $query): void {
                $query->where('expires_at', '<', now()->subDays(90))
                    ->whereNull('accepted_at');
            })->orWhere('cancelled_at', '<', now()->subDays(90));
        });
    }

    /**
     * @return BelongsToMany<Work, $this>
     */
    public function works(): BelongsToMany
    {
        return $this->belongsToMany(Work::class, 'reviewer_invite_work')->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->cancelled_at === null && ! $this->isExpired();
    }
}
