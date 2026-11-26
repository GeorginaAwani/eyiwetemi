<?php

namespace App\Models;

use Database\Factories\SubscriberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

#[Fillable(['email', 'kinds', 'manage_token', 'confirm_token_hash', 'confirmed_at'])]
#[Hidden(['manage_token', 'confirm_token_hash'])]
class Subscriber extends Model
{
    /** @use HasFactory<SubscriberFactory> */
    use HasFactory, Prunable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kinds' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * Get the query for unconfirmed subscribers that are no longer needed.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->whereNull('confirmed_at')
            ->where('created_at', '<', now()->subDays(30));
    }

    #[Scope]
    protected function confirmed(Builder $query): Builder
    {
        return $query->whereNotNull('confirmed_at');
    }
}
