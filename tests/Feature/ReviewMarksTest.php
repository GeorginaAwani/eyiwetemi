<?php

namespace Tests\Feature;

use App\Enums\CommentAudience;
use App\Enums\CommentKind;
use App\Enums\CommentStatus;
use App\Enums\Visibility;
use App\Models\Comment;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReviewMarksTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $reviewer;

    private Work $work;

    private WorkItem $chapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->author()->create(['name' => 'Georgina Awani']);
        $this->reviewer = User::factory()->reviewer()->create(['name' => 'Adaeze Okafor']);
        $this->work = Work::factory()->novel()->reviewers()->published()->create(['title' => 'The Ember Gate']);
        $this->work->reviewers()->attach($this->reviewer);
        $this->chapter = WorkItem::factory()->chapter()->published()->for($this->work)->create([
            'position' => 1,
            'published_at' => now()->subDay(),
            'live_body' => $this->body(),
        ]);
    }

    /**
     * Two paragraphs: the first has an emoji before the words "went out" and a footnote between them, so the
     * reader's text is "🌊 The tide went out." (the emoji is two UTF-16 units, the footnote none).
     *
     * @return array<string, mixed>
     */
    private function body(): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => 'b1'], 'content' => [
                ['type' => 'text', 'text' => '🌊 The tide '],
                ['type' => 'footnote', 'attrs' => ['id' => 'f1', 'text' => 'A note.']],
                ['type' => 'text', 'text' => 'went out.'],
            ]],
            ['type' => 'paragraph', 'attrs' => ['id' => 'b2'], 'content' => [['type' => 'text', 'text' => 'It came back.']]],
        ]];
    }

    /**
     * A reviewer's open line note on the chapter's first paragraph.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function mark(User $by, array $attributes = []): Comment
    {
        return Comment::factory()->line()->create([
            'user_id' => $by->id,
            'work_id' => $this->work->id,
            'work_item_id' => $this->chapter->id,
            'body' => 'Slower here.',
            'anchor' => ['block_id' => 'b1', 'start' => 11, 'end' => 19, 'quote' => 'went out'],
            ...$attributes,
        ]);
    }

    private function readerPage(?User $as = null): TestResponse
    {
        $request = $as === null ? $this : $this->actingAs($as);

        return $request->get('/library/'.$this->work->slug.'/1');
    }

    public function test_the_reader_shows_a_reviewer_only_their_own_open_marks(): void
    {
        $other = User::factory()->reviewer()->create();
        $this->work->reviewers()->attach($other);
        $mark = $this->mark($this->reviewer);
        $this->mark($this->reviewer, ['status' => CommentStatus::Resolved]);
        $this->mark($other);
        Comment::factory()->review()->create([
            'user_id' => $this->reviewer->id, 'work_id' => $this->work->id, 'work_item_id' => $this->chapter->id,
            'kind' => CommentKind::Comment, 'status' => null, 'anchor' => null, 'body' => 'Just a comment.',
        ]);

        $this->readerPage($this->reviewer)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('review.me.name', 'Adaeze Okafor')
            ->has('review.marks', 1)
            ->where('review.marks.0.id', $mark->id)
            ->where('review.marks.0.kind', 'line')
            ->where('review.marks.0.block', 'b1')
            ->where('review.marks.0.start', 12)
            ->where('review.marks.0.end', 20)
            ->where('review.marks.0.ins', '')
            ->where('review.marks.0.text', 'Slower here.')
            ->where('review.marks.0.at', 'now')
            ->where('review.marks.0.replies', []));
    }

    public function test_a_reviewers_only_chapter_is_not_found_to_anyone_else(): void
    {
        $this->mark($this->reviewer);

        $this->readerPage()->assertNotFound();
        $this->readerPage(User::factory()->reader()->create())->assertNotFound();
    }

    public function test_guests_authors_and_other_readers_get_no_marks_on_a_public_chapter(): void
    {
        $this->mark($this->reviewer);
        $this->work->update(['visibility' => Visibility::Public]);

        $this->readerPage()->assertInertia(fn (Assert $page) => $page->where('review', null));
        $this->readerPage($this->author)->assertInertia(fn (Assert $page) => $page->where('review', null));
        $this->readerPage(User::factory()->reader()->create())->assertInertia(fn (Assert $page) => $page->where('review', null));
    }

    public function test_a_mark_is_placed_in_utf16_units_after_an_emoji_and_a_footnote(): void
    {
        $this->mark($this->reviewer, ['anchor' => ['block_id' => 'b1', 'start' => 11, 'end' => 19, 'quote' => 'went out']]);

        $this->readerPage($this->reviewer)->assertInertia(fn (Assert $page) => $page
            ->where('review.marks.0.start', 12)
            ->where('review.marks.0.end', 20));
    }

    public function test_a_mark_whose_line_has_changed_is_left_out_of_the_reader(): void
    {
        $this->mark($this->reviewer, ['anchor' => ['block_id' => 'b1', 'start' => 0, 'end' => 8, 'quote' => 'went in']]);

        $this->readerPage($this->reviewer)->assertInertia(fn (Assert $page) => $page->has('review.marks', 0));
    }

    public function test_the_replies_show_the_writer_by_her_first_name_and_the_viewers_own_flag(): void
    {
        $mark = $this->mark($this->reviewer);
        Comment::factory()->replyTo($mark)->create(['user_id' => $this->author->id, 'body' => 'Thanks, fixed.']);
        Comment::factory()->replyTo($mark)->create(['user_id' => $this->reviewer->id, 'body' => 'Great.']);

        $this->readerPage($this->reviewer)->assertInertia(fn (Assert $page) => $page
            ->has('review.marks.0.replies', 2)
            ->where('review.marks.0.replies.0.author', true)
            ->where('review.marks.0.replies.0.mine', false)
            ->where('review.marks.0.replies.0.name', 'Georgina')
            ->where('review.marks.0.replies.0.text', 'Thanks, fixed.')
            ->where('review.marks.0.replies.0.photo', null)
            ->where('review.marks.0.replies.1.author', false)
            ->where('review.marks.0.replies.1.mine', true)
            ->where('review.marks.0.replies.1.name', 'Adaeze Okafor'));
    }

    public function test_a_reviewer_can_post_a_line_mark_and_gets_it_back_in_the_reader_shape(): void
    {
        $this->actingAs($this->reviewer)->postJson('/review/marks', [
            'item' => $this->chapter->id,
            'block' => 'b1',
            'start' => 12,
            'end' => 20,
            'quote' => 'went out',
            'kind' => 'line',
            'body' => '  Slower here.  ',
        ])->assertCreated()
            ->assertJsonPath('mark.kind', 'line')
            ->assertJsonPath('mark.block', 'b1')
            ->assertJsonPath('mark.start', 12)
            ->assertJsonPath('mark.end', 20)
            ->assertJsonPath('mark.ins', '')
            ->assertJsonPath('mark.text', 'Slower here.')
            ->assertJsonPath('mark.replies', []);

        $this->assertDatabaseHas('comments', [
            'user_id' => $this->reviewer->id,
            'work_id' => $this->work->id,
            'work_item_id' => $this->chapter->id,
            'parent_id' => null,
            'audience' => CommentAudience::Review->value,
            'kind' => CommentKind::Line->value,
            'status' => CommentStatus::Open->value,
            'body' => 'Slower here.',
        ]);
        $comment = Comment::query()->sole();
        $this->assertEquals(['block_id' => 'b1', 'start' => 11, 'end' => 19, 'quote' => 'went out'], $comment->anchor);
        $this->assertNull($comment->suggestion);
    }

    public function test_a_reviewer_can_suggest_an_edit_and_an_empty_insert_deletes_the_words(): void
    {
        $this->actingAs($this->reviewer)->postJson('/review/marks', [
            'item' => $this->chapter->id,
            'block' => 'b1',
            'start' => 12,
            'end' => 20,
            'quote' => 'went out',
            'kind' => 'edit',
            'inserted' => '',
        ])->assertCreated()
            ->assertJsonPath('mark.kind', 'edit')
            ->assertJsonPath('mark.ins', '')
            ->assertJsonPath('mark.text', '');

        $comment = Comment::query()->sole();
        $this->assertSame(CommentKind::Edit, $comment->kind);
        $this->assertSame(['deleted' => 'went out', 'inserted' => ''], $comment->suggestion);
        $this->assertNull($comment->body);
    }

    public function test_a_quote_keeps_the_spaces_at_its_edges(): void
    {
        $this->actingAs($this->reviewer)->postJson('/review/marks', [
            'item' => $this->chapter->id,
            'block' => 'b1',
            'start' => 7,
            'end' => 12,
            'quote' => 'tide ',
            'kind' => 'line',
            'body' => 'Here?',
        ])->assertCreated()
            ->assertJsonPath('mark.start', 7)
            ->assertJsonPath('mark.end', 12);

        $this->assertSame('tide ', Comment::query()->sole()->anchor['quote']);
    }

    public function test_a_mark_whose_quote_is_no_longer_at_its_place_is_refused(): void
    {
        $this->actingAs($this->reviewer)->postJson('/review/marks', [
            'item' => $this->chapter->id,
            'block' => 'b1',
            'start' => 12,
            'end' => 20,
            'quote' => 'went in',
            'kind' => 'line',
            'body' => 'Slower.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['quote' => 'That line has changed.']);

        $this->actingAs($this->reviewer)->postJson('/review/marks', [
            'item' => $this->chapter->id,
            'block' => 'b1',
            'start' => 1,
            'end' => 8,
            'quote' => 'went out',
            'kind' => 'line',
            'body' => 'Slower.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['quote']);

        $this->actingAs($this->reviewer)->postJson('/review/marks', [
            'item' => $this->chapter->id,
            'block' => 'b9',
            'start' => 0,
            'end' => 8,
            'quote' => 'went out',
            'kind' => 'line',
            'body' => 'Slower.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['quote']);

        $this->assertDatabaseCount('comments', 0);
    }

    public function test_a_line_note_needs_a_note_and_an_edit_may_leave_it_out(): void
    {
        $this->actingAs($this->reviewer)->postJson('/review/marks', [
            'item' => $this->chapter->id,
            'block' => 'b1',
            'start' => 12,
            'end' => 20,
            'quote' => 'went out',
            'kind' => 'line',
            'body' => '   ',
        ])->assertUnprocessable()->assertJsonValidationErrors(['body']);

        $this->actingAs($this->reviewer)->postJson('/review/marks', [
            'item' => $this->chapter->id,
            'block' => 'b1',
            'start' => 12,
            'end' => 20,
            'quote' => 'went out',
            'kind' => 'line',
        ])->assertUnprocessable()->assertJsonValidationErrors(['body']);

        $this->actingAs($this->reviewer)->postJson('/review/marks', [
            'item' => $this->chapter->id,
            'block' => 'b1',
            'start' => 12,
            'end' => 20,
            'quote' => 'went out',
            'kind' => 'edit',
            'inserted' => 'left',
        ])->assertCreated()->assertJsonPath('mark.ins', 'left');
    }

    public function test_only_the_works_reviewers_can_mark_it(): void
    {
        $payload = [
            'item' => $this->chapter->id,
            'block' => 'b1',
            'start' => 12,
            'end' => 20,
            'quote' => 'went out',
            'kind' => 'line',
            'body' => 'Slower.',
        ];

        $this->postJson('/review/marks', $payload)->assertUnauthorized();
        $this->actingAs($this->author)->postJson('/review/marks', $payload)->assertForbidden();
        $this->actingAs(User::factory()->reader()->create())->postJson('/review/marks', $payload)->assertForbidden();

        $unreleased = WorkItem::factory()->chapter()->published()->for($this->work)->create([
            'position' => 2,
            'published_at' => now()->addDay(),
            'live_body' => $this->body(),
        ]);
        $this->actingAs($this->reviewer)->postJson('/review/marks', [...$payload, 'item' => $unreleased->id])->assertNotFound();

        $this->assertDatabaseCount('comments', 0);
    }

    public function test_a_reviewer_can_reply_to_their_own_mark(): void
    {
        $mark = $this->mark($this->reviewer);

        $this->actingAs($this->reviewer)->postJson('/review/marks/'.$mark->id.'/reply', ['body' => '  Thanks, will do.  '])
            ->assertCreated()
            ->assertJsonPath('reply.mine', true)
            ->assertJsonPath('reply.author', false)
            ->assertJsonPath('reply.name', 'Adaeze Okafor')
            ->assertJsonPath('reply.text', 'Thanks, will do.');

        $this->assertDatabaseHas('comments', [
            'user_id' => $this->reviewer->id,
            'work_item_id' => $this->chapter->id,
            'parent_id' => $mark->id,
            'audience' => CommentAudience::Review->value,
            'kind' => CommentKind::Comment->value,
            'status' => null,
            'body' => 'Thanks, will do.',
        ]);
    }

    public function test_a_reviewer_taken_off_the_work_cannot_reply_any_more(): void
    {
        $mark = $this->mark($this->reviewer);
        $this->chapter->work->reviewers()->detach($this->reviewer->id);

        $this->actingAs($this->reviewer)->postJson('/review/marks/'.$mark->id.'/reply', ['body' => 'Still here.'])->assertForbidden();
        $this->assertDatabaseMissing('comments', ['parent_id' => $mark->id]);
    }

    public function test_a_reviewer_cannot_reply_to_someone_elses_mark_or_to_a_reply(): void
    {
        $other = User::factory()->reviewer()->create();
        $this->work->reviewers()->attach($other);
        $theirs = $this->mark($other);
        $mine = $this->mark($this->reviewer);
        $reply = Comment::factory()->replyTo($mine)->create(['user_id' => $this->reviewer->id]);

        $this->actingAs($this->reviewer)->postJson('/review/marks/'.$theirs->id.'/reply', ['body' => 'Hi.'])->assertNotFound();
        $this->actingAs($this->reviewer)->postJson('/review/marks/'.$reply->id.'/reply', ['body' => 'Hi.'])->assertNotFound();

        $this->assertDatabaseCount('comments', 3);
    }
}
