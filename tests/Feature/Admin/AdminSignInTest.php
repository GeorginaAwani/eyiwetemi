<?php

namespace Tests\Feature\Admin;

use App\Models\LoginLink;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\Admin\NewSignIn;
use App\Notifications\Admin\ResetPassword;
use App\Notifications\Admin\SignInLink;
use App\Notifications\Admin\SignInPaused;
use App\Support\AdminSignIn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Fortify;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AdminSignInTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->author = User::factory()->author()->create(['email' => 'me@eyiwetemi.com', 'password' => 'right-password']);
        $this->author->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt(self::SECRET),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['backup-one', 'backup-two'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    public function test_admin_pages_send_a_guest_to_the_sign_in(): void
    {
        $this->get(route('admin.works.index'))->assertRedirect(route('admin.signin'));

        $this->get(route('admin.signin'))->assertOk()->assertInertia(fn ($page) => $page->component('Admin/SignIn')->where('step', 'email'));
    }

    public function test_a_reader_signed_in_on_the_site_is_not_let_into_the_admin(): void
    {
        $reader = User::factory()->reader()->create();

        $this->actingAs($reader)->get(route('admin.desk'))->assertRedirect(route('admin.signin'));
    }

    public function test_the_author_without_the_admin_sign_in_is_not_let_in(): void
    {
        $this->actingAs($this->author)->get(route('admin.desk'))->assertRedirect(route('admin.signin'));
    }

    public function test_the_reading_sites_pages_do_not_answer_on_the_admin_host(): void
    {
        $this->get('http://'.config('eyiwetemi.admin_domain').'/about')->assertNotFound();
        $this->post('http://'.config('eyiwetemi.admin_domain').'/login', ['email' => 'me@eyiwetemi.com', 'password' => 'right-password'])->assertNotFound();
    }

    public function test_the_admin_does_not_answer_on_the_reading_site(): void
    {
        $this->get('/sign-in')->assertNotFound();
    }

    public function test_the_right_password_on_a_new_device_asks_for_the_code(): void
    {
        $this->postJson(route('admin.signin.password'), ['email' => 'Me@Eyiwetemi.com ', 'password' => 'right-password'])
            ->assertOk()
            ->assertExactJson(['next' => 'code']);

        $this->assertGuest();
    }

    public function test_the_right_code_signs_in_remembers_the_device_and_emails_a_new_sign_in(): void
    {
        $this->passwordStep();

        $response = $this->postJson(route('admin.signin.code'), ['code' => $this->currentCode(), 'remember' => true])
            ->assertOk()
            ->assertJson(['redirect' => route('admin.desk')]);

        $this->assertAuthenticatedAs($this->author);
        $response->assertCookie(AdminSignIn::DEVICE_COOKIE);
        $this->assertSame(1, TrustedDevice::query()->where('user_id', $this->author->id)->count());
        Notification::assertSentTo($this->author, NewSignIn::class);
        $this->get(route('admin.desk'))->assertOk()->assertInertia(fn ($page) => $page->component('Admin/Desk')->has('lead')->has('writingNow'));
    }

    public function test_without_remember_no_device_is_kept(): void
    {
        $this->passwordStep();

        $this->postJson(route('admin.signin.code'), ['code' => $this->currentCode(), 'remember' => false])
            ->assertOk()
            ->assertCookieMissing(AdminSignIn::DEVICE_COOKIE);

        $this->assertSame(0, TrustedDevice::query()->count());
    }

    public function test_a_remembered_device_skips_the_code_and_sends_no_new_sign_in_email(): void
    {
        $token = str_repeat('a', 64);
        $this->author->trustedDevices()->create(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(10)]);

        $this->withCredentials()->withCookie(AdminSignIn::DEVICE_COOKIE, $token)
            ->postJson(route('admin.signin.password'), ['email' => 'me@eyiwetemi.com', 'password' => 'right-password'])
            ->assertOk()
            ->assertJson(['redirect' => route('admin.desk')]);

        $this->assertAuthenticatedAs($this->author);
        Notification::assertNotSentTo($this->author, NewSignIn::class);
    }

    public function test_an_expired_device_asks_for_the_code_again(): void
    {
        $token = str_repeat('b', 64);
        $this->author->trustedDevices()->create(['token_hash' => hash('sha256', $token), 'expires_at' => now()->subMinute()]);

        $this->withCredentials()->withCookie(AdminSignIn::DEVICE_COOKIE, $token)
            ->postJson(route('admin.signin.password'), ['email' => 'me@eyiwetemi.com', 'password' => 'right-password'])
            ->assertExactJson(['next' => 'code']);
    }

    public function test_a_wrong_password_counts_down_once_three_tries_are_left(): void
    {
        $this->wrongPassword()->assertStatus(422)->assertJson(['message' => 'That password isn’t right.']);
        $this->wrongPassword()->assertJson(['message' => 'That password isn’t right. 3 tries left.']);
        $this->wrongPassword()->assertJson(['message' => 'That password isn’t right. 2 tries left.']);
        $this->wrongPassword()->assertJson(['message' => 'That password isn’t right. 1 try left.']);
    }

    public function test_the_fifth_wrong_try_pauses_sign_in_for_fifteen_minutes_and_emails_her(): void
    {
        foreach (range(1, 4) as $try) {
            $this->wrongPassword();
        }

        $this->wrongPassword()->assertStatus(423)->assertJson(['pausedFor' => 900]);
        Notification::assertSentTo($this->author, SignInPaused::class);

        $this->postJson(route('admin.signin.password'), ['email' => 'me@eyiwetemi.com', 'password' => 'right-password'])->assertStatus(423);
        $this->postJson(route('admin.signin.email'), ['email' => 'me@eyiwetemi.com'])->assertJson(['pausedFor' => 900]);

        $this->travel(16)->minutes();
        $this->postJson(route('admin.signin.password'), ['email' => 'me@eyiwetemi.com', 'password' => 'right-password'])->assertExactJson(['next' => 'code']);
    }

    public function test_a_right_password_between_wrong_codes_does_not_reset_the_count(): void
    {
        foreach (range(1, 2) as $round) {
            $this->passwordStep();
            $this->postJson(route('admin.signin.code'), ['code' => $this->wrongCode()])->assertStatus(422);
            $this->postJson(route('admin.signin.code'), ['code' => $this->wrongCode()])->assertStatus(422);
        }

        $this->passwordStep();
        $this->postJson(route('admin.signin.code'), ['code' => $this->wrongCode()])->assertStatus(423);
        Notification::assertSentTo($this->author, SignInPaused::class);
    }

    public function test_a_new_password_ends_her_other_sessions_and_remembered_devices(): void
    {
        config(['session.driver' => 'database']);
        TrustedDevice::query()->create(['user_id' => $this->author->id, 'token_hash' => hash('sha256', 'old-device'), 'label' => 'Laptop', 'user_agent' => 'x', 'last_used_at' => now(), 'expires_at' => now()->addDays(30)]);
        DB::table('sessions')->insert(['id' => 'stolen-session', 'user_id' => $this->author->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);
        $token = Password::broker()->createToken($this->author);

        $this->get(route('admin.password.open', ['token' => $token, 'email' => 'me@eyiwetemi.com']));
        $this->postJson(route('admin.signin.reset'), ['password' => 'a-brand-new-one'])->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'stolen-session']);
        $this->assertSame(0, $this->author->trustedDevices()->count());
    }

    public function test_an_email_that_is_not_hers_gets_the_same_answer_and_never_signs_in(): void
    {
        $reader = User::factory()->reader()->create(['email' => 'reader@example.com', 'password' => 'right-password']);

        $this->postJson(route('admin.signin.password'), ['email' => 'reader@example.com', 'password' => 'right-password'])
            ->assertStatus(422)
            ->assertJson(['message' => 'That password isn’t right.']);

        $this->assertGuest();
        Notification::assertNothingSentTo($reader);
    }

    public function test_the_email_step_checks_the_address(): void
    {
        $this->postJson(route('admin.signin.email'), ['email' => 'not an email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => 'That doesn’t look like an email.']);
    }

    public function test_a_wrong_code_is_refused_and_counts_as_a_try(): void
    {
        $this->passwordStep();

        $this->postJson(route('admin.signin.code'), ['code' => $this->wrongCode()])
            ->assertStatus(422)
            ->assertJson(['message' => 'That code isn’t right. Try the newest one.']);

        $this->assertGuest();
        $this->assertSame(1, cache('admin-signin-tries|me@eyiwetemi.com'));
    }

    public function test_the_code_needs_the_first_step_first(): void
    {
        $this->postJson(route('admin.signin.code'), ['code' => $this->currentCode()])->assertStatus(409)->assertJson(['expired' => true]);

        $this->assertGuest();
    }

    public function test_the_code_step_times_out_after_ten_minutes(): void
    {
        $this->passwordStep();
        $this->travel(11)->minutes();

        $this->postJson(route('admin.signin.code'), ['code' => $this->currentCode()])->assertStatus(409);
    }

    public function test_a_backup_code_signs_in_once(): void
    {
        $this->passwordStep();
        $this->postJson(route('admin.signin.backup'), ['code' => 'backup-one'])->assertOk()->assertJson(['redirect' => route('admin.desk')]);
        $this->assertAuthenticatedAs($this->author);

        $this->post(route('admin.signout'));
        $this->passwordStep();
        $this->postJson(route('admin.signin.backup'), ['code' => 'backup-one'])
            ->assertStatus(422)
            ->assertJson(['message' => 'That backup code isn’t right.']);
    }

    public function test_the_sign_in_link_is_emailed_only_to_her(): void
    {
        $reader = User::factory()->reader()->create(['email' => 'reader@example.com']);

        $this->postJson(route('admin.signin.link'), ['email' => 'reader@example.com'])->assertOk()->assertJson(['sent' => true]);
        $this->postJson(route('admin.signin.link'), ['email' => 'me@eyiwetemi.com'])->assertOk()->assertJson(['sent' => true]);

        Notification::assertNothingSentTo($reader);
        Notification::assertSentTo($this->author, SignInLink::class);
        $this->assertSame(1, LoginLink::query()->count());
    }

    public function test_the_sign_in_link_opens_the_code_step_once(): void
    {
        $token = $this->emailedLinkToken();

        $this->get(route('admin.link.open', $token))->assertRedirect(route('admin.signin'));
        $this->get(route('admin.signin'))->assertInertia(fn ($page) => $page->where('step', 'code'));
        $this->postJson(route('admin.signin.code'), ['code' => $this->currentCode()])->assertOk();
        $this->assertAuthenticatedAs($this->author);

        $this->post(route('admin.signout'));
        $this->get(route('admin.link.open', $token))->assertRedirect(route('admin.signin'))->assertSessionHas('admin.error', 'That link doesn’t work any more.');
    }

    public function test_an_old_sign_in_link_does_not_work(): void
    {
        $token = $this->emailedLinkToken();
        $this->travel(16)->minutes();

        $this->get(route('admin.link.open', $token))->assertSessionHas('admin.error', 'That link doesn’t work any more.');
        $this->assertNull(session('admin.pending'));
    }

    public function test_forgot_it_emails_a_reset_link_and_the_new_password_leads_to_the_code(): void
    {
        $this->postJson(route('admin.signin.forgot'), ['email' => 'me@eyiwetemi.com'])->assertOk();

        $token = null;
        Notification::assertSentTo($this->author, ResetPassword::class, function (ResetPassword $mail) use (&$token): bool {
            $token = $mail->token;

            return true;
        });

        $this->get(route('admin.password.open', ['token' => $token, 'email' => 'me@eyiwetemi.com']))->assertRedirect(route('admin.signin'));
        $this->get(route('admin.signin'))->assertInertia(fn ($page) => $page->where('step', 'reset'));

        $this->postJson(route('admin.signin.reset'), ['password' => 'short'])->assertStatus(422)->assertJsonValidationErrors(['password' => '8 or more characters.']);
        $this->postJson(route('admin.signin.reset'), ['password' => 'a-brand-new-one'])->assertOk()->assertExactJson(['next' => 'code']);

        $this->assertTrue(Hash::check('a-brand-new-one', $this->author->fresh()->password));
        $this->assertGuest();
    }

    public function test_a_wrong_reset_token_changes_nothing(): void
    {
        Password::broker()->createToken($this->author);

        $this->get(route('admin.password.open', ['token' => 'made-up', 'email' => 'me@eyiwetemi.com']));
        $this->postJson(route('admin.signin.reset'), ['password' => 'a-brand-new-one'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password' => 'That link doesn’t work any more.']);

        $this->assertTrue(Hash::check('right-password', $this->author->fresh()->password));
    }

    public function test_her_google_account_leads_to_the_code(): void
    {
        $this->author->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'g-1']);
        $this->fakeGoogle('g-1', 'me@eyiwetemi.com');

        $this->get(route('admin.google.callback'))->assertRedirect(route('admin.signin'));
        $this->assertSame($this->author->id, session('admin.pending.user_id'));
    }

    public function test_another_google_account_cannot_sign_in(): void
    {
        $this->fakeGoogle('g-2', 'someone@example.com');

        $this->get(route('admin.google.callback'))
            ->assertRedirect(route('admin.signin'))
            ->assertSessionHas('admin.error', 'That Google account can’t sign in here.');
        $this->assertNull(session('admin.pending'));
    }

    public function test_forget_this_device_drops_the_remembered_device(): void
    {
        $token = str_repeat('c', 64);
        $this->author->trustedDevices()->create(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(10)]);
        $this->withCredentials()->withCookie(AdminSignIn::DEVICE_COOKIE, $token)
            ->postJson(route('admin.signin.password'), ['email' => 'me@eyiwetemi.com', 'password' => 'right-password']);

        $this->withCredentials()->withCookie(AdminSignIn::DEVICE_COOKIE, $token)->postJson(route('admin.device.forget'))->assertOk();

        $this->assertSame(0, TrustedDevice::query()->count());
        $this->assertAuthenticatedAs($this->author);
    }

    public function test_sign_out_leaves_the_admin(): void
    {
        $this->passwordStep();
        $this->postJson(route('admin.signin.code'), ['code' => $this->currentCode()]);

        $this->post(route('admin.signout'))->assertRedirect(route('admin.signin'));

        $this->assertGuest();
        $this->get(route('admin.desk'))->assertRedirect(route('admin.signin'));
    }

    private function passwordStep(): void
    {
        $this->postJson(route('admin.signin.password'), ['email' => 'me@eyiwetemi.com', 'password' => 'right-password'])->assertExactJson(['next' => 'code']);
    }

    private function wrongPassword(): TestResponse
    {
        return $this->postJson(route('admin.signin.password'), ['email' => 'me@eyiwetemi.com', 'password' => 'not-it']);
    }

    private function currentCode(): string
    {
        return (new Google2FA)->getCurrentOtp(self::SECRET);
    }

    private function wrongCode(): string
    {
        return $this->currentCode() === '000000' ? '111111' : '000000';
    }

    private function emailedLinkToken(): string
    {
        $this->postJson(route('admin.signin.link'), ['email' => 'me@eyiwetemi.com']);

        $url = null;
        Notification::assertSentTo($this->author, SignInLink::class, function (SignInLink $mail) use (&$url): bool {
            $url = $mail->url;

            return true;
        });

        return basename(parse_url($url, PHP_URL_PATH));
    }

    private function fakeGoogle(string $id, string $email): void
    {
        $google = (new GoogleUser)->map(['id' => $id, 'name' => 'Someone', 'email' => $email]);
        $google->user = ['email_verified' => true];
        $provider = Mockery::mock();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($google);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }
}
