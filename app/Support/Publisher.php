<?php

namespace App\Support;

use App\Enums\PublishStatus;
use App\Enums\VersionLabel;
use App\Enums\WorkItemType;
use App\Models\User;
use App\Models\Version;
use App\Models\WorkItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * What happens when she publishes: the draft becomes what readers see, and a version is kept (Published or Updated).
 * Scheduling waits for its time (WAT), Unpublish takes it back to a draft, and any version can come back as the draft.
 */
class Publisher
{
    /**
     * Publish now, or update what's out. Parts have no text: they just come out.
     */
    public static function publish(WorkItem $item, ?User $by, ?CarbonInterface $at = null): void
    {
        $at ??= now();

        DB::transaction(function () use ($item, $by, $at): void {
            $wasOut = $item->status === PublishStatus::Published;
            $item->loadMissing('work');

            if ($item->type !== WorkItemType::Part) {
                $body = $item->draft_body ?? $item->live_body ?? Prose::empty();
                $text = Prose::text($body);
                $previous = $item->versions()->first();
                $change = Prose::wordChange($previous ? Prose::text($previous->body) : '', $text);

                $item->versions()->create([
                    'label' => $wasOut ? VersionLabel::Updated : VersionLabel::Published,
                    'body' => $body,
                    'words' => Prose::words($body),
                    'words_added' => $change['added'],
                    'words_cut' => $change['cut'],
                    'created_by' => $by?->id,
                ]);

                $item->forceFill([
                    'draft_body' => $body,
                    'live_body' => $body,
                    'live_text' => $text,
                    'live_words' => Prose::words($body),
                ]);
            }

            $item->forceFill([
                'status' => PublishStatus::Published,
                'published_at' => $wasOut ? $item->published_at : ($item->published_at ?? $at),
                'live_updated_at' => $at,
                'scheduled_at' => null,
            ])->save();

            $work = $item->work;
            $work->forceFill([
                'first_published_at' => $work->first_published_at ?? $at,
                'last_published_at' => $wasOut ? $work->last_published_at : $at,
            ])->save();
        });
    }

    /**
     * Publish at a day and time (WAT).
     */
    public static function schedule(WorkItem $item, CarbonInterface $at): void
    {
        $item->forceFill(['status' => PublishStatus::Scheduled, 'scheduled_at' => $at])->save();
    }

    public static function unschedule(WorkItem $item): void
    {
        $item->forceFill(['status' => PublishStatus::Draft, 'scheduled_at' => null])->save();
    }

    /**
     * Readers stop seeing it; it goes back to being a draft. Its comments and likes stay, to come back with it.
     */
    public static function unpublish(WorkItem $item, ?User $by): void
    {
        DB::transaction(function () use ($item, $by): void {
            if ($item->type !== WorkItemType::Part && $item->live_body) {
                $item->versions()->create([
                    'label' => VersionLabel::Unpublished,
                    'body' => $item->live_body,
                    'words' => (int) $item->live_words,
                    'words_added' => 0,
                    'words_cut' => 0,
                    'created_by' => $by?->id,
                ]);
            }

            $item->forceFill(['status' => PublishStatus::Unpublished, 'scheduled_at' => null])->save();
        });
    }

    /**
     * Bring a version back as the draft; the draft it replaces is kept as a version first.
     */
    public static function restore(WorkItem $item, Version $version, ?User $by): void
    {
        DB::transaction(function () use ($item, $version, $by): void {
            $current = $item->draft_body ?? $item->live_body ?? Prose::empty();

            $item->versions()->create([
                'label' => VersionLabel::DraftBeforeRestore,
                'body' => $current,
                'words' => Prose::words($current),
                'words_added' => 0,
                'words_cut' => 0,
                'created_by' => $by?->id,
            ]);

            $item->forceFill([
                'draft_body' => $version->body,
                'draft_text' => Prose::text($version->body),
                'draft_words' => Prose::words($version->body),
                'draft_revision' => $item->draft_revision + 1,
                'draft_saved_at' => now(),
            ])->save();
        });
    }

    /**
     * Everything whose time has come. Returns how many came out.
     */
    public static function publishDue(): int
    {
        $due = WorkItem::query()
            ->where('status', PublishStatus::Scheduled)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();

        foreach ($due as $item) {
            self::publish($item, null);
        }

        return $due->count();
    }

    /**
     * draft | sch | pub | chg (published, with edits waiting) | unpub — as the writing screen and lists show it.
     */
    public static function state(WorkItem $item): string
    {
        return match ($item->status) {
            PublishStatus::Scheduled => 'sch',
            PublishStatus::Unpublished => 'unpub',
            PublishStatus::Published => $item->type !== WorkItemType::Part && $item->draft_body !== null && $item->draft_body != $item->live_body ? 'chg' : 'pub',
            default => 'draft',
        };
    }
}
