<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * The six reviewers who have accepted an invite. The other two prototype reviewers are invites (see ReviewerSeeder).
     *
     * @var list<array{name: string, username: string, email: string}>
     */
    private const REVIEWERS = [
        ['name' => 'Adaeze Obi', 'username' => 'adaeze.obi', 'email' => 'adaeze.obi@example.com'],
        ['name' => 'Kemi Bello', 'username' => 'kemi.bello', 'email' => 'kemi.bello@example.com'],
        ['name' => 'Ruth Okon', 'username' => 'ruth.okon', 'email' => 'ruthokon@example.com'],
        ['name' => 'Dayo Bankole', 'username' => 'dayo.bankole', 'email' => 'dayo@example.com'],
        ['name' => 'Chioma Eze', 'username' => 'chioma.eze', 'email' => 'chioma.eze@example.com'],
        ['name' => 'Ifeanyi Okafor', 'username' => 'ifeanyi.okafor', 'email' => 'ifeanyi.okafor@example.com'],
    ];

    /**
     * Reader accounts: the signed-in prototype reader, then one account for each named commenter in the prototype.
     *
     * @var list<array{name: string, username: string}>
     */
    private const READERS = [
        ['name' => 'Adaeze Okafor', 'username' => 'adaeze'],
        ['name' => 'Tolu Ade', 'username' => 'tolu.ade'],
        ['name' => 'kemi.writes', 'username' => 'kemi.writes'],
        ['name' => 'Ife', 'username' => 'ife'],
        ['name' => 'Chiamaka', 'username' => 'chiamaka'],
        ['name' => 'Funmi', 'username' => 'funmi'],
        ['name' => 'Tari', 'username' => 'tari'],
        ['name' => 'Lola', 'username' => 'lola'],
        ['name' => 'Ngozi', 'username' => 'ngozi'],
        ['name' => 'Ebi', 'username' => 'ebi'],
        ['name' => 'anon_reader22', 'username' => 'anon_reader22'],
        ['name' => 'Amara', 'username' => 'amara'],
        ['name' => 'Kelechi', 'username' => 'kelechi'],
        ['name' => 'Bisi', 'username' => 'bisi'],
        ['name' => 'Osaro', 'username' => 'osaro'],
        ['name' => 'Dami', 'username' => 'dami'],
        ['name' => 'Yewande', 'username' => 'yewande'],
        ['name' => 'Ejiro', 'username' => 'ejiro'],
        ['name' => 'Chuka', 'username' => 'chuka'],
        ['name' => 'Halima', 'username' => 'halima'],
        ['name' => 'Kunle', 'username' => 'kunle'],
        ['name' => 'Tamuno', 'username' => 'tamuno'],
        ['name' => 'Daniel', 'username' => 'daniel'],
        ['name' => 'Emeka', 'username' => 'emeka'],
    ];

    public function run(): void
    {
        $verifiedAt = now();

        User::forceCreate([
            'name' => 'Georgina Awani',
            'username' => 'eyiwetemi',
            'email' => 'me@eyiwetemi.com',
            'password' => env('SEED_AUTHOR_PASSWORD', 'password'),
            'role' => UserRole::Author,
            'avatar_seed' => $this->avatarSeed('eyiwetemi'),
            'email_verified_at' => $verifiedAt,
            'preferences' => ['theme' => 'night'],
        ]);

        foreach (self::REVIEWERS as $reviewer) {
            User::forceCreate([
                'name' => $reviewer['name'],
                'username' => $reviewer['username'],
                'email' => $reviewer['email'],
                'password' => 'password',
                'role' => UserRole::Reviewer,
                'avatar_seed' => $this->avatarSeed($reviewer['username']),
                'email_verified_at' => $verifiedAt,
            ]);
        }

        foreach (self::READERS as $index => $reader) {
            User::forceCreate([
                'name' => $reader['name'],
                'username' => $reader['username'],
                'email' => $reader['username'].'@example.com',
                'password' => 'password',
                'role' => UserRole::Reader,
                'avatar_seed' => $this->avatarSeed($reader['username']),
                'email_verified_at' => $verifiedAt,
                'preferences' => $index === 0
                    ? ['theme' => 'white', 'reading' => ['size' => 2, 'font' => 'serif', 'width' => 'narrow', 'lines' => 'airy', 'sound' => 'off']]
                    : null,
            ]);
        }
    }

    private function avatarSeed(string $username): string
    {
        return substr(md5($username), 0, 12);
    }
}
