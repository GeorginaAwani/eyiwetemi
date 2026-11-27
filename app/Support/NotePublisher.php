<?php

namespace App\Support;

use App\Enums\PublishStatus;
use App\Models\Note;
use App\Notifications\NewNote;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * What happens when she publishes a note: the draft becomes what readers see, and an update carries the draft out again.
 * Scheduled notes come out when their time comes; Undo takes a note back to a draft for two minutes after it went out.
 */
class NotePublisher
{
    /** How long a note can be taken back after it goes out. */
    public const UNDO_MINUTES = 2;

    /**
     * Publish now, or update what's out. The first publish time stays; an update only moves the live time.
     */
    public static function publish(Note $note, ?CarbonInterface $at = null): void
    {
        $at ??= now();
        $wasOut = $note->status === PublishStatus::Published;
        $body = $note->draft_body ?? Prose::empty();

        $note->forceFill([
            'status' => PublishStatus::Published,
            'live_body' => $body,
            'live_text' => Prose::text($body),
            'media_id' => $note->draft_media_id,
            'work_id' => $note->draft_work_id,
            'published_at' => $wasOut ? $note->published_at : $at,
            'live_updated_at' => $at,
            'scheduled_at' => null,
        ])->save();
    }

    public static function schedule(Note $note, CarbonInterface $at): void
    {
        $note->forceFill(['status' => PublishStatus::Scheduled, 'scheduled_at' => $at])->save();
    }

    public static function unschedule(Note $note): void
    {
        $note->forceFill(['status' => PublishStatus::Draft, 'scheduled_at' => null])->save();
    }

    /**
     * Takes a note that went out a moment ago back to a draft, and removes the new-note notices sent for it.
     */
    public static function undo(Note $note): void
    {
        DB::transaction(function () use ($note): void {
            DB::table('notifications')
                ->where('type', NewNote::class)
                ->whereRaw("(data::jsonb)->>'note_id' = ?", [(string) $note->id])
                ->delete();

            $note->forceFill([
                'status' => PublishStatus::Draft,
                'live_body' => null,
                'live_text' => null,
                'media_id' => null,
                'work_id' => null,
                'published_at' => null,
                'live_updated_at' => null,
            ])->save();
        });
    }

    /**
     * Published, and within the time it can still be taken back.
     */
    public static function canUndo(Note $note): bool
    {
        return $note->status === PublishStatus::Published
            && $note->published_at !== null
            && $note->published_at->greaterThanOrEqualTo(now()->subMinutes(self::UNDO_MINUTES));
    }

    /**
     * A note with no words and no picture: nothing to send out.
     */
    public static function isEmpty(Note $note): bool
    {
        return Prose::text($note->draft_body) === '' && $note->draft_media_id === null;
    }

    /**
     * Publishes the scheduled notes whose time has come, at now. A note emptied after it was scheduled goes back to a
     * draft instead of going out blank. Returns how many came out.
     */
    public static function publishDue(): int
    {
        $due = Note::query()
            ->where('status', PublishStatus::Scheduled)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();

        $published = 0;

        foreach ($due as $note) {
            if (self::isEmpty($note)) {
                self::unschedule($note);

                continue;
            }

            self::publish($note, now());
            $published++;
        }

        return $published;
    }
}
