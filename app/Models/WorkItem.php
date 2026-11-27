<?php

namespace App\Models;

use App\Enums\PublishStatus;
use App\Enums\WorkItemType;
use Database\Factories\WorkItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'work_id', 'type', 'position', 'piece_work_id', 'title', 'description',
    'draft_body', 'live_body', 'draft_text', 'live_text', 'draft_words', 'live_words',
    'draft_revision', 'draft_saved_at', 'draft_device', 'status', 'scheduled_at', 'published_at', 'live_updated_at',
    'pov_character_id', 'epigraph', 'epigraph_by', 'soundtrack_url', 'soundtrack_label',
])]
#[Hidden(['search'])]
class WorkItem extends Model
{
    /** @use HasFactory<WorkItemFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WorkItemType::class,
            'status' => PublishStatus::class,
            'draft_body' => 'array',
            'live_body' => 'array',
            'draft_saved_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'live_updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Work, $this> */
    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    /** @return BelongsTo<Work, $this> */
    public function pieceWork(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'piece_work_id');
    }

    /** @return BelongsTo<Character, $this> */
    public function povCharacter(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'pov_character_id');
    }

    /** @return MorphMany<Version, $this> */
    public function versions(): MorphMany
    {
        return $this->morphMany(Version::class, 'versionable')->latest()->latest('id');
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** @return MorphMany<Like, $this> */
    public function likes(): MorphMany
    {
        return $this->morphMany(Like::class, 'likeable');
    }

    /** @return HasMany<ReadingProgress, $this> */
    public function readingProgress(): HasMany
    {
        return $this->hasMany(ReadingProgress::class);
    }

    /** @return HasMany<PlotPoint, $this> */
    public function plotPoints(): HasMany
    {
        return $this->hasMany(PlotPoint::class);
    }

    /**
     * Chapters and pieces readers can open: published, and their time has come.
     */
    #[Scope]
    protected function released(Builder $query): Builder
    {
        return $query->where('status', PublishStatus::Published)->where('published_at', '<=', now());
    }

    /**
     * Whether a published item has draft edits that are not live yet.
     */
    public function hasUnpublishedChanges(): bool
    {
        return $this->status === PublishStatus::Published && $this->draft_body !== $this->live_body;
    }
}
