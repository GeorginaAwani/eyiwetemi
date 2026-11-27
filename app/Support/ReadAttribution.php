<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Where a read came from and what it was read on, as the daily breakdowns label them. Labels only: no address is looked up.
 */
class ReadAttribution
{
    /** Referring hosts with a known name, as the stats show it. */
    private const SOURCES = [
        'instagram.com' => 'Instagram',
        't.co' => 'X',
        'x.com' => 'X',
        'twitter.com' => 'X',
        'wa.me' => 'WhatsApp',
        'whatsapp.com' => 'WhatsApp',
        'web.whatsapp.com' => 'WhatsApp',
        'facebook.com' => 'Facebook',
        'fb.com' => 'Facebook',
        'messenger.com' => 'Facebook',
        'goodreads.com' => 'Goodreads',
        'tiktok.com' => 'TikTok',
    ];

    /**
     * The newsletter's link says so; otherwise the referring host, with the site's own hosts and no referrer counted as Direct.
     */
    public static function source(Request $request, ?string $from, ?string $referrer): string
    {
        if ($from === 'newsletter') {
            return 'Newsletter';
        }

        $host = self::host($referrer);

        if ($host === null || in_array($host, self::ownHosts($request), true)) {
            return 'Direct';
        }

        if (preg_match('/(^|\.)google\.(?:[a-z]{2,3}\.)?[a-z]{2,3}$/', $host) === 1) {
            return 'Google';
        }

        return self::SOURCES[$host] ?? mb_strcut($host, 0, 255, 'UTF-8');
    }

    /**
     * The two-letter country the edge network reports, or null for no country, XX (unknown) or T1 (Tor).
     */
    public static function country(Request $request): ?string
    {
        $code = (string) $request->header((string) config('eyiwetemi.stats.country_header'));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 && $code !== 'XX' ? $code : null;
    }

    public static function device(Request $request): string
    {
        return preg_match('/Mobi|Android|iPhone|iPad|iPod/i', (string) $request->userAgent()) === 1 ? 'phone' : 'computer';
    }

    /**
     * The referring host in lower case, without the mobile and short-link prefixes; null when there is none.
     */
    private static function host(?string $referrer): ?string
    {
        $host = parse_url((string) $referrer, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^(?:(?:www|lm|m|l)\.)+/', '', strtolower($host));
    }

    /**
     * @return list<string>
     */
    private static function ownHosts(Request $request): array
    {
        return array_values(array_filter([
            strtolower($request->getHost()),
            strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
            strtolower((string) config('eyiwetemi.admin_domain')),
        ]));
    }
}
