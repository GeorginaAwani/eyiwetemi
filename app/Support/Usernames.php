<?php

namespace App\Support;

use App\Models\User;
use App\Models\UsernameHold;
use Illuminate\Support\Str;

/**
 * Usernames: a–z, 0–9, . and _; 3–20 characters; not case-sensitive. Some are kept for the author
 * (and close copies of them). One that's taken, or held for 30 days after someone left it, isn't free.
 */
class Usernames
{
    /** @var list<string> */
    public const RESERVED = ['eyiwetemi', 'georgina', 'georginaawani', 'admin', 'author', 'support'];

    /**
     * A first guess from a name: "Tolu Ade" → toluade; a long pair keeps a dot ("ifeoluwa.banjo").
     */
    public static function fromName(string $name): string
    {
        $words = self::words($name);

        if ($words === []) {
            return '';
        }

        $username = $words[0].($words[1] ?? '');

        if (strlen($username) > 12 && isset($words[1])) {
            $username = $words[0].'.'.$words[1];
        }

        return substr($username, 0, 20);
    }

    /**
     * The username to offer someone: their name's guess, or the first close one that's free.
     */
    public static function offerFor(string $name): string
    {
        $guess = self::fromName($name);

        if ($guess !== '' && self::check($guess)['ok']) {
            return $guess;
        }

        return self::suggestions($guess !== '' ? $guess : 'reader', $name)[0] ?? '';
    }

    /**
     * Whether a username can be used, why not, and a few close ones that are free.
     *
     * @return array{ok: bool, message: string, suggestions: list<string>}
     */
    public static function check(string $username, string $name = '', ?User $except = null): array
    {
        $username = Str::lower(trim($username));
        $no = fn (string $message, bool $suggest = false): array => [
            'ok' => false,
            'message' => $message,
            'suggestions' => $suggest ? self::suggestions($username, $name, $except) : [],
        ];

        if (preg_match('/[^a-z0-9._]/', $username)) {
            return $no('Letters, numbers, . and _ only.');
        }
        if (strlen($username) < 3) {
            return $no('At least 3 characters.');
        }
        if (strlen($username) > 20) {
            return $no('20 characters at most.');
        }
        if (self::isKept($username)) {
            return $no('That one’s kept for the author.', true);
        }
        if (self::isTaken($username, $except)) {
            return $no('@'.$username.' is taken.', true);
        }

        return ['ok' => true, 'message' => 'You can change it later.', 'suggestions' => []];
    }

    /**
     * Kept for the author: the reserved names and anything one letter away, ignoring dots, underscores and digits.
     */
    public static function isKept(string $username): bool
    {
        $bare = preg_replace('/[._\d]/', '', Str::lower($username));

        if (str_contains($bare, 'eyiwetemi')) {
            return true;
        }

        foreach (self::RESERVED as $reserved) {
            if (levenshtein($bare, $reserved) <= 1) {
                return true;
            }
        }

        return false;
    }

    public static function isTaken(string $username, ?User $except = null): bool
    {
        $username = Str::lower($username);

        return User::query()->where('username', $username)->when($except, fn ($query) => $query->whereKeyNot($except->id))->exists()
            || UsernameHold::query()->where('username', $username)->where('released_at', '>', now())
                ->when($except, fn ($query) => $query->where('user_id', '!=', $except->id))->exists();
    }

    /**
     * Up to three close usernames that are free.
     *
     * @return list<string>
     */
    public static function suggestions(string $username, string $name = '', ?User $except = null): array
    {
        $words = self::words($name);
        $base = self::fromName($name);
        $initial = isset($words[1]) ? $words[1][0] : '';
        $candidates = array_unique(array_filter([
            $base,
            $initial !== '' ? $username.'.'.$initial : $username.'.reads',
            $username.'_',
            $username.'.reads',
            $base !== '' ? $base.'_' : '',
            $username.'.'.random_int(10, 99),
        ]));

        $free = [];
        foreach ($candidates as $candidate) {
            if ($candidate !== $username && preg_match('/^[a-z0-9._]{3,20}$/', $candidate)
                && ! self::isKept($candidate) && ! self::isTaken($candidate, $except)) {
                $free[] = $candidate;
            }
        }

        return array_slice($free, 0, 3);
    }

    /**
     * A name as plain lowercase words: accents dropped, hyphens split ("Bánjọ-Oyèwọ̀lé" → banjo, oyewole).
     *
     * @return list<string>
     */
    private static function words(string $name): array
    {
        $plain = preg_replace('/[^a-z0-9\s-]/', '', Str::lower(Str::ascii($name)));

        return array_values(array_filter(preg_split('/[\s-]+/', (string) $plain) ?: []));
    }
}
