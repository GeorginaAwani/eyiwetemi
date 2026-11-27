<?php

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches a web page for the site, but only public addresses: never this machine or a private network. Redirects are
 * followed only as far as asked, and each new address is checked the same way as the first.
 */
class SafeFetch
{
    /**
     * Only public web addresses: http or https, and every address the host resolves to is public.
     */
    public static function isPublic(string $url): bool
    {
        $parts = parse_url($url);

        if (! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
            return false;
        }

        $ips = gethostbynamel(trim((string) $parts['host'], '[]')) ?: [];

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return $ips !== [];
    }

    /**
     * A GET of a public address. A redirect is followed up to $redirects times, each new address checked to be public.
     *
     * @throws RuntimeException when an address is not public
     */
    public static function get(string $url, float $timeoutSeconds = 6, int $redirects = 0): Response
    {
        $hops = 0;

        while (true) {
            if (! self::isPublic($url)) {
                throw new RuntimeException('Not a public address.');
            }

            $response = Http::timeout($timeoutSeconds)->withoutRedirecting()->get($url);
            $location = (string) $response->header('Location');

            if (! $response->redirect() || $location === '' || $hops >= $redirects) {
                return $response;
            }

            $url = self::resolve($url, $location);
            $hops++;
        }
    }

    /**
     * The address a redirect points to, read against the address it came from.
     */
    private static function resolve(string $from, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($from);
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $parts['scheme'].':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        return $origin.rtrim(dirname($parts['path'] ?? '/'), '/').'/'.$location;
    }
}
