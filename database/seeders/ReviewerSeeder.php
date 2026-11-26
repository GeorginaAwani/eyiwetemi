<?php

namespace Database\Seeders;

use App\Models\ReviewerInvite;
use App\Models\User;
use App\Models\Work;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ReviewerSeeder extends Seeder
{
    /**
     * The prototype's eight reviewers. The six who have accepted are user accounts (UserSeeder) with a work list;
     * the two still invited (pending and expired) are invites, because an invite has no account until it is accepted.
     */
    public function run(): void
    {
        $author = User::query()->where('username', 'eyiwetemi')->firstOrFail();
        $works = Work::query()->get()->keyBy('slug');
        $usersByEmail = User::query()->where('role', 'reviewer')->get()->keyBy('email');

        foreach (SeedData::PEOPLE_PSEED as $person) {
            $email = $this->example($person['e']);
            $worksForPerson = collect($person['ws'])->map(fn (string $slug): int => $works[$slug]->id)->all();

            if ($person['st'] === 'on') {
                $usersByEmail[$email]->reviewableWorks()->attach($worksForPerson);

                continue;
            }

            $invite = ReviewerInvite::forceCreate([
                'email' => $email,
                'name' => $person['n'],
                'note' => ($person['note'] ?? '') !== '' ? $person['note'] : null,
                'token_hash' => hash('sha256', Str::random(64)),
                'invited_by' => $author->id,
                'expires_at' => $person['st'] === 'pend'
                    ? now()->addDays($person['days'])
                    : now()->subDay(),
            ]);

            $invite->works()->attach($worksForPerson);
        }
    }

    /**
     * The prototype's addresses on the example.com domain.
     */
    private function example(string $email): string
    {
        return Str::before($email, '@').'@example.com';
    }
}
