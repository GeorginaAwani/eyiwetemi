<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Note;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationsPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $data
     */
    private function notify(User $user, array $data, Carbon $at, bool $read = false): string
    {
        $id = (string) Str::uuid();
        $user->notifications()->create(['id' => $id, 'type' => 'test', 'data' => $data, 'read_at' => $read ? $at : null, 'created_at' => $at, 'updated_at' => $at]);

        return $id;
    }

    public function test_the_page_groups_new_this_week_and_months_with_their_wording(): void
    {
        $user = User::factory()->create();
        $work = Work::factory()->novel()->public()->published()->create(['title' => 'The Ember Gate']);
        foreach (range(1, 3) as $n) {
            WorkItem::factory()->chapter()->published()->for($work)->create(['position' => $n, 'title' => $n === 3 ? 'Low tide' : null, 'live_words' => 3450]);
        }
        $chapter = $work->items()->where('position', 3)->first();
        $note = Note::factory()->published()->create(['live_text' => 'Chapter 8 will be a week late. Sorry.', 'work_id' => $work->id]);
        $poem = Work::factory()->poem()->public()->published()->create(['title' => 'Tin Roof']);
        WorkItem::factory()->piece()->published()->for($poem)->create(['live_words' => 300]);

        $this->notify($user, ['kind' => 'chapter', 'work_item_id' => $chapter->id], now()->subHours(2));
        $this->notify($user, ['kind' => 'note', 'note_id' => $note->id], now()->subDays(3), read: true);
        $this->notify($user, ['kind' => 'work', 'work_id' => $poem->id], now()->subDays(40), read: true);
        $this->notify($user, ['kind' => 'chapter', 'work_item_id' => 999999], now()->subHour());

        $this->actingAs($user)->get('/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Notifications')
            ->where('total', 4)
            ->where('unread', 2)
            ->has('page.rows', 3)
            ->where('page.rows.0.title', 'Chapter 3 of The Ember Gate is out')
            ->where('page.rows.0.small', 'Low tide · 15 min')
            ->where('page.rows.0.group', 'New')
            ->where('page.rows.0.when', '2 h')
            ->where('page.rows.1.title', 'A note on The Ember Gate')
            ->where('page.rows.1.small', '“Chapter 8 will be a week late.”')
            ->where('page.rows.1.group', 'This week')
            ->where('page.rows.2.title', 'A new poem: Tin Roof')
            ->where('page.rows.2.thumb.type', 'poem')
            ->where('page.rows.2.group', now()->subDays(40)->setTimezone('Africa/Lagos')->format(now()->subDays(40)->year === now()->year ? 'F' : 'F Y')));
    }

    public function test_a_reply_names_who_replied_and_links_to_the_comments(): void
    {
        $me = User::factory()->create();
        $work = Work::factory()->story()->public()->published()->create(['title' => 'Night bus']);
        WorkItem::factory()->piece()->published()->for($work)->create();
        $mine = Comment::factory()->for($me)->create(['work_id' => $work->id]);
        $reply = Comment::factory()->replyTo($mine)->for(User::factory()->create(['name' => 'Tolu Ade']))->create(['body' => 'Same! I missed my stop.']);
        $this->notify($me, ['kind' => 'reply', 'comment_id' => $reply->id], now()->subMinutes(5));

        $this->actingAs($me)->get('/notifications/menu')->assertOk()->assertJson([
            'unread' => 1,
            'rows' => [['title' => 'Tolu replied to your comment on Night bus', 'small' => '“Same! I missed my stop.”', 'href' => '/library/'.$work->slug.'#comments', 'thumb' => ['type' => 'avatar']]],
        ]);
    }

    public function test_older_pages_and_marking_read(): void
    {
        $user = User::factory()->create();
        $note = Note::factory()->published()->create();
        $ids = collect(range(1, 23))->map(fn (int $n): string => $this->notify($user, ['kind' => 'note', 'note_id' => $note->id], now()->subMinutes($n)));

        $first = $this->actingAs($user)->get('/notifications')->assertInertia(fn (Assert $page) => $page->has('page.rows', 20)->where('page.more', true));
        $this->actingAs($user)->getJson('/notifications/older?before='.$ids[19])->assertOk()->assertJsonCount(3, 'rows')->assertJsonPath('more', false);

        $this->actingAs($user)->postJson('/notifications/'.$ids[0].'/read')->assertOk()->assertExactJson(['unread' => 22]);
        $this->actingAs($user)->postJson('/notifications/read')->assertOk()->assertExactJson(['unread' => 0]);
        $this->assertSame(0, $user->unreadNotifications()->count());
    }

    public function test_someone_else_cannot_read_your_notifications(): void
    {
        $owner = User::factory()->create();
        $id = $this->notify($owner, ['kind' => 'note', 'note_id' => Note::factory()->published()->create()->id], now());

        $this->actingAs(User::factory()->create())->postJson('/notifications/'.$id.'/read')->assertOk();
        $this->assertSame(1, $owner->unreadNotifications()->count());
        $this->get('/notifications')->assertOk();
        auth()->logout();
        $this->get('/notifications')->assertRedirect('/');
    }
}
