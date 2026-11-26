<?php

namespace App\Models;

use Database\Factories\DailyVisitorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

#[Fillable(['day', 'scope', 'hash'])]
class DailyVisitor extends Model
{
    /** @use HasFactory<DailyVisitorFactory> */
    use HasFactory, Prunable;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'date',
        ];
    }

    /**
     * Get the query for visitor rows from earlier days.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('day', '<', today());
    }
}
