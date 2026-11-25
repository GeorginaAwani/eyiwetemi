<?php

namespace App\Models;

use Database\Factories\NewsletterSendItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['newsletter_send_id', 'item_type', 'item_id', 'left_out', 'position'])]
class NewsletterSendItem extends Model
{
    /** @use HasFactory<NewsletterSendItemFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'left_out' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<NewsletterSend, $this>
     */
    public function send(): BelongsTo
    {
        return $this->belongsTo(NewsletterSend::class, 'newsletter_send_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function item(): MorphTo
    {
        return $this->morphTo();
    }
}
