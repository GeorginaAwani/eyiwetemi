<?php

namespace App\Support;

use App\Enums\VersionLabel;
use App\Models\Version;
use App\Models\WorkItem;

/**
 * Where a chapter stands, for the writing screen's header and Versions list.
 */
class WritePage
{
    /**
     * @return array{state: string, scheduledAt: string|null, liveAt: string|null, audience: string, versions: list<array<string, mixed>>}
     */
    public static function state(WorkItem $item): array
    {
        $item->loadMissing('work');

        return [
            'state' => Publisher::state($item),
            'scheduledAt' => $item->scheduled_at?->toIso8601String(),
            'liveAt' => $item->live_updated_at?->toIso8601String(),
            'audience' => ChaptersAdmin::audience($item->work),
            'versions' => $item->versions()->get(['id', 'label', 'device', 'words', 'words_added', 'words_cut', 'created_at'])
                ->map(fn (Version $v): array => [
                    'id' => $v->id,
                    'label' => self::label($v),
                    'at' => $v->created_at->toIso8601String(),
                    'words' => $v->words,
                    'added' => $v->words_added,
                    'cut' => $v->words_cut,
                ])->all(),
        ];
    }

    private static function label(Version $version): string
    {
        return match ($version->label) {
            VersionLabel::Published => 'Published',
            VersionLabel::Updated => 'Updated',
            VersionLabel::Unpublished => 'Unpublished',
            VersionLabel::DraftBeforeRestore => 'Draft before restore',
            VersionLabel::DraftFromDevice => self::deviceDraftLabel($version->device),
        };
    }

    /**
     * "Draft from this phone" when it came from the device asking, "Draft from Laptop" when it came from another.
     */
    private static function deviceDraftLabel(?string $device): string
    {
        if ($device === null) {
            return 'Draft from another device';
        }

        return $device === DeviceKind::current()
            ? 'Draft from this '.$device
            : 'Draft from '.ucfirst($device);
    }
}
