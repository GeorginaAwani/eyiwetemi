<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret']);
    }

    private function googleReturns(string $id, string $name, string $email, bool $verified = true): void
    {
        $google = (new GoogleUser)->setRaw(['email_verified' => $verified])->map(['id' => $id, 'name' => $name, 'email' => $email]);
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn($google);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_it_remembers_the_page_and_goes_to_google(): void
    {
        $provider = Mockery::mock();
        $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/auth/google?back=/library/ember-gate')->assertRedirect('https://accounts.google.com/o/oauth2/auth');
        $this->assertSame('/library/ember-gate', session('google.back'));

        $this->get('/auth/google?back=//elsewhere.example');
        $this->assertSame('/', session('google.back'));
    }

    public function test_a_known_google_account_signs_straight_in_and_returns_to_the_page(): void
    {
        $user = User::factory()->googleOnly()->create();
        SocialAccount::factory()->for($user)->create(['provider' => 'google', 'provider_user_id' => 'g-1']);
        $this->googleReturns('g-1', 'Adaeze Okafor', $user->email);

        $this->withSession(['google.back' => '/notes'])->get('/auth/google/callback')->assertRedirect('/notes');

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_password_nobody_proved_stops_working_once_google_proves_the_address(): void
    {
        $squatter = User::factory()->create(['email' => 'ada@example.com', 'password' => 'squatter-pass', 'email_verified_at' => null]);
        $this->googleReturns('g-9', 'Ada', 'ada@example.com');

        $this->get('/auth/google/callback');

        $this->assertAuthenticatedAs($squatter);
        $this->assertNull($squatter->fresh()->password);
        $this->assertNotNull($squatter->fresh()->email_verified_at);
    }

    public function test_an_address_google_has_not_proved_is_not_marked_proved(): void
    {
        $this->googleReturns('g-8', 'Ife', 'ife@example.com', verified: false);
        $this->get('/auth/google/callback');

        $this->postJson('/auth/google/finish', ['username' => 'ife'])->assertOk();

        $this->assertNull(User::query()->where('email', 'ife@example.com')->value('email_verified_at'));
    }

    public function test_a_verified_email_we_know_is_linked_to_google(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $this->googleReturns('g-2', 'Ada', 'Ada@Example.com');

        $this->get('/auth/google/callback')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-2']);
    }

    public function test_a_new_google_account_picks_a_username_then_is_made(): void
    {
        User::factory()->create(['username' => 'kemibello']);
        $this->googleReturns('g-3', 'Kemi Bello', 'kemi.bello@gmail.com');

        $this->withSession(['google.back' => '/library'])->get('/auth/google/callback')->assertRedirect('/library');
        $this->assertGuest();
        $this->get('/library')->assertInertia(fn (Assert $page) => $page
            ->where('signin.view', 'google')
            ->where('signin.google', ['name' => 'Kemi Bello', 'email' => 'kemi.bello@gmail.com', 'username' => 'kemibello.b']));

        $this->postJson('/auth/google/finish', ['username' => 'kemibello'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username' => '@kemibello is taken.']);

        $this->postJson('/auth/google/finish', ['username' => 'Kemi.Reads'])->assertOk()->assertExactJson(['redirect' => '/library']);

        $kemi = User::query()->where('email', 'kemi.bello@gmail.com')->sole();
        $this->assertAuthenticatedAs($kemi);
        $this->assertSame('kemi.reads', $kemi->username);
        $this->assertNull($kemi->password);
        $this->assertDatabaseHas('social_accounts', ['user_id' => $kemi->id, 'provider_user_id' => 'g-3']);
    }

    public function test_finishing_without_coming_from_google_is_refused(): void
    {
        $this->postJson('/auth/google/finish', ['username' => 'someone'])->assertStatus(419);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_without_keys_the_card_says_google_is_not_set_up(): void
    {
        config(['services.google.client_id' => null]);

        $this->get('/auth/google?back=/library')->assertRedirect('/library');
        $this->get('/library')->assertInertia(fn (Assert $page) => $page->where('signin.error', 'Google sign-in isn’t set up yet.'));
    }
}
