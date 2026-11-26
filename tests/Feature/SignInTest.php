<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SignInTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_right_password_signs_in_and_stays_on_the_page(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/login', ['email' => 'Ada@Example.com ', 'password' => 'password', 'remember' => true])
            ->assertOk()
            ->assertExactJson(['redirect' => null]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_each_failure_says_what_is_wrong(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);
        User::factory()->googleOnly()->create(['email' => 'tolu@example.com']);

        $this->postJson('/login', ['email' => 'nobody@example.com', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'No account uses this email.']);

        $this->postJson('/login', ['email' => 'tolu@example.com', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password' => 'This account uses Google.']);

        $this->postJson('/login', ['email' => 'ada@example.com', 'password' => 'sunflower'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password' => 'That password doesn’t match.']);

        $this->assertGuest();
    }

    public function test_wrong_passwords_are_throttled(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        foreach (range(1, 5) as $try) {
            $this->postJson('/login', ['email' => 'ada@example.com', 'password' => 'wrong'.$try])->assertUnprocessable();
        }

        $this->postJson('/login', ['email' => 'ada@example.com', 'password' => 'password'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_a_private_page_opens_the_card_on_home_and_signing_in_carries_on_there(): void
    {
        Route::middleware(['web', 'auth'])->get('/private-test', fn () => 'yours');
        User::factory()->create(['email' => 'ada@example.com']);

        $this->get('/private-test')->assertRedirect('/');
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('signin.why', 'Sign in to see your profile'));

        $this->postJson('/login', ['email' => 'ada@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertExactJson(['redirect' => url('/private-test')]);
    }

    public function test_pages_share_only_what_the_header_needs_about_you(): void
    {
        $user = User::factory()->create(['name' => 'Adaeze Okafor', 'username' => 'adaeze']);

        $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('auth.user', [
                'id' => $user->id,
                'name' => 'Adaeze Okafor',
                'username' => 'adaeze',
                'email' => $user->email,
                'avatar_seed' => $user->avatar_seed,
                'reading' => ['size' => 2, 'font' => 'serif', 'width' => 'narrow', 'lines' => 'airy', 'theme' => 'white'],
                'unread' => 0,
            ]));
    }

    public function test_signing_out_goes_home(): void
    {
        $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    }
}
