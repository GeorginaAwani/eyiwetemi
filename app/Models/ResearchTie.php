<?php

namespace App\Models;

use Database\Factories\ResearchTieFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['research_item_id', 'tieable_type', 'tieable_id'])]
class ResearchTie extends Model
{
    /** @use HasFactory<ResearchTieFactory> */
    use HasFactory;

    /** @return BelongsTo<ResearchItem, $this> */
    public function researchItem(): BelongsTo
    {
        return $this->belongsTo(ResearchItem::class, 'research_item_id');
    }

    /** @return MorphTo<Model, $this> */
    public function tieable(): MorphTo
    {
        return $this->morphTo();
    }
}
