<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\Visibility;
use App\Models\ReviewerInvite;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewInviteTest extends TestCase
{
    use RefreshDatabase;

    private Work $ember;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ember = Work::factory()->novel()->create(['title' => 'The Ember Gate', 'visibility' => Visibility::Private]);
    }

    /**
     * An invite for the given works, and the token its link carries.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: ReviewerInvite, 1: string}
     */
    private function invite(array $attributes = [], Work ...$works): array
    {
        $token = Str::random(64);
        $invite = ReviewerInvite::factory()->create([
            'email' => 'chioma@example.com',
            'token_hash' => hash('sha256', $token),
            ...$attributes,
        ]);
        $invite->works()->attach(collect($works)->pluck('id')->all());

        return [$invite, $token];
    }

    public function test_a_reader_who_opens_the_link_signed_in_becomes_a_reviewer_of_the_works(): void
    {
        $reader = User::factory()->reader()->create();
        $salt = Work::factory()->novel()->create(['visibility' => Visibility::Reviewers]);
        [$invite, $token] = $this->invite([], $this->ember, $salt);

        $this->actingAs($reader)->get('/review/'.$token)->assertRedirect('/library/'.$this->ember->slug);

        $this->assertSame(UserRole::Reviewer, $reader->fresh()->role);
        $this->assertDatabaseHas('work_reviewer', ['work_id' => $this->ember->id, 'user_id' => $reader->id]);
        $this->assertDatabaseHas('work_reviewer', ['work_id' => $salt->id, 'user_id' => $reader->id]);
        $this->assertSame(Visibility::Reviewers, $this->ember->fresh()->visibility);
        $this->assertSame(Visibility::Reviewers, $salt->fresh()->visibility);
        $this->assertNotNull($invite->fresh()->accepted_at);
        $this->assertSame($reader->id, $invite->fresh()->accepted_user_id);
    }

    public function test_the_link_opens_on_the_first_work_the_invite_was_given(): void
    {
        $salt = Work::factory()->novel()->create(['title' => 'Salt Season']);
        [$invite, $token] = $this->invite([], $this->ember, $salt);
        $invite->works()->updateExistingPivot($salt->id, ['created_at' => now()->subDay()]);

        $this->actingAs(User::factory()->reader()->create())->get('/review/'.$token)
            ->assertRedirect('/library/'.$salt->slug);
    }

    public function test_a_guest_is_asked_to_sign_in_and_the_invite_waits_for_them(): void
    {
        [$invite, $token] = $this->invite([], $this->ember);

        $this->get('/review/'.$token)
            ->assertRedirect(route('home'))
            ->assertSessionHas('signin', ['view' => 'main', 'why' => 'Sign in to read The Ember Gate'])
            ->assertSessionHas('review.invite', $invite->id)
            ->assertSessionHas('url.intended', '/library/'.$this->ember->slug);

        $this->assertNull($invite->fresh()->accepted_at);
    }

    public function test_signing_in_takes_up_the_invite_and_the_sign_in_lands_on_the_work(): void
    {
        $reader = User::factory()->reader()->create(['email' => 'tolu@example.com']);
        [$invite, $token] = $this->invite(['email' => 'tolu@example.com'], $this->ember);
        $this->get('/review/'.$token);

        $this->postJson('/login', ['email' => 'tolu@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertExactJson(['redirect' => '/library/'.$this->ember->slug]);

        $this->assertSame(UserRole::Reviewer, $reader->fresh()->role);
        $this->assertDatabaseHas('work_reviewer', ['work_id' => $this->ember->id, 'user_id' => $reader->id]);
        $this->assertSame($reader->id, $invite->fresh()->accepted_user_id);
    }

    public function test_creating_an_account_takes_up_the_invite(): void
    {
        [$invite, $token] = $this->invite(['email' => 'chioma@example.com'], $this->ember);
        $this->get('/review/'.$token);

        $this->postJson('/register', [
            'name' => 'Chioma Eze',
            'username' => 'chioma',
            'email' => 'chioma@example.com',
            'password' => 'paper boats',
        ])->assertOk()->assertExactJson(['redirect' => '/library/'.$this->ember->slug]);

        $user = User::query()->where('email', 'chioma@example.com')->sole();
        $this->assertSame(UserRole::Reviewer, $user->role);
        $this->assertDatabaseHas('work_reviewer', ['work_id' => $this->ember->id, 'user_id' => $user->id]);
        $this->assertSame($user->id, $invite->fresh()->accepted_user_id);
    }

    public function test_a_sign_in_does_not_take_up_an_invite_that_has_run_out(): void
    {
        $reader = User::factory()->reader()->create(['email' => 'tolu@example.com']);
        [$invite] = $this->invite(['email' => 'tolu@example.com', 'expires_at' => now()->subDay()], $this->ember);

        $this->withSession(['review.invite' => $invite->id])
            ->postJson('/login', ['email' => 'tolu@example.com', 'password' => 'password'])
            ->assertOk();

        $this->assertSame(UserRole::Reader, $reader->fresh()->role);
        $this->assertNull($invite->fresh()->accepted_at);
        $this->assertDatabaseMissing('work_reviewer', ['user_id' => $reader->id]);
    }

    public function test_an_unknown_cancelled_or_expired_invite_is_not_found(): void
    {
        $reader = User::factory()->reader()->create();
        [$cancelled, $cancelledToken] = $this->invite(['cancelled_at' => now()], $this->ember);
        [$expired, $expiredToken] = $this->invite(['expires_at' => now()->subDay()], $this->ember);

        $this->actingAs($reader)->get('/review/'.Str::random(64))->assertNotFound();
        $this->actingAs($reader)->get('/review/'.$cancelledToken)->assertNotFound();
        $this->actingAs($reader)->get('/review/'.$expiredToken)->assertNotFound();
        $this->get('/review/'.$expiredToken)->assertNotFound();

        $this->assertSame(UserRole::Reader, $reader->fresh()->role);
        $this->assertNull($cancelled->fresh()->accepted_at);
        $this->assertNull($expired->fresh()->accepted_at);
    }

    public function test_an_author_who_opens_the_link_keeps_their_account_and_is_not_added(): void
    {
        $author = User::factory()->author()->create();
        [$invite, $token] = $this->invite([], $this->ember);

        $this->actingAs($author)->get('/review/'.$token)->assertRedirect('/library/'.$this->ember->slug);

        $this->assertSame(UserRole::Author, $author->fresh()->role);
        $this->assertDatabaseMissing('work_reviewer', ['user_id' => $author->id]);
        $this->assertNull($invite->fresh()->accepted_at);
    }

    public function test_an_accepted_invite_opens_only_for_the_person_who_accepted_it(): void
    {
        $reader = User::factory()->reader()->create();
        [, $token] = $this->invite([], $this->ember);
        $this->actingAs($reader)->get('/review/'.$token)->assertRedirect('/library/'.$this->ember->slug);

        $this->actingAs($reader)->get('/review/'.$token)->assertRedirect('/library/'.$this->ember->slug);
        $this->actingAs(User::factory()->reader()->create())->get('/review/'.$token)->assertNotFound();
        $this->get('/review/'.$token)->assertNotFound();
    }
}
