<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UsernameHold;
use App\Support\Usernames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_account_is_made_and_signed_in(): void
    {
        $this->postJson('/register', [
            'name' => 'Tolu Ade',
            'username' => 'ToluAde',
            'email' => 'Tolu@Example.com',
            'password' => 'paper boats',
        ])->assertOk()->assertExactJson(['redirect' => null]);

        $user = User::query()->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('toluade', $user->username);
        $this->assertSame('tolu@example.com', $user->email);
        $this->assertNotEmpty($user->avatar_seed);
    }

    public function test_the_fields_say_what_is_wrong(): void
    {
        User::factory()->create(['email' => 'ada@example.com', 'username' => 'ada']);

        $this->postJson('/register', ['name' => '', 'username' => 'ada', 'email' => 'ada@example.com', 'password' => 'short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name' => 'Type your name.',
                'username' => '@ada is taken.',
                'email' => 'There’s already an account with this email.',
                'password' => '8 or more characters.',
            ]);

        $this->assertGuest();
    }

    public function test_the_live_check_explains_and_offers_close_ones_that_are_free(): void
    {
        User::factory()->create(['username' => 'toluade']);
        UsernameHold::factory()->create(['username' => 'kemi']);
        UsernameHold::factory()->released()->create(['username' => 'bisi']);

        $this->getJson('/username-check?u=Tolu.Reads&name=Tolu Ade')->assertOk()->assertJson(['ok' => true, 'message' => 'You can change it later.']);
        $this->getJson('/username-check?u=bisi')->assertJson(['ok' => true]);

        $taken = $this->getJson('/username-check?u=toluade&name=Tolu Ade')->assertJson(['ok' => false, 'message' => '@toluade is taken.']);
        $this->assertContains('toluade.a', $taken->json('suggestions'));
        $this->assertNotContains('toluade', $taken->json('suggestions'));

        $this->getJson('/username-check?u=kemi')->assertJson(['ok' => false, 'message' => '@kemi is taken.']);
        $this->getJson('/username-check?u=Ge0rgina_')->assertJson(['ok' => false, 'message' => 'That one’s kept for the author.']);
        $this->getJson('/username-check?u=the.eyiwetemi.fan')->assertJson(['ok' => false, 'message' => 'That one’s kept for the author.']);
        $this->getJson('/username-check?u=tolu ade')->assertJson(['ok' => false, 'message' => 'Letters, numbers, . and _ only.', 'suggestions' => []]);
        $this->getJson('/username-check?u=to')->assertJson(['ok' => false, 'message' => 'At least 3 characters.']);
        $this->getJson('/username-check?u='.str_repeat('a', 21))->assertJson(['ok' => false, 'message' => '20 characters at most.']);
    }

    public function test_a_name_becomes_a_first_guess_at_a_username(): void
    {
        $this->assertSame('toluade', Usernames::fromName('Tolu Ade'));
        $this->assertSame('ifeoluwa.banjo', Usernames::fromName('Ifẹ́olúwa Bánjọ-Oyèwọ̀lé'));
        $this->assertSame('', Usernames::fromName('  '));

        User::factory()->create(['username' => 'kemibello']);
        $this->assertSame('kemibello.b', Usernames::offerFor('Kemi Bello'));
    }
}
