<?php

namespace App\Models;

use Database\Factories\CharacterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['work_id', 'name', 'typed_as', 'aliases', 'role', 'notes', 'position'])]
class Character extends Model
{
    /** @use HasFactory<CharacterFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'notes' => 'array',
        ];
    }

    /** @return BelongsTo<Work, $this> */
    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    /** @return HasMany<CharacterLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(CharacterLink::class, 'character_id');
    }

    /** @return HasMany<WorkItem, $this> */
    public function povItems(): HasMany
    {
        return $this->hasMany(WorkItem::class, 'pov_character_id');
    }

    /** @return MorphMany<ResearchTie, $this> */
    public function researchTies(): MorphMany
    {
        return $this->morphMany(ResearchTie::class, 'tieable');
    }
}
