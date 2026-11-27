<?php

namespace App\Support;

use App\Models\SiteSetting;
use Carbon\CarbonImmutable;

/**
 * The work that leads Home until a moment she picks, stored in UTC as {slug, until}. Once that moment has passed the pin
 * no longer leads; a pin with no end leads until she takes it off.
 */
class HomePin
{
    /**
     * The pin while it still leads, or null. Its until is null for a pin with no end.
     *
     * @return array{slug: string, until: CarbonImmutable|null}|null
     */
    public static function current(): ?array
    {
        $pin = SiteSetting::get('home_pin');

        if (! is_array($pin) || ! isset($pin['slug'])) {
            return null;
        }

        $until = isset($pin['until']) ? CarbonImmutable::parse($pin['until']) : null;

        if ($until !== null && $until->isPast()) {
            return null;
        }

        return ['slug' => (string) $pin['slug'], 'until' => $until];
    }

    /**
     * Pins a work until the given moment.
     */
    public static function set(string $slug, CarbonImmutable $until): void
    {
        SiteSetting::put('home_pin', ['slug' => $slug, 'until' => $until->toIso8601String()]);
    }

    /**
     * Takes the pin off: the newest work leads Home again.
     */
    public static function clear(): void
    {
        SiteSetting::put('home_pin', null);
    }
}
