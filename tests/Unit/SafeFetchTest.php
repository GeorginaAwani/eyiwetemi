<?php

namespace Tests\Unit;

use App\Support\SafeFetch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SafeFetchTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function addresses(): array
    {
        return [
            'public IPv4' => ['http://8.8.8.8/', true],
            'public IPv6' => ['https://[2606:4700::1]/', true],
            'loopback' => ['http://127.0.0.1/', false],
            'IPv6 loopback' => ['http://[::1]/', false],
            'IPv4 written as IPv6' => ['http://[::ffff:127.0.0.1]/', false],
            'private network' => ['http://10.0.0.5/', false],
            'IPv6 unique local' => ['http://[fc00::1]/', false],
            'cloud metadata' => ['http://169.254.169.254/latest', false],
            'shared carrier space' => ['http://100.64.0.1/', false],
            'not the web' => ['file:///etc/passwd', false],
        ];
    }

    #[DataProvider('addresses')]
    public function test_only_public_web_addresses_are_fetched(string $url, bool $public): void
    {
        $this->assertSame($public, SafeFetch::isPublic($url));
    }
}
