<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Notifications\Admin\NewSignIn;
use App\Notifications\Admin\ResetPassword;
use App\Notifications\Admin\SignInLink;
use App\Notifications\Admin\SignInPaused;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

class AdminMailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sign_in_link_mail_puts_the_link_on_its_button(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $url = 'https://admin.eyiwetemi.test/link/abc123';

        $mail = (new SignInLink($url))->toMail($user);

        $this->assertSame('Your sign-in link', $mail->subject);
        $this->assertStringContainsString('Sign in to the admin', $this->html($mail));
        $this->assertStringContainsString('It works once, for 15 minutes.', $this->html($mail));
        $this->assertStringContainsString('href="'.e($url).'"', $this->html($mail));
        $this->assertStringContainsString($url, $this->text($mail));
    }

    public function test_the_paused_mail_gives_the_end_of_the_pause_in_west_africa_time(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $mail = (new SignInPaused(Carbon::parse('2026-11-26 17:40:00', 'UTC')))->toMail($user);

        $this->assertSame('Admin sign-in paused', $mail->subject);
        $this->assertStringContainsString('Sign-in paused', $this->html($mail));
        $this->assertStringContainsString('paused until 18:40 WAT.', $this->html($mail));
        $this->assertStringContainsString('If it wasn’t you, change your password.', $this->html($mail));
        $this->assertStringNotContainsString('<a href', $this->html($mail));
        $this->assertStringContainsString('paused until 18:40 WAT.', $this->text($mail));
    }

    public function test_the_new_sign_in_mail_names_the_device_and_the_time_in_west_africa_time(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $mail = (new NewSignIn('Edge on Windows', Carbon::parse('2026-11-26 17:40:00', 'UTC')))->toMail($user);

        $this->assertSame('New sign-in to the admin', $mail->subject);
        $this->assertStringContainsString('Edge on Windows signed in to the admin on Thu 26 Nov at 18:40 WAT.', $this->html($mail));
        $this->assertStringContainsString('sign out everywhere.', $this->html($mail));
        $this->assertStringNotContainsString('<a href', $this->html($mail));
        $this->assertStringContainsString('Edge on Windows signed in to the admin on Thu 26 Nov at 18:40 WAT.', $this->text($mail));
    }

    public function test_the_reset_mail_button_opens_the_admin_reset_page(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $url = route('admin.password.open', ['token' => 'reset-token', 'email' => 'ada@example.com']);

        $mail = (new ResetPassword('reset-token', 'ada@example.com'))->toMail($user);

        $this->assertSame('Choose a new password', $mail->subject);
        $this->assertStringContainsString('It works for an hour.', $this->html($mail));
        $this->assertStringContainsString('href="'.e($url).'"', $this->html($mail));
        $this->assertStringContainsString($url, $this->text($mail));
    }

    public function test_the_admin_mails_are_queued_and_sent_by_mail(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $notifications = [
            new SignInLink('https://admin.eyiwetemi.test/link/abc123'),
            new SignInPaused(Carbon::parse('2026-11-26 17:40:00', 'UTC')),
            new NewSignIn('Edge on Windows', Carbon::parse('2026-11-26 17:40:00', 'UTC')),
            new ResetPassword('reset-token', 'ada@example.com'),
        ];

        foreach ($notifications as $notification) {
            $this->assertInstanceOf(ShouldQueue::class, $notification);
            $this->assertSame(['mail'], $notification->via($user));
        }
    }

    private function html(MailMessage $mail): string
    {
        return view($mail->view[0], $mail->viewData)->render();
    }

    private function text(MailMessage $mail): string
    {
        return view($mail->view[1], $mail->viewData)->render();
    }
}
