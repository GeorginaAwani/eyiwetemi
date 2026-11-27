<?php

namespace App\Models;

use Database\Factories\NoticeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['text', 'link_target', 'link_url', 'starts_at', 'ends_at', 'taken_down_at'])]
class Notice extends Model
{
    /** @use HasFactory<NoticeFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'taken_down_at' => 'datetime',
        ];
    }

    #[Scope]
    protected function live(Builder $query): Builder
    {
        return $query->where('starts_at', '<=', now())
            ->where(function (Builder $query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->whereNull('taken_down_at');
    }

    /**
     * Up or scheduled: not taken down, and not yet ended.
     */
    #[Scope]
    protected function current(Builder $query): Builder
    {
        return $query->whereNull('taken_down_at')
            ->where(function (Builder $query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            });
    }

    /**
     * Taken down or ended: what the notice tab keeps as earlier notices.
     */
    #[Scope]
    protected function earlier(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNotNull('taken_down_at')->orWhere('ends_at', '<=', now());
        });
    }

    /**
     * Whether the notice is up or scheduled (see the current scope).
     */
    public function isCurrent(): bool
    {
        return $this->taken_down_at === null && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}
