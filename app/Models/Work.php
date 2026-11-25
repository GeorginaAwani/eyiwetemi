<?php

namespace App\Models;

use App\Enums\CollectionKind;
use App\Enums\HeadingStyle;
use App\Enums\SceneBreak;
use App\Enums\Visibility;
use App\Enums\WorkItemType;
use App\Enums\WorkKind;
use App\Enums\WorkStatus;
use Database\Factories\WorkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'kind', 'collection_kind', 'title', 'title_key', 'slug', 'description', 'status', 'visibility',
    'series_id', 'series_position', 'collection_id', 'cover_media_id', 'cover_color',
    'heading_style', 'show_chapter_titles', 'show_pov', 'part_word', 'part_style', 'show_part_numbers',
    'scene_break', 'opening_quote', 'opening_quote_by', 'content_notes', 'free_chapters', 'plan_notes', 'mark_names',
    'share_line', 'share_media_id', 'share_uses_own', 'first_published_at', 'last_published_at',
])]
#[Hidden(['search'])]
class Work extends Model
{
    /** @use HasFactory<WorkFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => WorkKind::class,
            'collection_kind' => CollectionKind::class,
            'status' => WorkStatus::class,
            'visibility' => Visibility::class,
            'heading_style' => HeadingStyle::class,
            'part_style' => HeadingStyle::class,
            'scene_break' => SceneBreak::class,
            'show_chapter_titles' => 'boolean',
            'show_pov' => 'boolean',
            'show_part_numbers' => 'boolean',
            'mark_names' => 'boolean',
            'share_uses_own' => 'boolean',
            'free_chapters' => 'integer',
            'plan_notes' => 'array',
            'first_published_at' => 'datetime',
            'last_published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Series, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    /** @return BelongsTo<Work, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'collection_id');
    }

    /** @return HasMany<Work, $this> */
    public function collectionPieces(): HasMany
    {
        return $this->hasMany(Work::class, 'collection_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function shareImage(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'share_media_id');
    }

    /** @return BelongsToMany<Genre, $this> */
    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class)->withTimestamps();
    }

    /** @return HasMany<WorkItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(WorkItem::class)->orderBy('position');
    }

    /** @return HasMany<WorkItem, $this> */
    public function chapters(): HasMany
    {
        return $this->hasMany(WorkItem::class)->where('type', WorkItemType::Chapter)->orderBy('position');
    }

    /** @return MorphMany<SlugHistory, $this> */
    public function slugHistory(): MorphMany
    {
        return $this->morphMany(SlugHistory::class, 'sluggable');
    }

    /** @return BelongsToMany<User, $this> */
    public function reviewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'work_reviewer')->withTimestamps();
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** @return MorphMany<Follow, $this> */
    public function follows(): MorphMany
    {
        return $this->morphMany(Follow::class, 'followable');
    }

    /** @return HasMany<Note, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /** @return HasMany<Character, $this> */
    public function characters(): HasMany
    {
        return $this->hasMany(Character::class);
    }

    /** @return HasMany<WorldGroup, $this> */
    public function worldGroups(): HasMany
    {
        return $this->hasMany(WorldGroup::class);
    }

    /** @return HasMany<PlotThread, $this> */
    public function plotThreads(): HasMany
    {
        return $this->hasMany(PlotThread::class);
    }

    /** @return HasMany<ResearchItem, $this> */
    public function researchItems(): HasMany
    {
        return $this->hasMany(ResearchItem::class);
    }

    /** @return HasMany<SweepName, $this> */
    public function sweepNames(): HasMany
    {
        return $this->hasMany(SweepName::class);
    }

    /** @return HasMany<WritingDay, $this> */
    public function writingDays(): HasMany
    {
        return $this->hasMany(WritingDay::class);
    }
}
