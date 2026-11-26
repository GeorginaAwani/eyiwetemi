<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReaderPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function novel(int $chapters, array $attributes = []): Work
    {
        $work = Work::factory()->novel()->public()->published()->create(['title' => 'The Ember Gate', 'heading_style' => 'chapter_number', ...$attributes]);

        foreach (range(1, $chapters) as $n) {
            WorkItem::factory()->chapter()->published()->for($work)->create([
                'position' => $n,
                'title' => $n === 3 ? 'Ember' : null,
                'published_at' => now()->subDays(10 - $n),
                'live_body' => ['type' => 'doc', 'content' => [
                    ['type' => 'paragraph', 'attrs' => ['id' => 'b1'], 'content' => [['type' => 'text', 'text' => 'The tide went out.'], ['type' => 'text', 'text' => ' Slowly.', 'marks' => [['type' => 'italic']]]]],
                    ['type' => 'sceneBreak', 'attrs' => ['id' => 'b2']],
                    ['type' => 'paragraph', 'attrs' => ['id' => 'b3'], 'content' => [['type' => 'text', 'text' => 'It came back.']]],
                ]],
            ]);
        }

        return $work;
    }

    public function test_a_chapter_reads_its_live_text_with_previous_and_next(): void
    {
        $work = $this->novel(4);

        $this->get('/library/'.$work->slug.'/3')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Reader')
            ->where('item.label', 'Chapter 3')
            ->where('item.title', 'Ember')
            ->where('item.total', 4)
            ->where('item.blocks.0', ['type' => 'p', 'id' => 'b1', 'runs' => [['text' => 'The tide went out.'], ['text' => ' Slowly.', 'em' => true]]])
            ->where('item.blocks.1', ['type' => 'break'])
            ->where('nav.prev', '/library/'.$work->slug.'/2')
            ->where('nav.next', '/library/'.$work->slug.'/4')
            ->count('chapters', 4)
        );
    }

    public function test_the_last_chapter_says_what_comes_next(): void
    {
        $writing = $this->novel(2);
        $finished = $this->novel(2, ['title' => 'Salt Season', 'status' => 'finished']);

        $this->get('/library/'.$writing->slug.'/2')->assertInertia(fn (Assert $page) => $page
            ->where('nav', ['prev' => '/library/'.$writing->slug.'/1', 'next' => null, 'end' => 'Next chapter soon', 'follow' => true]));
        $this->get('/library/'.$finished->slug.'/2')->assertInertia(fn (Assert $page) => $page
            ->where('nav.end', 'The end')->where('nav.follow', false));
    }

    public function test_a_chapter_taken_down_says_so_and_is_skipped(): void
    {
        $work = $this->novel(3);
        $work->items()->where('position', 2)->update(['status' => 'unpublished']);
        WorkItem::factory()->chapter()->for($work)->create(['position' => 4]);

        $this->get('/library/'.$work->slug.'/2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('unavailable.label', 'Chapter 2'));
        $this->get('/library/'.$work->slug.'/1')->assertInertia(fn (Assert $page) => $page
            ->where('nav.next', '/library/'.$work->slug.'/3')
            ->count('chapters', 2));
        $this->get('/library/'.$work->slug.'/4')->assertNotFound();
        $this->get('/library/'.$work->slug.'/9')->assertNotFound();
    }

    public function test_a_book_in_print_lets_you_read_its_free_chapters_only(): void
    {
        $work = $this->novel(3, ['title' => 'The Second Rain', 'status' => 'finished', 'free_chapters' => 1]);

        $this->get('/library/'.$work->slug.'/1')->assertInertia(fn (Assert $page) => $page
            ->where('nav.end', 'The rest is in print')->where('nav.next', null));
        $this->get('/library/'.$work->slug.'/2')->assertRedirect('/library/'.$work->slug.'#in-print');
    }

    public function test_hidden_works_are_not_found(): void
    {
        $private = $this->novel(1, ['title' => 'Private one', 'visibility' => 'private']);

        $this->get('/library/'.$private->slug.'/1')->assertNotFound();
        $this->actingAs(User::factory()->author()->create())->get('/library/'.$private->slug.'/1')->assertOk();
    }

    public function test_only_reader_comments_show_and_a_deleted_one_keeps_its_replies(): void
    {
        $work = $this->novel(1);
        $item = $work->items()->first();
        $reader = User::factory()->reader()->create(['name' => 'Tolu Ade']);
        $author = User::factory()->author()->create();

        $kept = Comment::factory()->create(['work_id' => $work->id, 'work_item_id' => $item->id, 'user_id' => $reader->id, 'body' => 'The boots!']);
        Comment::factory()->replyTo($kept)->create(['user_id' => $author->id, 'reply_to_user_id' => $reader->id, 'body' => 'They matter.']);
        $gone = Comment::factory()->deleted()->create(['work_id' => $work->id, 'work_item_id' => $item->id]);
        Comment::factory()->replyTo($gone)->create(['user_id' => $author->id, 'body' => 'Read chapter 4.']);
        Comment::factory()->deleted()->create(['work_id' => $work->id, 'work_item_id' => $item->id]);
        Comment::factory()->hidden()->create(['work_id' => $work->id, 'work_item_id' => $item->id]);
        Comment::factory()->review()->create(['work_id' => $work->id, 'work_item_id' => $item->id, 'body' => 'Reviewer note']);

        $this->get('/library/'.$work->slug.'/1')->assertInertia(fn (Assert $page) => $page
            ->where('comments.count', 3)
            ->count('comments.list', 2)
            ->where('comments.list.0.body', 'The boots!')
            ->where('comments.list.0.replies.0.name', 'Georgina')
            ->where('comments.list.0.replies.0.author', true)
            ->where('comments.list.0.replies.0.replyTo', 'Tolu Ade')
            ->where('comments.list.1.deleted', true)
            ->where('comments.list.1.body', null)
            ->where('comments.list.1.replies.0.body', 'Read chapter 4.')
        );
    }

    public function test_a_single_poem_opens_straight_into_the_reader_with_its_stanzas(): void
    {
        $poem = Work::factory()->poem()->public()->published()->create(['title' => 'Harmattan']);
        WorkItem::factory()->published()->for($poem)->create([
            'type' => 'piece', 'piece_work_id' => null, 'title' => null, 'position' => 1, 'published_at' => now()->subDay(),
            'live_body' => ['type' => 'doc', 'content' => [
                ['type' => 'poemLine', 'attrs' => ['indent' => 0], 'content' => [['type' => 'text', 'text' => 'The wind comes down']]],
                ['type' => 'poemLine', 'attrs' => ['indent' => 1], 'content' => [['type' => 'text', 'text' => 'from the north']]],
                ['type' => 'poemLine', 'attrs' => ['indent' => 0]],
                ['type' => 'poemLine', 'attrs' => ['indent' => 0], 'content' => [['type' => 'text', 'text' => 'It comes for my lips.']]],
            ]],
        ]);

        $this->get('/library/'.$poem->slug)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Reader')
            ->where('item.isPiece', true)
            ->where('item.poem', true)
            ->where('nav', null)
            ->count('item.blocks', 2)
            ->where('item.blocks.0.lines.1', ['text' => 'from the north', 'indent' => 1])
        );
    }
}
