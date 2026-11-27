<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * The contact tab's data and saving: the address, the footer's line, and her handles on each network. The handles are the
 * ones the footer and About link to (SocialNetworks turns them into addresses).
 */
class ContactAdmin
{
    /**
     * @return array{contact: array{email: string, line: string, links: list<array{network: string, handle: string}>}, networks: list<string>}
     */
    public static function props(): array
    {
        $links = SiteSetting::get('links');
        $networks = SocialNetworks::names();

        return [
            'contact' => [
                'email' => SiteChrome::email(),
                'line' => SiteChrome::line(),
                'links' => array_values(array_map(
                    fn (array $link): array => ['network' => (string) $link['network'], 'handle' => (string) ($link['handle'] ?? '')],
                    array_filter(is_array($links) ? $links : [], fn (mixed $link): bool => is_array($link) && in_array($link['network'] ?? null, $networks, true)),
                )),
            ],
            'networks' => $networks,
        ];
    }

    /**
     * Saves her address, the footer's line and her handles.
     *
     * @param  list<array{network: string, handle?: string|null}>  $links
     */
    public static function save(string $email, string $line, array $links): void
    {
        SiteSetting::put('contact', ['email' => $email]);
        SiteSetting::put('footer', ['line' => $line]);
        SiteSetting::put('links', array_values(array_map(
            fn (array $link): array => ['network' => $link['network'], 'handle' => (string) ($link['handle'] ?? '')],
            $links,
        )));
    }
}
