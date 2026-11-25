<?php

namespace App\Models;

use App\Enums\SendStatus;
use Database\Factories\NewsletterSendFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['scheduled_for', 'status', 'intro_lines', 'sent_at'])]
class NewsletterSend extends Model
{
    /** @use HasFactory<NewsletterSendFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'status' => SendStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<NewsletterSendItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(NewsletterSendItem::class, 'newsletter_send_id')->orderBy('position');
    }
}
