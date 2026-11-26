<?php

namespace App\Models;

use Database\Factories\WorldEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['world_group_id', 'name', 'typed_as', 'description', 'notes', 'position'])]
class WorldEntry extends Model
{
    /** @use HasFactory<WorldEntryFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notes' => 'array',
        ];
    }

    /** @return BelongsTo<WorldGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(WorldGroup::class, 'world_group_id');
    }

    /** @return MorphMany<ResearchTie, $this> */
    public function researchTies(): MorphMany
    {
        return $this->morphMany(ResearchTie::class, 'tieable');
    }
}
