<?php

namespace App\Models;

use App\Enums\SendStatus;
use Database\Factories\NewsletterStopFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * An address that stopped getting the newsletter, by the reader or by the desk. It is counted against the latest send
 * that went out, so each send shows how many stopped after it.
 */
#[Fillable(['newsletter_send_id'])]
class NewsletterStop extends Model
{
    /** @use HasFactory<NewsletterStopFactory> */
    use HasFactory;

    /** A stop only has its creation time. */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
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
     * Records one stop now, against the latest send that went out (none when nothing has gone out yet).
     */
    public static function record(): void
    {
        DB::transaction(function (): void {
            $send = NewsletterSend::query()
                ->where('status', SendStatus::Sent)
                ->orderByDesc('scheduled_for')
                ->first(['id']);

            static::query()->create(['newsletter_send_id' => $send?->id]);

            if ($send !== null) {
                NewsletterSend::query()->whereKey($send->id)->increment('stopped_count');
            }
        });
    }
}
