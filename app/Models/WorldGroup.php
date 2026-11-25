<?php

namespace App\Models;

use Database\Factories\WorldGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['work_id', 'name', 'position'])]
class WorldGroup extends Model
{
    /** @use HasFactory<WorldGroupFactory> */
    use HasFactory;

    /** @return BelongsTo<Work, $this> */
    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    /** @return HasMany<WorldEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(WorldEntry::class)->orderBy('position');
    }
}
