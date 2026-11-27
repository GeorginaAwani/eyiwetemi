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
     * Only public web addresses: http or https, and every address the host resolves to (IPv4 and IPv6) is public.
     */
    public static function isPublic(string $url): bool
    {
        return self::publicAddress($url) !== null;
    }

    /**
     * The address to connect to when every address the host has is public, else null.
     */
    private static function publicAddress(string $url): ?string
    {
        $parts = parse_url($url);

        if (! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }

        $ips = self::addresses(trim((string) $parts['host'], '[]'));

        foreach ($ips as $ip) {
            if (! self::publicIp($ip)) {
                return null;
            }
        }

        return $ips[0] ?? null;
    }

    /**
     * Every address a host name points to, IPv4 first; an address typed as the host is itself.
     *
     * @return list<string>
     */
    private static function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $v4 = gethostbynamel($host) ?: [];
        $v6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique([...$v4, ...$v6]));
    }

    private static function publicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        /* an IPv4 address written as IPv6 is judged as the IPv4 address */
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $mapped) === 1) {
            return self::publicIp($mapped[1]);
        }

        /* shared carrier space (100.64.0.0/10), which the flags above let through */
        $long = ip2long($ip);

        return $long === false || ($long & 0xFFC00000) !== 0x64400000;
    }

    /**
     * A GET of a public address. A redirect is followed up to $redirects times, each new address checked to be public.
     * The connection goes to the very address that was checked, so the name can't be pointed elsewhere in between.
     *
     * @throws RuntimeException when an address is not public
     */
    public static function get(string $url, float $timeoutSeconds = 6, int $redirects = 0): Response
    {
        $hops = 0;

        while (true) {
            $ip = self::publicAddress($url);

            if ($ip === null) {
                throw new RuntimeException('Not a public address.');
            }

            $parts = parse_url($url);
            $port = (int) ($parts['port'] ?? (strtolower((string) $parts['scheme']) === 'https' ? 443 : 80));
            $host = trim((string) $parts['host'], '[]');
            $pin = str_contains($ip, ':') ? '['.$ip.']' : $ip;

            $response = Http::timeout($timeoutSeconds)
                ->withoutRedirecting()
                ->withOptions(['curl' => [
                    CURLOPT_RESOLVE => [$host.':'.$port.':'.$pin],
                    CURLOPT_MAXFILESIZE => 5 * 1024 * 1024,
                ]])
                ->get($url);
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
