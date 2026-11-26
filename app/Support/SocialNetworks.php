<?php

namespace App\Support;

class SocialNetworks
{
    /**
     * Address prefixes for the sites she can link to from the footer and About.
     *
     * @var array<string, string>
     */
    private const PREFIXES = [
        'X' => 'https://x.com/',
        'Instagram' => 'https://instagram.com/',
        'Goodreads' => 'https://goodreads.com/',
        'TikTok' => 'https://tiktok.com/@',
        'Threads' => 'https://threads.net/@',
        'YouTube' => 'https://youtube.com/@',
        'Facebook' => 'https://facebook.com/',
        'Substack' => 'https://',
        'Website' => 'https://',
    ];

    /**
     * Turn the stored links into network, handle and address, dropping empty handles.
     *
     * @param  array<int, array{network?: string, handle?: string}>  $links
     * @return list<array{network: string, url: string}>
     */
    public static function links(array $links): array
    {
        $out = [];

        foreach ($links as $link) {
            $network = $link['network'] ?? 'Website';
            $handle = trim((string) ($link['handle'] ?? ''));

            if ($handle === '') {
                continue;
            }

            $out[] = ['network' => array_key_exists($network, self::PREFIXES) ? $network : 'Website', 'url' => self::url($network, $handle)];
        }

        return $out;
    }

    private static function url(string $network, string $handle): string
    {
        if (preg_match('#^https?://#i', $handle)) {
            return $handle;
        }

        $handlesOnly = ['X', 'Instagram', 'TikTok', 'Threads', 'YouTube'];

        if (str_contains($handle, '.') && ! in_array($network, $handlesOnly, true)) {
            return 'https://'.$handle;
        }

        return (self::PREFIXES[$network] ?? 'https://').ltrim($handle, '@');
    }
}
