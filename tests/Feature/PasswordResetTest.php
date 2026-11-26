<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_emails_a_link_that_opens_the_new_password_card(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/forgot-password', ['email' => 'ada@example.com'])->assertOk();

        $sent = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$sent, $user): bool {
            $sent = $notification;

            return str_contains($notification->toMail($user)->render(), 'Choose a new password');
        });

        $this->get('/reset-password/'.$sent->token.'?email=ada@example.com')->assertRedirect('/');
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('signin.view', 'reset')
            ->where('signin.reset', ['token' => $sent->token, 'email' => 'ada@example.com']));
    }

    public function test_an_unknown_email_is_told_so(): void
    {
        $this->postJson('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'No account uses this email.']);
    }

    public function test_a_new_password_works_and_an_old_link_does_not(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $token = Password::createToken($user);

        $this->postJson('/reset-password', ['token' => $token, 'email' => 'ada@example.com', 'password' => 'short'])
            ->assertJsonValidationErrors(['password' => '8 or more characters.']);

        $this->postJson('/reset-password', ['token' => $token, 'email' => 'ada@example.com', 'password' => 'rainy midnight'])->assertOk();

        $this->postJson('/reset-password', ['token' => $token, 'email' => 'ada@example.com', 'password' => 'another one'])
            ->assertJsonValidationErrors(['email' => 'This link has expired.']);

        $this->postJson('/login', ['email' => 'ada@example.com', 'password' => 'rainy midnight'])->assertOk();
    }
}
