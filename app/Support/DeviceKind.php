<?php

namespace App\Support;

/**
 * Which kind of device a request comes from (phone, tablet or laptop), from its user agent. Drafts and versions
 * remember it so the writing screen can say "Draft from Laptop" or "Draft from this phone".
 */
class DeviceKind
{
    /**
     * The kind of device a user agent string belongs to. Anything that is not a phone or a tablet counts as a laptop.
     *
     * @return 'phone'|'tablet'|'laptop'
     */
    public static function of(string $userAgent): string
    {
        $tablet = str_contains($userAgent, 'iPad')
            || str_contains($userAgent, 'Tablet')
            || (str_contains($userAgent, 'Android') && ! str_contains($userAgent, 'Mobile'));

        if ($tablet) {
            return 'tablet';
        }

        $phone = str_contains($userAgent, 'iPhone')
            || str_contains($userAgent, 'iPod')
            || str_contains($userAgent, 'Mobile');

        return $phone ? 'phone' : 'laptop';
    }

    /**
     * The kind of device the current request comes from.
     *
     * @return 'phone'|'tablet'|'laptop'
     */
    public static function current(): string
    {
        return self::of((string) request()->userAgent());
    }
}
