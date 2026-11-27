<?php

namespace Tests\Feature\Admin;

use App\Enums\CommentKind;
use App\Enums\UserRole;
use App\Enums\Visibility;
use App\Mail\ReviewerInvited;
use App\Models\Comment;
use App\Models\ReviewerInvite;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminReviewersTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->author()->create(['name' => 'Georgina Awani', 'email' => 'georgina@example.com']);

        Mail::fake();
    }

    private function asAdmin(): static
    {
        return $this->actingAs($this->author)->withSession(['admin.verified' => true]);
    }

    private function url(string $path): string
    {
        return 'http://'.config('eyiwetemi.admin_domain').$path;
    }

    private function work(string $title, string $slug, Visibility $visibility = Visibility::Private): Work
    {
        return Work::factory()->novel()->create([
            'title' => $title,
            'title_key' => mb_strtolower($title),
            'slug' => $slug,
            'visibility' => $visibility,
        ]);
    }

    private function reviewer(string $name, string $email, Work ...$works): User
    {
        $user = User::factory()->reviewer()->create(['name' => $name, 'email' => $email]);
        $user->reviewableWorks()->attach(collect($works)->pluck('id')->all());

        return $user;
    }

    private function invite(string $name, string $email, array $attributes = []): ReviewerInvite
    {
        return ReviewerInvite::factory()->create([
            'name' => $name,
            'email' => $email,
            'invited_by' => $this->author->id,
            ...$attributes,
        ]);
    }

    private function bookNote(User $reviewer, Work $work, string $body, \DateTimeInterface $at): Comment
    {
        return Comment::factory()->review()->create([
            'user_id' => $reviewer->id,
            'work_id' => $work->id,
            'work_item_id' => null,
            'kind' => CommentKind::Book,
            'body' => $body,
            'created_at' => $at,
        ]);
    }

    public function test_the_page_lists_reviewers_with_works_then_the_invites_still_waiting_or_run_out(): void
    {
        $ember = $this->work('The Ember Gate', 'the-ember-gate');
        $salt = $this->work('Salt Season', 'salt-season', Visibility::Reviewers);
        $midnight = $this->work('Midnight Poems', 'midnight-poems', Visibility::Public);

        $adaeze = $this->reviewer('Adaeze Okafor', 'adaeze@example.com', $ember, $salt);
        $ruth = $this->reviewer('Ruth Adu', 'ruth@example.com', $salt);
        $this->reviewer('Zed Ola', 'zed@example.com', $midnight);
        $this->reviewer('Kemi Ade', 'kemi@example.com');

        $this->bookNote($adaeze, $ember, 'Lovely.', now()->subMinutes(10));
        $this->bookNote($ruth, $salt, 'Good.', now()->subDay());

        $old = $this->invite('Old Friend', 'old@example.com', ['expires_at' => now()->subDay(), 'created_at' => now()->subDays(3)]);
        $old->works()->attach($ember->id);
        $chioma = $this->invite('Chioma Eze', 'chioma@example.com', ['note' => 'Tell me about the middle.', 'created_at' => now()->subDay()]);
        $chioma->works()->attach($ember->id);
        $this->invite('Done Already', 'done@example.com', ['accepted_at' => now(), 'accepted_user_id' => $this->reviewer('Done', 'done-user@example.com')->id]);
        $this->invite('Cancelled', 'cancelled@example.com', ['cancelled_at' => now()]);

        $this->asAdmin()->get($this->url('/reviewers'))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Reviewers')
                ->has('people', 5)
                ->where('people.0.key', 'u'.$adaeze->id)
                ->where('people.0.name', 'Adaeze Okafor')
                ->where('people.0.state', 'on')
                ->where('people.0.comments', 1)
                ->where('people.0.last', '10 min')
                ->where('people.0.works.0', 'the-ember-gate')
                ->where('people.0.works.1', 'salt-season')
                ->where('people.1.name', 'Ruth Adu')
                ->where('people.2.name', 'Zed Ola')
                ->where('people.2.last', '')
                ->where('people.3.key', 'i'.$old->id)
                ->where('people.3.kind', 'invite')
                ->where('people.3.state', 'expired')
                ->where('people.3.days', 0)
                ->where('people.4.name', 'Chioma Eze')
                ->where('people.4.state', 'invited')
                ->where('people.4.days', 14)
                ->where('people.4.note', 'Tell me about the middle.')
                ->has('works', 3)
                ->where('open', null));

        $this->asAdmin()->get($this->url('/reviewers?p=i'.$chioma->id))
            ->assertInertia(fn ($page) => $page->where('open', 'i'.$chioma->id));
    }

    public function test_an_invite_is_saved_mailed_and_opens_its_private_works_to_reviewers(): void
    {
        $ember = $this->work('The Ember Gate', 'the-ember-gate');
        $salt = $this->work('Salt Season', 'salt-season');

        $response = $this->asAdmin()->postJson($this->url('/reviewers/invites'), [
            'name' => '  Ruth   Adu ',
            'email' => '  RUTH@Example.com ',
            'works' => [$salt->slug, $ember->slug],
            'note' => 'Start with the lamps.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('person.name', 'Ruth Adu')
            ->assertJsonPath('person.email', 'ruth@example.com')
            ->assertJsonPath('person.kind', 'invite')
            ->assertJsonPath('person.state', 'invited')
            ->assertJsonPath('person.days', 14)
            ->assertJsonPath('person.works.0', 'the-ember-gate')
            ->assertJsonPath('person.works.1', 'salt-season')
            ->assertJsonPath('person.note', 'Start with the lamps.');

        $invite = ReviewerInvite::query()->where('email', 'ruth@example.com')->sole();
        $this->assertSame($this->author->id, $invite->invited_by);
        $this->assertSame(64, strlen($invite->token_hash));
        $this->assertEqualsWithDelta(now()->addDays(14)->getTimestamp(), $invite->expires_at->getTimestamp(), 60);
        $this->assertSame(2, $invite->works()->count());
        $this->assertSame(Visibility::Reviewers, $ember->fresh()->visibility);
        $this->assertSame(Visibility::Reviewers, $salt->fresh()->visibility);

        Mail::assertQueued(ReviewerInvited::class, function (ReviewerInvited $mail) use ($invite): bool {
            preg_match('#/review/([A-Za-z0-9]{64})$#', $mail->startUrl, $match);

            return $mail->hasTo('ruth@example.com')
                && $mail->envelope()->subject === 'Read The Ember Gate before everyone else'
                && isset($match[1])
                && hash('sha256', $match[1]) === $invite->token_hash;
        });
    }

    public function test_an_invite_is_refused_for_the_writers_own_email_a_reviewer_with_works_or_a_pending_invite(): void
    {
        $ember = $this->work('The Ember Gate', 'the-ember-gate');
        $this->reviewer('Adaeze Okafor', 'adaeze@example.com', $ember);
        $this->invite('Chioma Eze', 'chioma@example.com', ['expires_at' => now()->addDays(5)]);

        $this->asAdmin()->postJson($this->url('/reviewers/invites'), ['name' => 'Adaeze', 'email' => 'adaeze@example.com', 'works' => [$ember->slug]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Adaeze Okafor already reviews. Open their row to change their works.');

        $this->asAdmin()->postJson($this->url('/reviewers/invites'), ['name' => 'Chioma', 'email' => 'chioma@example.com', 'works' => [$ember->slug]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Chioma Eze is already invited.');

        $this->asAdmin()->postJson($this->url('/reviewers/invites'), ['name' => 'Georgina', 'email' => 'georgina@example.com', 'works' => [$ember->slug]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'That’s your own email.');

        $this->asAdmin()->postJson($this->url('/reviewers/invites'), ['name' => 'Kemi Ade', 'email' => 'kemi@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('works');

        $this->assertSame(1, ReviewerInvite::query()->count());
        Mail::assertNothingQueued();
    }

    public function test_resending_renews_the_link_and_needs_a_work_and_a_live_invite(): void
    {
        $ember = $this->work('The Ember Gate', 'the-ember-gate');
        $pending = $this->invite('Chioma Eze', 'chioma@example.com', ['expires_at' => now()->addDays(3)]);
        $pending->works()->attach($ember->id);
        $oldHash = $pending->token_hash;

        $this->asAdmin()->postJson($this->url('/reviewers/invites/'.$pending->id.'/resend'))
            ->assertOk()
            ->assertJsonPath('person.state', 'invited')
            ->assertJsonPath('person.days', 14);

        $pending->refresh();
        $this->assertNotSame($oldHash, $pending->token_hash);
        $this->assertEqualsWithDelta(now()->addDays(14)->getTimestamp(), $pending->expires_at->getTimestamp(), 60);
        Mail::assertQueued(ReviewerInvited::class, 1);

        $bare = $this->invite('Bare', 'bare@example.com');
        $this->asAdmin()->postJson($this->url('/reviewers/invites/'.$bare->id.'/resend'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('works');

        $this->asAdmin()->postJson($this->url('/reviewers/invites/'.$this->invite('Gone', 'gone@example.com', ['cancelled_at' => now()])->id.'/resend'))
            ->assertNotFound();
        $this->asAdmin()->postJson($this->url('/reviewers/invites/'.ReviewerInvite::factory()->accepted()->create(['invited_by' => $this->author->id])->id.'/resend'))
            ->assertNotFound();

        Mail::assertQueued(ReviewerInvited::class, 1);
    }

    public function test_cancelling_an_invite_takes_it_off_the_page_and_an_accepted_one_cannot_be_cancelled(): void
    {
        $ember = $this->work('The Ember Gate', 'the-ember-gate');
        $invite = $this->invite('Chioma Eze', 'chioma@example.com');
        $invite->works()->attach($ember->id);

        $this->asAdmin()->deleteJson($this->url('/reviewers/invites/'.$invite->id))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNotNull($invite->fresh()->cancelled_at);

        $this->asAdmin()->get($this->url('/reviewers'))
            ->assertInertia(fn ($page) => $page->has('people', 0));

        $accepted = ReviewerInvite::factory()->accepted()->create(['invited_by' => $this->author->id]);

        $this->asAdmin()->deleteJson($this->url('/reviewers/invites/'.$accepted->id))->assertNotFound();
    }

    public function test_a_work_can_be_given_to_or_taken_from_an_invite(): void
    {
        $ember = $this->work('The Ember Gate', 'the-ember-gate');
        $salt = $this->work('Salt Season', 'salt-season');
        $invite = $this->invite('Chioma Eze', 'chioma@example.com');
        $invite->works()->attach($ember->id);

        $this->asAdmin()->patchJson($this->url('/reviewers/invites/'.$invite->id.'/works'), ['work' => $salt->slug, 'on' => true])
            ->assertOk()
            ->assertJsonCount(2, 'person.works')
            ->assertJsonPath('person.works.1', 'salt-season');

        $this->assertSame(Visibility::Reviewers, $salt->fresh()->visibility);

        $this->asAdmin()->patchJson($this->url('/reviewers/invites/'.$invite->id.'/works'), ['work' => $ember->slug, 'on' => false])
            ->assertOk()
            ->assertJsonCount(1, 'person.works')
            ->assertJsonPath('person.works.0', 'salt-season');

        $this->asAdmin()->patchJson($this->url('/reviewers/invites/'.$invite->id.'/works'), ['work' => $salt->slug, 'on' => false])
            ->assertOk()
            ->assertJsonCount(0, 'person.works');

        $accepted = ReviewerInvite::factory()->accepted()->create(['invited_by' => $this->author->id]);
        $this->asAdmin()->patchJson($this->url('/reviewers/invites/'.$accepted->id.'/works'), ['work' => $salt->slug, 'on' => true])
            ->assertNotFound();
    }

    public function test_a_work_can_be_given_to_or_taken_from_a_reviewer_and_only_reviewers_are_changed(): void
    {
        $ember = $this->work('The Ember Gate', 'the-ember-gate');
        $salt = $this->work('Salt Season', 'salt-season');
        $midnight = $this->work('Midnight Poems', 'midnight-poems', Visibility::Public);
        $adaeze = $this->reviewer('Adaeze Okafor', 'adaeze@example.com', $ember);

        $this->asAdmin()->patchJson($this->url('/reviewers/'.$adaeze->id.'/works'), ['work' => $salt->slug, 'on' => true])
            ->assertOk()
            ->assertJsonPath('person.works.1', 'salt-season');

        $this->assertSame(Visibility::Reviewers, $salt->fresh()->visibility);

        $this->asAdmin()->patchJson($this->url('/reviewers/'.$adaeze->id.'/works'), ['work' => $midnight->slug, 'on' => true])
            ->assertOk();
        $this->assertSame(Visibility::Public, $midnight->fresh()->visibility);

        $this->asAdmin()->patchJson($this->url('/reviewers/'.$adaeze->id.'/works'), ['work' => $ember->slug, 'on' => false])
            ->assertOk()
            ->assertJsonCount(2, 'person.works')
            ->assertJsonPath('person.works.0', 'salt-season');

        $this->asAdmin()->patchJson($this->url('/reviewers/'.$this->author->id.'/works'), ['work' => $salt->slug, 'on' => true])
            ->assertNotFound();
        $this->asAdmin()->patchJson($this->url('/reviewers/'.User::factory()->reader()->create()->id.'/works'), ['work' => $salt->slug, 'on' => true])
            ->assertNotFound();
    }

    public function test_removing_a_reviewer_takes_their_works_and_keeps_their_notes_and_role(): void
    {
        $ember = $this->work('The Ember Gate', 'the-ember-gate');
        $salt = $this->work('Salt Season', 'salt-season');
        $adaeze = $this->reviewer('Adaeze Okafor', 'adaeze@example.com', $ember, $salt);
        $this->bookNote($adaeze, $ember, 'Lovely.', now()->subMinutes(5));
        $notes = Comment::query()->count();

        $this->asAdmin()->deleteJson($this->url('/reviewers/'.$adaeze->id))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(0, DB::table('work_reviewer')->where('user_id', $adaeze->id)->count());
        $this->assertSame($notes, Comment::query()->count());
        $this->assertSame(UserRole::Reviewer, $adaeze->fresh()->role);

        $this->asAdmin()->get($this->url('/reviewers'))
            ->assertInertia(fn ($page) => $page->has('people', 0));

        $this->asAdmin()->deleteJson($this->url('/reviewers/'.$this->author->id))->assertNotFound();
    }

    public function test_the_works_endpoint_is_the_works_the_page_carries(): void
    {
        $this->work('The Ember Gate', 'the-ember-gate');
        $this->work('Salt Season', 'salt-season', Visibility::Reviewers);
        $this->work('Midnight Poems', 'midnight-poems', Visibility::Public);

        $json = $this->asAdmin()->getJson($this->url('/reviewers/works'))
            ->assertOk()
            ->assertJsonCount(3, 'works')
            ->assertJsonStructure(['works' => [['slug', 'title', 'sub', 'visibility', 'cover' => ['src', 'color', 'dark']]]])
            ->json('works');

        $page = $this->asAdmin()->get($this->url('/reviewers'))->viewData('page');

        $this->assertEquals($page['props']['works'], $json);
    }

    public function test_guests_get_401_on_json_and_are_sent_to_the_sign_in_on_pages(): void
    {
        $this->getJson($this->url('/reviewers/works'))->assertUnauthorized();
        $this->getJson($this->url('/reviewers'))->assertUnauthorized();
        $this->postJson($this->url('/reviewers/invites'), ['name' => 'Ruth', 'email' => 'ruth@example.com', 'works' => []])->assertUnauthorized();
        $this->get($this->url('/reviewers'))->assertRedirect(route('admin.signin'));

        Mail::assertNothingQueued();
    }
}
