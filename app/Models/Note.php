<?php

namespace App\Models;

use App\Enums\PublishStatus;
use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['status', 'draft_body', 'live_body', 'draft_text', 'live_text', 'media_id', 'draft_media_id', 'work_id', 'draft_work_id', 'notify_followers', 'scheduled_at', 'published_at', 'live_updated_at'])]
#[Hidden(['search'])]
class Note extends Model
{
    /** @use HasFactory<NoteFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PublishStatus::class,
            'draft_body' => 'array',
            'live_body' => 'array',
            'notify_followers' => 'boolean',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'live_updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function draftMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'draft_media_id');
    }

    /**
     * @return BelongsTo<Work, $this>
     */
    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'work_id');
    }

    /**
     * @return BelongsTo<Work, $this>
     */
    public function draftWork(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'draft_work_id');
    }

    /**
     * @return MorphMany<Like, $this>
     */
    public function likes(): MorphMany
    {
        return $this->morphMany(Like::class, 'likeable');
    }
}
