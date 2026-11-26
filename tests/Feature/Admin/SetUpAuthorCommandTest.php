<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\TwoFactorAuthenticationProvider;
use Mockery;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class SetUpAuthorCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private const PASSWORD_QUESTION = 'Password (at least 8 characters)';

    private const AGAIN_QUESTION = 'Type the password again';

    private const CODE_QUESTION = '6-digit code from the app';

    protected function setUp(): void
    {
        parent::setUp();

        $provider = Mockery::mock(TwoFactorAuthenticationProvider::class, [new Google2FA])->makePartial();
        $provider->shouldReceive('generateSecretKey')->andReturn(self::SECRET);
        $this->app->instance(TwoFactorAuthenticationProvider::class, $provider);
    }

    public function test_the_author_is_confirmed_with_the_authenticator_when_the_right_code_is_typed(): void
    {
        $this->artisan('eyiwetemi:author', ['email' => 'Ada@Example.com', '--name' => 'Ada Okafor'])
            ->expectsQuestion(self::PASSWORD_QUESTION, 'sunflower')
            ->expectsQuestion(self::AGAIN_QUESTION, 'sunflower')
            ->expectsQuestion(self::CODE_QUESTION, (new Google2FA)->getCurrentOtp(self::SECRET))
            ->assertSuccessful();

        $author = User::query()->where('role', UserRole::Author)->sole();

        $this->assertSame('ada@example.com', $author->email);
        $this->assertTrue(Hash::check('sunflower', $author->password));
        $this->assertNotNull($author->two_factor_confirmed_at);
        $this->assertCount(8, $author->recoveryCodes());
    }

    public function test_mismatched_passwords_stop_the_command_and_nothing_is_saved(): void
    {
        $this->artisan('eyiwetemi:author', ['email' => 'ada@example.com', '--name' => 'Ada Okafor'])
            ->expectsQuestion(self::PASSWORD_QUESTION, 'sunflower')
            ->expectsQuestion(self::AGAIN_QUESTION, 'sunflowers')
            ->assertFailed();

        $this->assertFalse(User::query()->where('role', UserRole::Author)->exists());
    }

    public function test_three_wrong_codes_leave_the_authenticator_off(): void
    {
        $this->artisan('eyiwetemi:author', ['email' => 'ada@example.com', '--name' => 'Ada Okafor'])
            ->expectsQuestion(self::PASSWORD_QUESTION, 'sunflower')
            ->expectsQuestion(self::AGAIN_QUESTION, 'sunflower')
            ->expectsQuestion(self::CODE_QUESTION, '000000')
            ->expectsQuestion(self::CODE_QUESTION, '000000')
            ->expectsQuestion(self::CODE_QUESTION, '000000')
            ->assertFailed();

        $author = User::query()->where('role', UserRole::Author)->sole();

        $this->assertNull($author->two_factor_confirmed_at);
    }

    public function test_new_codes_replaces_the_backup_codes_of_a_confirmed_author(): void
    {
        $this->setUpConfirmedAuthor();
        $oldCodes = User::query()->where('role', UserRole::Author)->sole()->recoveryCodes();

        $this->artisan('eyiwetemi:author', ['email' => 'ada@example.com', '--new-codes' => true])
            ->assertSuccessful();

        $newCodes = User::query()->where('role', UserRole::Author)->sole()->recoveryCodes();

        $this->assertCount(8, $newCodes);
        $this->assertEmpty(array_intersect($oldCodes, $newCodes));
    }

    public function test_new_codes_needs_a_confirmed_authenticator(): void
    {
        User::factory()->create(['role' => UserRole::Author, 'email' => 'ada@example.com']);

        $this->artisan('eyiwetemi:author', ['email' => 'ada@example.com', '--new-codes' => true])
            ->assertFailed();
    }

    public function test_a_new_email_for_the_author_changes_only_after_a_yes(): void
    {
        $author = User::factory()->create(['role' => UserRole::Author, 'email' => 'old@example.com']);

        $this->artisan('eyiwetemi:author', ['email' => 'new@example.com', '--password-only' => true])
            ->expectsConfirmation("Change the author's email from old@example.com to new@example.com?", 'yes')
            ->expectsConfirmation('Change the password?', 'no')
            ->assertSuccessful();

        $this->assertSame('new@example.com', $author->fresh()->email);
    }

    private function setUpConfirmedAuthor(): void
    {
        $this->artisan('eyiwetemi:author', ['email' => 'ada@example.com', '--name' => 'Ada Okafor'])
            ->expectsQuestion(self::PASSWORD_QUESTION, 'sunflower')
            ->expectsQuestion(self::AGAIN_QUESTION, 'sunflower')
            ->expectsQuestion(self::CODE_QUESTION, (new Google2FA)->getCurrentOtp(self::SECRET))
            ->assertSuccessful();
    }
}
