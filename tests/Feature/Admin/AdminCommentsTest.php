<?php

namespace Tests\Feature\Admin;

use App\Enums\CommentKind;
use App\Enums\CommentStatus;
use App\Enums\PublishStatus;
use App\Models\Comment;
use App\Models\Like;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use App\Notifications\CommentReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminCommentsTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $reader;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->author()->create(['name' => 'Georgina Awani']);
        $this->reader = User::factory()->reader()->create(['name' => 'Ife Bello', 'username' => 'ife.bello']);
        $this->reviewer = User::factory()->reviewer()->create(['name' => 'Adaeze Okafor']);
    }

    private function asAdmin(): static
    {
        return $this->actingAs($this->author)->withSession(['admin.verified' => true]);
    }

    private function url(string $path): string
    {
        return 'http://'.config('eyiwetemi.admin_domain').$path;
    }

    private function emberGate(): Work
    {
        return Work::factory()->novel()->create(['title' => 'The Ember Gate', 'title_key' => 'the ember gate', 'slug' => 'the-ember-gate']);
    }

    /**
     * A released chapter, with its own title or none, and its draft as the paragraphs by id.
     *
     * @param  array<string, string>  $paragraphs
     */
    private function chapter(Work $work, int $position, ?string $title, array $paragraphs = ['b1' => 'Plain text.']): WorkItem
    {
        $content = [];

        foreach ($paragraphs as $id => $text) {
            $content[] = ['type' => 'paragraph', 'attrs' => ['id' => $id], 'content' => [['type' => 'text', 'text' => $text]]];
        }

        return WorkItem::factory()->for($work)->create([
            'position' => $position,
            'title' => $title,
            'status' => PublishStatus::Published,
            'published_at' => now()->subWeek(),
            'draft_body' => ['type' => 'doc', 'content' => $content],
            'draft_text' => implode("\n\n", $paragraphs),
            'draft_words' => 9,
            'draft_saved_at' => now(),
        ]);
    }

    /**
     * A reader's (or the writer's) public top-level comment on a chapter.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function comment(WorkItem $item, ?User $by, string $body, array $attributes = []): Comment
    {
        return Comment::factory()->create([
            'user_id' => $by?->id,
            'work_id' => $item->work_id,
            'work_item_id' => $item->id,
            'body' => $body,
            ...$attributes,
        ]);
    }

    /**
     * A reviewer's note: the reviewer's by default, open and unseen.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function note(WorkItem $item, string $body, array $attributes = []): Comment
    {
        return Comment::factory()->review()->create([
            'user_id' => $this->reviewer->id,
            'work_id' => $item->work_id,
            'work_item_id' => $item->id,
            'kind' => CommentKind::Comment,
            'body' => $body,
            ...$attributes,
        ]);
    }

    public function test_the_inbox_carries_the_counts_the_works_with_news_and_the_writers_name(): void
    {
        $work = $this->emberGate();
        $chapter = $this->chapter($work, 1, 'The Lamps');

        $this->comment($chapter, $this->reader, 'Loved this.');
        $this->comment($chapter, $this->reader, 'The lamps were lovely.');
        $this->comment($chapter, $this->reader, 'Hidden one.', ['hidden_at' => now()]);
        $this->comment($chapter, $this->reader, 'Seen already.', ['seen_at' => now()]);
        $this->comment($chapter, $this->author, 'Thank you all.');
        Comment::factory()->replyTo($this->comment($chapter, $this->reader, 'A reply target.', ['seen_at' => now()]))
            ->create(['user_id' => $this->reader->id, 'body' => 'Me too.']);

        $this->note($chapter, 'New line note.');
        $this->note($chapter, 'Seen note.', ['seen_at' => now()]);
        $this->note($chapter, 'Resolved note.', ['status' => 'resolved', 'resolved_at' => now(), 'seen_at' => null]);

        $this->asAdmin()->get($this->url('/comments'))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Comments')
                ->where('counts.r', 3)
                ->where('counts.v', 1)
                ->where('works.r.0.slug', 'the-ember-gate')
                ->where('works.r.0.new', 3)
                ->has('works.r', 1)
                ->has('works.v', 1)
                ->where('mail.r', true)
                ->where('mail.v', true)
                ->where('me.name', 'Georgina Awani')
                ->where('commentsNew', 4));
    }

    public function test_the_tab_and_the_filters_come_from_the_query(): void
    {
        $this->emberGate();

        $this->asAdmin()->get($this->url('/comments?tab=rev&w=the-ember-gate&who=Adaeze%20Okafor'))
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'v')
                ->where('w', 'the-ember-gate')
                ->where('who', 'Adaeze Okafor'));

        $this->asAdmin()->get($this->url('/comments'))
            ->assertInertia(fn ($page) => $page->where('tab', 'r')->where('w', null)->where('who', null));
    }

    public function test_readers_groups_lead_with_the_new_and_the_two_newest_and_count_the_rest_as_earlier(): void
    {
        $work = $this->emberGate();
        $lamps = $this->chapter($work, 1, 'The Lamps');
        $second = $this->chapter($work, 2, null);

        $this->comment($lamps, $this->reader, 'One.', ['created_at' => now()->subMinutes(50), 'seen_at' => now()]);
        $this->comment($lamps, $this->reader, 'Two.', ['created_at' => now()->subMinutes(40), 'seen_at' => now()]);
        $this->comment($lamps, $this->reader, 'Three.', ['created_at' => now()->subMinutes(30), 'seen_at' => now()]);
        $fourth = $this->comment($lamps, $this->reader, 'Four.', ['created_at' => now()->subMinutes(20)]);
        $fifth = $this->comment($lamps, $this->reader, 'Five.', ['created_at' => now()->subMinutes(10)]);
        $this->comment($lamps, $this->author, 'The writer answers.', ['created_at' => now()->subMinute()]);

        foreach ([90, 80, 70] as $minutes) {
            $this->comment($second, $this->reader, 'Older.', ['created_at' => now()->subMinutes($minutes), 'seen_at' => now()]);
        }
        $this->comment($second, $this->reader, 'Newest there.', ['created_at' => now()->subMinutes(60), 'seen_at' => now()]);

        $this->asAdmin()->getJson($this->url('/comments/readers'))
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('more', false)
            ->assertJsonPath('hits', null)
            ->assertJsonPath('groups.0.item', $lamps->id)
            ->assertJsonPath('groups.0.title', 'The Lamps')
            ->assertJsonPath('groups.0.sub', 'The Ember Gate · Chapter 1')
            ->assertJsonPath('groups.0.new', 2)
            ->assertJsonPath('groups.0.earlier', 3)
            ->assertJsonCount(2, 'groups.0.comments')
            ->assertJsonPath('groups.0.comments.0.id', $fifth->id)
            ->assertJsonPath('groups.0.comments.1.id', $fourth->id)
            ->assertJsonPath('groups.0.open', rtrim((string) config('app.url'), '/').'/library/the-ember-gate/1#comments')
            ->assertJsonPath('groups.1.item', $second->id)
            ->assertJsonPath('groups.1.title', 'Chapter 2')
            ->assertJsonPath('groups.1.sub', 'The Ember Gate')
            ->assertJsonPath('groups.1.new', 0)
            ->assertJsonPath('groups.1.earlier', 2)
            ->assertJsonCount(2, 'groups.1.comments')
            ->assertJsonPath('groups.1.open', rtrim((string) config('app.url'), '/').'/library/the-ember-gate/2#comments');
    }

    public function test_hidden_comments_are_listed_flagged_and_the_writers_own_are_left_out(): void
    {
        $work = $this->emberGate();
        $chapter = $this->chapter($work, 1, 'The Lamps');

        $hidden = $this->comment($chapter, $this->reader, 'Taken down.', ['hidden_at' => now(), 'created_at' => now()->subMinutes(5)]);
        $this->comment($chapter, $this->author, 'Mine.', ['created_at' => now()->subMinutes(1)]);
        $this->comment($chapter, $this->reader, 'Still up.', ['seen_at' => now(), 'created_at' => now()->subMinutes(15)]);

        $this->asAdmin()->getJson($this->url('/comments/readers'))
            ->assertOk()
            ->assertJsonCount(2, 'groups.0.comments')
            ->assertJsonPath('groups.0.comments.0.id', $hidden->id)
            ->assertJsonPath('groups.0.comments.0.hidden', true)
            ->assertJsonPath('groups.0.comments.0.new', false)
            ->assertJsonPath('groups.0.new', 0);
    }

    public function test_a_search_matches_text_and_replies_and_lists_every_match(): void
    {
        $work = $this->emberGate();
        $lamps = $this->chapter($work, 1, 'The Lamps');
        $second = $this->chapter($work, 2, null);

        $kettle = $this->comment($lamps, $this->reader, 'The kettle was cold.', ['created_at' => now()->subMinutes(20)]);
        $rain = $this->comment($lamps, $this->reader, 'Loved the rain.', ['created_at' => now()->subMinutes(10)]);
        Comment::factory()->replyTo($rain)->create(['user_id' => $this->reader->id, 'body' => 'Me too, the kettle sang.']);
        $this->comment($second, $this->reader, 'Nothing to see here.');

        $this->asAdmin()->getJson($this->url('/comments/readers?q=kettle'))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('hits', 2)
            ->assertJsonPath('groups.0.item', $lamps->id)
            ->assertJsonPath('groups.0.earlier', 0)
            ->assertJsonCount(2, 'groups.0.comments')
            ->assertJsonPath('groups.0.comments.1.id', $kettle->id);
    }

    public function test_readers_groups_come_five_to_a_page(): void
    {
        $work = $this->emberGate();

        foreach (range(1, 6) as $number) {
            $chapter = $this->chapter($work, $number, 'Part '.$number);
            $this->comment($chapter, $this->reader, 'Comment '.$number, ['created_at' => now()->subMinutes($number)]);
        }

        $this->asAdmin()->getJson($this->url('/comments/readers?page=1'))
            ->assertOk()
            ->assertJsonCount(5, 'groups')
            ->assertJsonPath('total', 6)
            ->assertJsonPath('more', true);

        $this->asAdmin()->getJson($this->url('/comments/readers?page=2'))
            ->assertOk()
            ->assertJsonCount(1, 'groups')
            ->assertJsonPath('more', false);
    }

    public function test_a_chapters_readers_comments_come_newest_first_with_its_hidden_ones(): void
    {
        $work = $this->emberGate();
        $chapter = $this->chapter($work, 1, 'The Lamps');

        $older = $this->comment($chapter, $this->reader, 'Older.', ['created_at' => now()->subMinutes(30)]);
        $newer = $this->comment($chapter, $this->reader, 'Newer.', ['created_at' => now()->subMinutes(10)]);
        $hidden = $this->comment($chapter, $this->reader, 'Hidden.', ['hidden_at' => now(), 'created_at' => now()->subMinutes(20)]);
        $this->comment($chapter, $this->author, 'Mine.', ['created_at' => now()->subMinutes(1)]);

        $this->asAdmin()->getJson($this->url('/comments/readers/'.$chapter->id))
            ->assertOk()
            ->assertJsonCount(3, 'comments')
            ->assertJsonPath('comments.0.id', $newer->id)
            ->assertJsonPath('comments.1.id', $hidden->id)
            ->assertJsonPath('comments.1.hidden', true)
            ->assertJsonPath('comments.2.id', $older->id);
    }

    public function test_replying_to_a_reader_counts_the_reply_tells_the_reader_and_comes_back_as_mine(): void
    {
        Notification::fake();

        $work = $this->emberGate();
        $chapter = $this->chapter($work, 1, 'The Lamps');
        $top = $this->comment($chapter, $this->reader, 'Loved the lamps.');

        $this->asAdmin()->postJson($this->url('/comments/'.$top->id.'/reply'), ['body' => 'Thank you for reading.'])
            ->assertCreated()
            ->assertJsonPath('reply.me', true)
            ->assertJsonPath('reply.text', 'Thank you for reading.')
            ->assertJsonPath('reply.who', $this->author->username);

        $reply = Comment::query()->where('parent_id', $top->id)->sole();
        $this->assertSame($this->author->id, $reply->user_id);
        $this->assertSame($this->reader->id, $reply->reply_to_user_id);
        $this->assertSame($chapter->id, $reply->work_item_id);
        $this->assertSame(1, $chapter->fresh()->comments_count);
        $this->assertSame(1, $work->fresh()->comments_count);
        $this->assertNotNull($top->fresh()->seen_at);

        Notification::assertSentTo($this->reader, CommentReply::class);
    }

    public function test_replying_to_a_reply_answers_the_top_comment_and_the_person_who_wrote_the_reply(): void
    {
        Notification::fake();

        $work = $this->emberGate();
        $chapter = $this->chapter($work, 1, 'The Lamps');
        $top = $this->comment($chapter, $this->reader, 'Loved the lamps.');
        $kemi = User::factory()->reader()->create(['name' => 'Kemi Ade']);
        $reply = Comment::factory()->replyTo($top)->create(['user_id' => $kemi->id, 'body' => 'Which lamp?']);

        $this->asAdmin()->postJson($this->url('/comments/'.$reply->id.'/reply'), ['body' => 'The one by the door.'])
            ->assertCreated();

        $answer = Comment::query()->where('user_id', $this->author->id)->sole();
        $this->assertSame($top->id, $answer->parent_id);
        $this->assertSame($kemi->id, $answer->reply_to_user_id);
    }

    public function test_like_and_unlike_keep_the_count_and_the_writers_flag(): void
    {
        $work = $this->emberGate();
        $chapter = $this->chapter($work, 1, 'The Lamps');
        $top = $this->comment($chapter, $this->reader, 'Loved the lamps.');

        $this->asAdmin()->putJson($this->url('/comments/'.$top->id.'/like'))
            ->assertOk()
            ->assertJsonPath('likes', 1)
            ->assertJsonPath('liked', true);

        $this->assertSame(1, Like::query()->where('likeable_type', 'comment')->where('likeable_id', $top->id)->count());
        $this->assertNotNull($top->fresh()->seen_at);

        $this->asAdmin()->getJson($this->url('/comments/readers'))
            ->assertJsonPath('groups.0.comments.0.liked', true)
            ->assertJsonPath('groups.0.comments.0.likes', 1);

        $this->asAdmin()->deleteJson($this->url('/comments/'.$top->id.'/like'))
            ->assertOk()
            ->assertJsonPath('likes', 0)
            ->assertJsonPath('liked', false);

        $this->assertSame(0, $top->fresh()->likes_count);
    }

    public function test_hiding_takes_a_comment_out_of_the_count_but_it_stays_listed_flagged(): void
    {
        $work = $this->emberGate();
        $chapter = $this->chapter($work, 1, 'The Lamps');
        $top = $this->comment($chapter, $this->reader, 'Too harsh.');
        $this->comment($chapter, $this->reader, 'Fine.');

        $this->asAdmin()->get($this->url('/comments'))->assertInertia(fn ($page) => $page->where('counts.r', 2));

        $this->asAdmin()->patchJson($this->url('/comments/'.$top->id.'/hide'), ['hidden' => true])
            ->assertOk()
            ->assertJsonPath('hidden', true);

        $this->asAdmin()->get($this->url('/comments'))->assertInertia(fn ($page) => $page->where('counts.r', 1));
        $this->asAdmin()->getJson($this->url('/comments/readers'))
            ->assertJsonCount(2, 'groups.0.comments')
            ->assertJsonPath('groups.0.comments.1.id', $top->id)
            ->assertJsonPath('groups.0.comments.1.hidden', true);

        $this->asAdmin()->patchJson($this->url('/comments/'.$top->id.'/hide'), ['hidden' => false])
            ->assertOk()
            ->assertJsonPath('hidden', false);
    }

    public function test_reviewer_notes_are_grouped_by_place_in_the_text_with_their_quote_and_context(): void
    {
        $work = $this->emberGate();
        $lamps = $this->chapter($work, 1, 'The Lamps', [
            'b1' => 'She stood from the door and waited.',
            'b2' => 'Nobody had lit the lamps yet.',
        ]);

        $this->note($lamps, 'Doorway. He is on the threshold.', [
            'kind' => CommentKind::Edit,
            'anchor' => ['block_id' => 'b1', 'start' => 10, 'end' => 23, 'quote' => 'from the door'],
            'suggestion' => ['deleted' => 'from the door', 'inserted' => 'from the doorway'],
            'created_at' => now()->subMinutes(30),
        ]);
        $this->note($lamps, 'Keep this.', [
            'kind' => CommentKind::Line,
            'anchor' => ['block_id' => 'b2', 'start' => 11, 'end' => 24, 'quote' => 'lit the lamps'],
            'created_at' => now()->subMinutes(20),
        ]);
        $this->note($lamps, 'The middle drags.', ['kind' => CommentKind::Comment, 'created_at' => now()->subMinutes(10)]);
        $this->note($lamps, 'The strongest start.', [
            'kind' => CommentKind::Book,
            'work_item_id' => null,
            'created_at' => now()->subMinutes(5),
        ]);
        $this->note($lamps, 'Fine now.', [
            'kind' => CommentKind::Line,
            'status' => 'resolved',
            'resolved_at' => now(),
            'anchor' => ['block_id' => 'b1', 'start' => 0, 'end' => 9, 'quote' => 'She stood'],
            'created_at' => now()->subMinutes(40),
        ]);

        $this->asAdmin()->getJson($this->url('/comments/reviewers'))
            ->assertOk()
            ->assertJsonPath('hits', null)
            ->assertJsonCount(2, 'open')
            ->assertJsonPath('open.0.key', 'w'.$work->id)
            ->assertJsonPath('open.0.item', null)
            ->assertJsonPath('open.0.sub', 'Novel')
            ->assertJsonPath('open.0.notes.0.scope', 'On the whole work')
            ->assertJsonPath('open.1.key', (string) $lamps->id)
            ->assertJsonPath('open.1.title', 'The Lamps')
            ->assertJsonPath('open.1.sub', 'The Ember Gate · Chapter 1')
            ->assertJsonPath('open.1.open', '/works/the-ember-gate/write/'.$lamps->id)
            ->assertJsonPath('open.1.notes.0.kind', 'edit')
            ->assertJsonPath('open.1.notes.0.pre', 'She stood ')
            ->assertJsonPath('open.1.notes.0.quote', 'from the door')
            ->assertJsonPath('open.1.notes.0.post', ' and waited.')
            ->assertJsonPath('open.1.notes.0.del', 'from the door')
            ->assertJsonPath('open.1.notes.0.ins', 'from the doorway')
            ->assertJsonPath('open.1.notes.1.kind', 'line')
            ->assertJsonPath('open.1.notes.1.pre', 'Nobody had ')
            ->assertJsonPath('open.1.notes.1.post', ' yet.')
            ->assertJsonPath('open.1.notes.2.kind', 'comment')
            ->assertJsonPath('open.1.notes.2.scope', 'On the whole chapter')
            ->assertJsonPath('open.1.notes.2.quote', '')
            ->assertJsonCount(1, 'done')
            ->assertJsonPath('done.0.notes.0.status', 'resolved')
            ->assertJsonPath('done.0.notes.0.pre', '')
            ->assertJsonPath('done.0.notes.0.post', ' from the door and waited.');
    }

    public function test_a_reviewer_search_and_filter_narrow_the_notes(): void
    {
        $work = $this->emberGate();
        $lamps = $this->chapter($work, 1, 'The Lamps', ['b1' => 'She stood from the door.', 'b2' => 'Nobody had lit the lamps yet.']);
        $kemi = User::factory()->reviewer()->create(['name' => 'Kemi Ade']);

        $this->note($lamps, 'Slow in the middle.', [
            'kind' => CommentKind::Line,
            'anchor' => ['block_id' => 'b2', 'start' => 11, 'end' => 24, 'quote' => 'lit the lamps'],
        ]);
        $this->note($lamps, 'Who is “them”?', [
            'user_id' => $kemi->id,
            'kind' => CommentKind::Line,
            'anchor' => ['block_id' => 'b2', 'start' => 0, 'end' => 6, 'quote' => 'Nobody'],
        ]);

        $this->asAdmin()->getJson($this->url('/comments/reviewers?who=Kemi%20Ade'))
            ->assertOk()
            ->assertJsonCount(1, 'open.0.notes')
            ->assertJsonPath('open.0.notes.0.who', 'Kemi Ade');

        $this->asAdmin()->getJson($this->url('/comments/reviewers?q=middle'))
            ->assertOk()
            ->assertJsonPath('hits', 1)
            ->assertJsonCount(1, 'open.0.notes');

        $this->asAdmin()->getJson($this->url('/comments/reviewers?q=Nobody'))
            ->assertOk()
            ->assertJsonPath('hits', 1)
            ->assertJsonPath('open.0.notes.0.who', 'Kemi Ade');
    }

    public function test_accepting_an_edit_changes_the_draft_and_the_note_is_done(): void
    {
        $work = $this->emberGate();
        $lamps = $this->chapter($work, 1, 'The Lamps', ['b1' => 'She stood from the door and waited.']);
        $edit = $this->note($lamps, 'Doorway.', [
            'kind' => CommentKind::Edit,
            'anchor' => ['block_id' => 'b1', 'start' => 10, 'end' => 23, 'quote' => 'from the door'],
            'suggestion' => ['deleted' => 'from the door', 'inserted' => 'from the doorway'],
        ]);

        $this->asAdmin()->patchJson($this->url('/comments/'.$edit->id.'/status'), ['status' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('note.status', 'accepted')
            ->assertJsonPath('note.new', false)
            ->assertJsonPath('note.quote', 'from the doorway')
            ->assertJsonPath('note.post', ' and waited.');

        $lamps->refresh();
        $this->assertSame('She stood from the doorway and waited.', $lamps->draft_text);
        $this->assertSame(1, $lamps->draft_revision);
        $this->assertEquals(
            [['type' => 'text', 'text' => 'She stood from the doorway and waited.']],
            $lamps->draft_body['content'][0]['content'],
        );
        $this->assertSame(CommentStatus::Accepted, $edit->fresh()->status);
        $this->assertNotNull($edit->fresh()->resolved_at);
    }

    public function test_accepting_an_edit_whose_text_has_changed_is_a_conflict_and_saves_nothing(): void
    {
        $work = $this->emberGate();
        $lamps = $this->chapter($work, 1, 'The Lamps', ['b1' => 'She stood by the window.']);
        $before = $lamps->draft_body;
        $edit = $this->note($lamps, 'Doorway.', [
            'kind' => CommentKind::Edit,
            'anchor' => ['block_id' => 'b1', 'start' => 10, 'end' => 23, 'quote' => 'from the door'],
            'suggestion' => ['deleted' => 'from the door', 'inserted' => 'from the doorway'],
        ]);

        $this->asAdmin()->patchJson($this->url('/comments/'.$edit->id.'/status'), ['status' => 'accepted'])
            ->assertConflict()
            ->assertJsonPath('message', 'That line has changed.');

        $this->assertEquals($before, $lamps->fresh()->draft_body);
        $this->assertSame(CommentStatus::Open, $edit->fresh()->status);
    }

    public function test_notes_can_be_resolved_and_reopened_and_only_open_edits_are_accepted(): void
    {
        $work = $this->emberGate();
        $lamps = $this->chapter($work, 1, 'The Lamps', ['b1' => 'She stood from the door.']);
        $line = $this->note($lamps, 'Keep this.', ['kind' => CommentKind::Line, 'anchor' => ['block_id' => 'b1', 'start' => 0, 'end' => 9, 'quote' => 'She stood']]);
        $edit = $this->note($lamps, 'Doorway.', [
            'kind' => CommentKind::Edit,
            'anchor' => ['block_id' => 'b1', 'start' => 10, 'end' => 23, 'quote' => 'from the door'],
            'suggestion' => ['deleted' => 'from the door', 'inserted' => 'from the doorway'],
        ]);

        $this->asAdmin()->patchJson($this->url('/comments/'.$line->id.'/status'), ['status' => 'accepted'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->asAdmin()->patchJson($this->url('/comments/'.$line->id.'/status'), ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('note.status', 'resolved');
        $this->assertNotNull($line->fresh()->resolved_at);

        $this->asAdmin()->patchJson($this->url('/comments/'.$line->id.'/status'), ['status' => 'open'])
            ->assertOk()
            ->assertJsonPath('note.status', 'open');
        $this->assertNull($line->fresh()->resolved_at);

        $this->asAdmin()->patchJson($this->url('/comments/'.$line->id.'/status'), ['status' => 'open'])
            ->assertOk();

        $this->asAdmin()->patchJson($this->url('/comments/'.$edit->id.'/status'), ['status' => 'resolved'])
            ->assertOk();

        $this->asAdmin()->patchJson($this->url('/comments/'.$edit->id.'/status'), ['status' => 'accepted'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_only_reader_comments_can_be_liked_or_hidden_and_only_reviewer_notes_change_status(): void
    {
        $work = $this->emberGate();
        $chapter = $this->chapter($work, 1, 'The Lamps');
        $top = $this->comment($chapter, $this->reader, 'Loved it.');
        $reply = Comment::factory()->replyTo($top)->create(['user_id' => $this->reader->id, 'body' => 'Agreed.']);
        $note = $this->note($chapter, 'Keep this.');

        $this->asAdmin()->putJson($this->url('/comments/'.$note->id.'/like'))->assertNotFound();
        $this->asAdmin()->patchJson($this->url('/comments/'.$note->id.'/hide'), ['hidden' => true])->assertNotFound();
        $this->asAdmin()->patchJson($this->url('/comments/'.$reply->id.'/hide'), ['hidden' => true])->assertNotFound();
        $this->asAdmin()->patchJson($this->url('/comments/'.$top->id.'/status'), ['status' => 'resolved'])->assertNotFound();
    }

    public function test_seen_marks_the_readers_and_reviewers_in_view_and_not_the_writers_own(): void
    {
        $work = $this->emberGate();
        $chapter = $this->chapter($work, 1, 'The Lamps');
        $reader = $this->comment($chapter, $this->reader, 'Hello.');
        $note = $this->note($chapter, 'Look here.');
        $own = $this->comment($chapter, $this->author, 'Mine.');

        $this->asAdmin()->postJson($this->url('/comments/seen'), ['ids' => [$reader->id, $note->id, $own->id]])
            ->assertOk()
            ->assertJsonPath('counts.r', 0)
            ->assertJsonPath('counts.v', 0);

        $this->assertNotNull($reader->fresh()->seen_at);
        $this->assertNotNull($note->fresh()->seen_at);
        $this->assertNull($own->fresh()->seen_at);
    }

    public function test_a_new_reply_makes_its_thread_new_once_and_seeing_the_thread_sees_its_replies(): void
    {
        $work = $this->emberGate();
        $chapter = $this->chapter($work, 1, 'The Lamps');
        $top = $this->comment($chapter, $this->reader, 'Hello.', ['seen_at' => now()->subDay()]);
        $other = User::factory()->reader()->create();
        $this->comment($chapter, $other, 'Me too.', ['parent_id' => $top->id, 'seen_at' => null]);
        $this->comment($chapter, $other, 'And again.', ['parent_id' => $top->id, 'seen_at' => null]);

        $this->asAdmin()->getJson($this->url('/comments/readers'))
            ->assertJsonPath('groups.0.new', 1)
            ->assertJsonPath('groups.0.comments.0.new', true);

        $this->asAdmin()->postJson($this->url('/comments/seen'), ['ids' => [$top->id]])->assertJsonPath('counts.r', 0);
        $this->assertSame(0, Comment::query()->where('parent_id', $top->id)->whereNull('seen_at')->count());
    }

    public function test_the_writing_screen_carries_the_chapters_open_notes_with_where_they_start(): void
    {
        $work = $this->emberGate();
        $lamps = $this->chapter($work, 1, 'The Lamps', ['b1' => 'She stood from the door and waited.']);
        $this->note($lamps, 'Doorway.', [
            'kind' => CommentKind::Edit,
            'anchor' => ['block_id' => 'b1', 'start' => 10, 'end' => 23, 'quote' => 'from the door'],
            'suggestion' => ['deleted' => 'from the door', 'inserted' => 'from the doorway'],
        ]);
        $this->note($lamps, 'Done already.', ['status' => CommentStatus::Resolved, 'resolved_at' => now()]);

        $this->asAdmin()->get($this->url('/works/the-ember-gate/write/'.$lamps->id))
            ->assertInertia(fn ($page) => $page->component('Admin/Write')
                ->has('notes', 1)
                ->where('notes.0.block', 'b1')
                ->where('notes.0.start', 10)
                ->where('notes.0.ins', 'from the doorway'));
    }

    public function test_an_edit_the_writing_screen_already_made_is_accepted_without_touching_the_draft(): void
    {
        $work = $this->emberGate();
        $lamps = $this->chapter($work, 1, 'The Lamps', ['b1' => 'She stood from the doorway and waited.']);
        $edit = $this->note($lamps, 'Doorway.', [
            'kind' => CommentKind::Edit,
            'anchor' => ['block_id' => 'b1', 'start' => 10, 'end' => 23, 'quote' => 'from the door'],
            'suggestion' => ['deleted' => 'from the door', 'inserted' => 'from the doorway'],
        ]);
        $before = $lamps->draft_body;

        $this->asAdmin()->patchJson($this->url('/comments/'.$edit->id.'/status'), ['status' => 'accepted', 'apply' => false])
            ->assertOk()
            ->assertJsonPath('note.status', 'accepted');

        $this->assertEquals($before, $lamps->fresh()->draft_body);
    }

    public function test_the_mail_switches_are_stored_and_default_on(): void
    {
        $this->asAdmin()->patchJson($this->url('/comments/mail'), ['key' => 'r', 'on' => false])
            ->assertOk()
            ->assertJsonPath('mail.r', false)
            ->assertJsonPath('mail.v', true);

        $this->assertFalse(SiteSetting::get('mail.readers_daily'));

        $this->asAdmin()->get($this->url('/comments'))
            ->assertInertia(fn ($page) => $page->where('mail.r', false)->where('mail.v', true));

        $this->asAdmin()->patchJson($this->url('/comments/mail'), ['key' => 'v', 'on' => false])
            ->assertOk()
            ->assertJsonPath('mail.v', false);
    }

    public function test_guests_get_401_on_json_and_are_sent_to_the_sign_in_on_pages(): void
    {
        $work = $this->emberGate();
        $chapter = $this->chapter($work, 1, 'The Lamps');
        $top = $this->comment($chapter, $this->reader, 'Hello.');

        $this->getJson($this->url('/comments/readers'))->assertUnauthorized();
        $this->getJson($this->url('/comments/reviewers'))->assertUnauthorized();
        $this->postJson($this->url('/comments/'.$top->id.'/reply'), ['body' => 'Hi'])->assertUnauthorized();
        $this->putJson($this->url('/comments/'.$top->id.'/like'))->assertUnauthorized();
        $this->get($this->url('/comments'))->assertRedirect(route('admin.signin'));
    }
}
