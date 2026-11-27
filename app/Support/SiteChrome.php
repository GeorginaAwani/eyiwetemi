<?php

namespace App\Support;

use App\Models\Notice;
use App\Models\SiteSetting;

class SiteChrome
{
    /**
     * Where each notice target goes and the words its link uses.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const NOTICE_LINKS = [
        'in_print' => ['/in-print', 'See where to buy'],
        'notes' => ['/notes', 'Read the note'],
        'about' => ['/about', 'Read more'],
        'library' => ['/library', 'Have a look'],
        'readings' => ['/in-print#readings', 'See the readings'],
    ];

    /**
     * What the header and footer show on every page.
     *
     * @return array{email: string, line: string, links: list<array{network: string, url: string}>, library: array<string, int>}
     */
    public static function site(): array
    {
        return [
            'email' => self::email(),
            'line' => self::line(),
            'links' => SocialNetworks::links((array) SiteSetting::get('links', [])),
            'library' => LibraryCounts::get(),
        ];
    }

    /**
     * The address the footer and contact show.
     */
    public static function email(): string
    {
        return (string) (SiteSetting::get('contact')['email'] ?? 'hello@eyiwetemi.com');
    }

    /**
     * The line under the footer's links.
     */
    public static function line(): string
    {
        return (string) (SiteSetting::get('footer')['line'] ?? 'Everything here is free to read.');
    }

    /**
     * The targets a notice's link can go to.
     *
     * @return list<string>
     */
    public static function noticeTargets(): array
    {
        return array_keys(self::NOTICE_LINKS);
    }

    /**
     * The notice that is up right now, if any.
     *
     * @return array{id: int, text: string, href: string|null, go: string|null}|null
     */
    public static function notice(): ?array
    {
        $notice = Notice::query()->live()->latest('starts_at')->first();

        if ($notice === null) {
            return null;
        }

        [$href, $go] = self::NOTICE_LINKS[$notice->link_target] ?? [$notice->link_url, $notice->link_url ? 'Have a look' : null];

        return ['id' => $notice->id, 'text' => $notice->text, 'href' => $href, 'go' => $go];
    }
}
