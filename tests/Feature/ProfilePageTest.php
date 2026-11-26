<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Follow;
use App\Models\Like;
use App\Models\Note;
use App\Models\ReadingProgress;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfilePageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Work, 1: Collection<int, WorkItem>}
     */
    private function novel(string $title, int $chapters, int $newestDaysAgo = 30): array
    {
        $work = Work::factory()->novel()->public()->published()->create(['title' => $title]);
        $items = collect(range(1, $chapters))->map(fn (int $n): WorkItem => WorkItem::factory()->chapter()->published()->for($work)->create([
            'position' => $n,
            'title' => $n === 2 ? 'Lanterns' : null,
            'live_words' => 2300,
            'published_at' => now()->subDays($newestDaysAgo + $chapters - $n),
        ]));

        return [$work, $items];
    }

    public function test_guests_are_sent_home_to_sign_in(): void
    {
        $this->get('/profile')->assertRedirect('/');
        $this->get('/profile/likes')->assertRedirect('/');
        $this->get('/settings')->assertRedirect('/');
    }

    public function test_a_new_account_sees_the_empty_profile(): void
    {
        $user = User::factory()->create(['name' => 'Tolu Ade', 'username' => 'toluade']);

        $this->actingAs($user)->get('/profile')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Profile')
            ->where('me.name', 'Tolu Ade')
            ->where('me.username', 'toluade')
            ->where('place', null)
            ->where('following.count', 0)
            ->where('likes.count', 0)
            ->where('comments.count', 0));
    }

    public function test_your_place_is_the_novel_you_read_last_and_moves_on_after_a_finished_chapter(): void
    {
        $user = User::factory()->create();
        [$boats, $boatItems] = $this->novel('Paper Boats', 3);
        [$ember, $emberItems] = $this->novel('The Ember Gate', 4);
        ReadingProgress::factory()->for($user)->create(['work_item_id' => $emberItems[0]->id, 'percent' => 40, 'updated_at' => now()->subDay()]);
        ReadingProgress::factory()->for($user)->finished()->create(['work_item_id' => $boatItems[0]->id, 'updated_at' => now()]);

        $this->actingAs($user)->get('/profile')->assertInertia(fn (Assert $page) => $page
            ->where('place.work.title', 'Paper Boats')
            ->where('place.label', 'Chapter 2')
            ->where('place.title', 'Lanterns')
            ->where('place.percent', 0)
            ->where('place.href', '/library/'.$boats->slug.'/2')
            ->where('place.left', '10 min left in this chapter')
            ->where('place.also.0.title', 'The Ember Gate')
            ->where('place.also.0.label', 'Chapter 1'));
    }

    public function test_following_shows_what_is_new_or_where_you_are(): void
    {
        $user = User::factory()->create();
        [$ember] = $this->novel('The Ember Gate', 3, newestDaysAgo: 2);
        [$boats, $boatItems] = $this->novel('Paper Boats', 3);
        Follow::factory()->for($user)->create(['followable_type' => 'work', 'followable_id' => $boats->id]);
        Follow::factory()->for($user)->create(['followable_type' => 'work', 'followable_id' => $ember->id]);
        ReadingProgress::factory()->for($user)->create(['work_item_id' => $boatItems[1]->id, 'percent' => 50]);

        $this->actingAs($user)->get('/profile')->assertInertia(fn (Assert $page) => $page
            ->where('following.count', 2)
            ->where('following.rows.0.title', 'The Ember Gate')
            ->where('following.rows.0.status', ['icon' => 'pen', 'text' => '3 chapters so far'])
            ->where('following.rows.0.extra', ['label' => 'Chapter 3', 'href' => '/library/'.$ember->slug.'/3', 'pill' => 'new'])
            ->where('following.rows.1.extra', ['label' => 'Chapter 2', 'href' => '/library/'.$boats->slug.'/2', 'pill' => 'you’re here']));
    }

    public function test_likes_list_chapters_and_notes_but_not_comments(): void
    {
        $user = User::factory()->create();
        [$ember, $items] = $this->novel('The Ember Gate', 2);
        $note = Note::factory()->published()->create(['live_text' => 'Salt Season is now in print. More soon.', 'work_id' => $ember->id]);
        $comment = Comment::factory()->create(['work_id' => $ember->id, 'work_item_id' => $items[0]->id]);
        Like::factory()->for($user)->create(['likeable_type' => 'work_item', 'likeable_id' => $items[1]->id, 'created_at' => now()->subMonths(2)]);
        Like::factory()->for($user)->create(['likeable_type' => 'note', 'likeable_id' => $note->id]);
        Like::factory()->for($user)->create(['likeable_type' => 'comment', 'likeable_id' => $comment->id]);

        $this->actingAs($user)->get('/profile/likes')->assertInertia(fn (Assert $page) => $page
            ->component('ProfileList')
            ->where('which', 'likes')
            ->where('count', 2)
            ->where('groups.0.label', 'This week')
            ->where('groups.0.rows.0.label', 'Note · '.$note->published_at->setTimezone('Africa/Lagos')->format('j M'))
            ->where('groups.0.rows.0.small', '“Salt Season is now in print.”')
            ->where('groups.0.rows.0.thumb', 'The Ember Gate')
            ->where('groups.1.rows.0.label', 'Chapter 2 · Lanterns')
            ->where('groups.1.rows.0.small', 'The Ember Gate'));
    }

    public function test_comments_show_their_replies_and_the_latest_one(): void
    {
        $user = User::factory()->create(['username' => 'adaeze']);
        [$ember, $items] = $this->novel('The Ember Gate', 3);
        $mine = Comment::factory()->for($user)->create(['work_id' => $ember->id, 'work_item_id' => $items[2]->id, 'body' => 'The gate in the mud.', 'likes_count' => 12]);
        Comment::factory()->replyTo($mine)->create(['body' => 'First.']);
        $tolu = User::factory()->create(['name' => 'Tolu Ade']);
        Comment::factory()->replyTo($mine)->for($tolu)->create(['body' => 'she really does.']);
        Comment::factory()->review()->for($user)->create(['work_id' => $ember->id]);

        $this->actingAs($user)->get('/profile')->assertInertia(fn (Assert $page) => $page
            ->where('comments.count', 1)
            ->where('comments.rows.0.where', 'Chapter 3 · The Ember Gate')
            ->where('comments.rows.0.href', '/library/'.$ember->slug.'/3#comments')
            ->where('comments.rows.0.likes', 12)
            ->where('comments.rows.0.replies', 2)
            ->where('comments.rows.0.reply.name', 'Tolu')
            ->where('comments.rows.0.reply.tag', '@adaeze')
            ->where('comments.rows.0.reply.text', 'she really does.'));
    }

    public function test_reviewers_see_what_is_shared_with_them_and_their_open_marks(): void
    {
        $reviewer = User::factory()->reviewer()->create();
        $draft = Work::factory()->novel()->reviewers()->create(['title' => 'The Glass Orchard']);
        $draft->reviewers()->attach($reviewer, ['created_at' => now()->subDays(3), 'updated_at' => now()]);
        Comment::factory()->review()->for($reviewer)->count(2)->create(['work_id' => $draft->id]);
        Comment::factory()->review()->for($reviewer)->create(['work_id' => $draft->id, 'resolved_at' => now()]);

        $this->actingAs($reviewer)->get('/profile')->assertInertia(fn (Assert $page) => $page
            ->where('shared.0.title', 'The Glass Orchard')
            ->where('shared.0.private', true)
            ->where('shared.0.shared', 'Shared '.now()->subDays(3)->setTimezone('Africa/Lagos')->format('j M'))
            ->where('shared.0.open', 2));

        $this->actingAs(User::factory()->create())->get('/profile')->assertInertia(fn (Assert $page) => $page->where('shared', []));
    }

    public function test_settings_shows_your_account_and_choices(): void
    {
        $user = User::factory()->googleOnly()->create(['username' => 'kemi', 'username_changed_at' => now()->subDays(3), 'preferences' => ['reading' => ['font' => 'sans']]]);
        $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'g-1']);

        $this->actingAs($user->fresh())->get('/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings')
            ->where('account.google', true)
            ->where('account.hasPassword', false)
            ->where('account.lockedUntil', now()->addDays(27)->setTimezone('Africa/Lagos')->format('j M'))
            ->where('reading.font', 'sans')
            ->where('reading.size', 2)
            ->where('notifications', fn ($notifications): bool => $notifications['notes.email'] === false && $notifications['chapters.email'] === true));
    }
}
