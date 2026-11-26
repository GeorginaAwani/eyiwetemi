<?php

namespace App\Support;

use App\Models\User;

/**
 * A reader's settings that the site keeps on the account: which notices come, and how a chapter reads.
 * Anything a reader hasn't changed shows the default.
 */
class Preferences
{
    /** @var array<string, bool> */
    public const NOTIFICATION_DEFAULTS = [
        'chapters.site' => true,
        'chapters.email' => true,
        'notes.site' => true,
        'notes.email' => false,
        'replies.site' => true,
        'replies.email' => true,
        'new.site' => false,
        'new.email' => false,
    ];

    /** @var array{size: int, font: string, width: string, lines: string, theme: string} */
    public const READING_DEFAULTS = [
        'size' => 2,
        'font' => 'serif',
        'width' => 'narrow',
        'lines' => 'airy',
        'theme' => 'white',
    ];

    /**
     * Every notice switch, on or off, with the defaults filled in.
     *
     * @return array<string, bool>
     */
    public static function notificationsFor(User $user): array
    {
        $stored = is_array($user->preferences['notifications'] ?? null) ? $user->preferences['notifications'] : [];
        $notifications = [];

        foreach (self::NOTIFICATION_DEFAULTS as $key => $default) {
            $notifications[$key] = array_key_exists($key, $stored) ? (bool) $stored[$key] : $default;
        }

        return $notifications;
    }

    /**
     * How the reader wants a chapter to look, with the defaults filled in.
     *
     * @return array{size: int, font: string, width: string, lines: string, theme: string}
     */
    public static function readingFor(User $user): array
    {
        $stored = is_array($user->preferences['reading'] ?? null) ? $user->preferences['reading'] : [];

        return array_merge(self::READING_DEFAULTS, array_intersect_key($stored, self::READING_DEFAULTS));
    }
}
