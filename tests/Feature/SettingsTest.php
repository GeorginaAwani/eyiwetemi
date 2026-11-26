<?php

namespace Tests\Feature;

use App\Mail\ConfirmEmailChange;
use App\Models\Comment;
use App\Models\User;
use App\Models\UsernameHold;
use App\Support\Preferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_name_is_saved_and_an_empty_one_is_refused(): void
    {
        $user = User::factory()->create(['name' => 'Tolu'])->refresh();

        $this->actingAs($user)->patchJson('/settings/name', ['name' => '  Tolu Ade  '])
            ->assertOk()->assertExactJson(['name' => 'Tolu Ade']);
        $this->assertSame('Tolu Ade', $user->fresh()->name);

        $this->patchJson('/settings/name', ['name' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors(['name' => 'Type your name.']);
    }

    public function test_a_username_change_holds_the_old_one_and_locks_the_next_change(): void
    {
        $user = User::factory()->create(['username' => 'toluade'])->refresh();

        $response = $this->actingAs($user)->patchJson('/settings/username', ['username' => ' Tolu.Reads '])->assertOk();

        $response->assertJson(['username' => 'tolu.reads', 'nextChange' => now()->addDays(30)->timezone('Africa/Lagos')->format('j M')]);
        $this->assertSame('tolu.reads', $user->fresh()->username);
        $this->assertDatabaseHas('username_holds', ['username' => 'toluade', 'user_id' => $user->id]);
        $this->assertTrue(UsernameHold::query()->where('username', 'toluade')->sole()->released_at->isFuture());

        $until = $user->fresh()->username_changed_at->copy()->addDays(30)->timezone('Africa/Lagos')->format('j M');
        $this->patchJson('/settings/username', ['username' => 'tolu.new'])
            ->assertUnprocessable()->assertJsonValidationErrors(['username' => 'You can change it again from '.$until]);
        $this->assertSame('tolu.reads', $user->fresh()->username);
    }

    public function test_a_taken_or_current_username_is_refused(): void
    {
        $user = User::factory()->create(['username' => 'toluade'])->refresh();
        User::factory()->create(['username' => 'kemi']);

        $this->actingAs($user)->patchJson('/settings/username', ['username' => 'kemi'])
            ->assertJsonValidationErrors(['username' => '@kemi is taken.']);
        $this->patchJson('/settings/username', ['username' => 'TOLUADE'])
            ->assertJsonValidationErrors(['username' => 'That’s your username now.']);
        $this->assertSame('toluade', $user->fresh()->username);
    }

    public function test_an_email_change_waits_until_the_new_address_confirms(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'ada@example.com'])->refresh();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($user)->postJson('/settings/email', ['email' => 'Ada@Example.com', 'password' => 'password'])
            ->assertJsonValidationErrors(['email' => 'That’s your email now.']);
        $this->postJson('/settings/email', ['email' => 'taken@example.com', 'password' => 'password'])
            ->assertJsonValidationErrors(['email' => 'That email already has an account.']);
        $this->postJson('/settings/email', ['email' => 'new@example.com', 'password' => 'wrong'])
            ->assertJsonValidationErrors(['password' => 'That password doesn’t match.']);

        $this->postJson('/settings/email', ['email' => ' New@Example.com ', 'password' => 'password'])
            ->assertOk()->assertExactJson(['pending' => 'new@example.com']);

        $this->assertSame('new@example.com', $user->fresh()->pending_email);
        $this->assertSame('ada@example.com', $user->fresh()->email);
        Mail::assertQueued(ConfirmEmailChange::class, fn (ConfirmEmailChange $mail): bool => $mail->hasTo('new@example.com'));
    }

    public function test_a_google_only_account_adds_a_password_before_changing_email(): void
    {
        $user = User::factory()->googleOnly()->create()->refresh();

        $this->actingAs($user)->postJson('/settings/email', ['email' => 'new@example.com', 'password' => 'anything'])
            ->assertJsonValidationErrors(['password' => 'Add a password first.']);
        $this->assertNull($user->fresh()->pending_email);
    }

    public function test_a_waiting_change_can_be_sent_again_or_cancelled(): void
    {
        Mail::fake();
        $user = User::factory()->create()->refresh();

        $this->actingAs($user)->postJson('/settings/email/again')
            ->assertJsonValidationErrors(['email' => 'There’s no change waiting.']);

        $user->forceFill(['pending_email' => 'new@example.com'])->save();

        $this->postJson('/settings/email/again')->assertOk()->assertExactJson(['pending' => 'new@example.com']);
        Mail::assertQueued(ConfirmEmailChange::class);

        $this->deleteJson('/settings/email/pending')->assertOk()->assertExactJson(['pending' => null]);
        $this->assertNull($user->fresh()->pending_email);
    }

    public function test_the_confirmation_link_changes_the_email_and_a_tampered_or_old_one_is_refused(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com', 'pending_email' => 'new@example.com'])->refresh();
        $link = URL::temporarySignedRoute('settings.email.confirm', now()->addDay(), ['user' => $user->id, 'email' => 'new@example.com']);

        $tampered = preg_replace('/email=[^&]+/', 'email=evil%40example.com', $link);
        $this->get($tampered)->assertForbidden();
        $this->assertSame('ada@example.com', $user->fresh()->email);

        $user->forceFill(['pending_email' => 'later@example.com'])->save();
        $this->get($link)->assertRedirect('/settings')->assertSessionHas('status', 'That link has expired.');
        $this->assertSame('ada@example.com', $user->fresh()->email);

        $user->forceFill(['pending_email' => 'new@example.com'])->save();
        $this->get($link)->assertRedirect('/settings')->assertSessionHas('status', 'Your email is changed.');

        $user->refresh();
        $this->assertSame('new@example.com', $user->email);
        $this->assertNull($user->pending_email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_a_password_change_needs_the_current_one_and_keeps_you_signed_in(): void
    {
        $user = User::factory()->create()->refresh();

        $this->actingAs($user)->putJson('/settings/password', ['current_password' => 'nope', 'password' => 'a new password'])
            ->assertJsonValidationErrors(['current_password' => 'That password doesn’t match.']);
        $this->putJson('/settings/password', ['password' => 'a new password'])
            ->assertJsonValidationErrors(['current_password' => 'Type your current password.']);
        $this->putJson('/settings/password', ['current_password' => 'password', 'password' => 'short'])
            ->assertJsonValidationErrors(['password' => 'At least 8 characters.']);

        $this->putJson('/settings/password', ['current_password' => 'password', 'password' => 'a new password'])
            ->assertOk()->assertExactJson(['ok' => true]);

        $this->assertTrue(Hash::check('a new password', $user->fresh()->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_google_only_account_adds_a_password_without_a_current_one(): void
    {
        $user = User::factory()->googleOnly()->create()->refresh();

        $this->actingAs($user)->putJson('/settings/password', ['password' => 'a new password'])->assertOk();

        $this->assertTrue(Hash::check('a new password', $user->fresh()->password));
    }

    public function test_a_new_photo_changes_the_seed(): void
    {
        $user = User::factory()->create(['avatar_seed' => 'old-seed-value'])->refresh();

        $seed = $this->actingAs($user)->postJson('/settings/photo')->assertOk()->json('seed');

        $this->assertNotSame('old-seed-value', $seed);
        $this->assertSame($seed, $user->fresh()->avatar_seed);
    }

    public function test_a_notice_is_stored_and_the_defaults_come_back_in_full(): void
    {
        $user = User::factory()->create(['preferences' => ['reading' => ['size' => 3]]])->refresh();

        $notifications = $this->actingAs($user)->putJson('/settings/notifications', ['key' => 'new.email', 'on' => true])
            ->assertOk()->json('notifications');

        $this->assertSame(array_merge(Preferences::NOTIFICATION_DEFAULTS, ['new.email' => true]), $notifications);
        $this->assertTrue($user->fresh()->preferences['notifications']['new.email']);
        $this->assertSame(3, $user->fresh()->preferences['reading']['size']);

        $this->putJson('/settings/notifications', ['key' => 'gossip.site', 'on' => true])
            ->assertJsonValidationErrors(['key']);
    }

    public function test_reading_settings_merge_and_refuse_unknown_values(): void
    {
        $user = User::factory()->create(['preferences' => ['notifications' => ['new.email' => true]]])->refresh();

        $this->actingAs($user)->putJson('/settings/reading', ['size' => 3, 'theme' => 'night'])
            ->assertOk()->assertExactJson(['reading' => ['size' => 3, 'font' => 'serif', 'width' => 'narrow', 'lines' => 'airy', 'theme' => 'night']]);

        $this->putJson('/settings/reading', ['font' => 'sans'])
            ->assertOk()->assertJsonPath('reading.theme', 'night');
        $this->assertTrue($user->fresh()->preferences['notifications']['new.email']);

        $this->putJson('/settings/reading', ['size' => 5])->assertJsonValidationErrors(['size']);
        $this->putJson('/settings/reading', ['theme' => 'blue'])->assertJsonValidationErrors(['theme']);
    }

    public function test_a_wrong_username_refuses_deleting_the_account(): void
    {
        $user = User::factory()->create(['username' => 'toluade'])->refresh();

        $this->actingAs($user)->deleteJson('/settings/account', ['username' => 'someone'])
            ->assertJsonValidationErrors(['username' => 'Type your username to confirm.']);

        $this->assertModelExists($user);
    }

    public function test_deleting_the_account_blanks_your_comments_keeps_replies_and_signs_you_out(): void
    {
        $user = User::factory()->create(['username' => 'toluade'])->refresh();
        $other = User::factory()->create();
        $comment = Comment::factory()->create(['user_id' => $user->id, 'body' => 'My own words']);
        $reply = Comment::factory()->create(['user_id' => $other->id, 'parent_id' => $comment->id, 'work_id' => $comment->work_id, 'body' => 'A reply from someone else']);

        $this->actingAs($user)->deleteJson('/settings/account', ['username' => ' TOLUADE '])
            ->assertOk()->assertExactJson(['redirect' => '/']);

        $this->assertModelMissing($user);
        $this->assertNull(Comment::withTrashed()->find($comment->id)->body);
        $this->assertNotNull(Comment::withTrashed()->find($comment->id)->deleted_at);
        $this->assertSame('A reply from someone else', Comment::query()->find($reply->id)->body);
        $this->assertGuest();
    }

    public function test_guests_are_asked_to_sign_in(): void
    {
        $this->patchJson('/settings/name', ['name' => 'Ada'])->assertUnauthorized();
        $this->putJson('/settings/reading', ['theme' => 'night'])->assertUnauthorized();
        $this->deleteJson('/settings/account', ['username' => 'ada'])->assertUnauthorized();
    }
}
