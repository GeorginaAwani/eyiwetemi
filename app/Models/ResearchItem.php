<?php

namespace App\Models;

use App\Enums\ResearchKind;
use Database\Factories\ResearchItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['work_id', 'kind', 'title', 'url', 'note', 'media_id', 'position'])]
class ResearchItem extends Model
{
    /** @use HasFactory<ResearchItemFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ResearchKind::class,
        ];
    }

    /** @return BelongsTo<Work, $this> */
    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    /** @return HasMany<ResearchTie, $this> */
    public function ties(): HasMany
    {
        return $this->hasMany(ResearchTie::class, 'research_item_id');
    }
}
