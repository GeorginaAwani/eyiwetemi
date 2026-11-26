<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use App\Notifications\CommentReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CommentPostingTest extends TestCase
{
    use RefreshDatabase;

    private function chapter(?Work $work = null, int $position = 1): WorkItem
    {
        $work ??= Work::factory()->novel()->public()->published()->create();

        return WorkItem::factory()->chapter()->published()->for($work)->create(['position' => $position, 'published_at' => now()->subDay()]);
    }

    public function test_guests_cannot_post_a_comment(): void
    {
        $this->postJson('/comments', ['work_item_id' => $this->chapter()->id, 'body' => 'Nice.'])->assertUnauthorized();

        $this->assertDatabaseCount('comments', 0);
    }

    public function test_a_comment_is_saved_counted_and_returned_in_the_reader_shape(): void
    {
        $item = $this->chapter();
        $reader = User::factory()->create(['name' => 'Tolu Ade']);

        $this->actingAs($reader)->postJson('/comments', ['work_item_id' => $item->id, 'body' => '  The boots!  '])
            ->assertCreated()
            ->assertJsonStructure(['comment' => ['id', 'name', 'username', 'seed', 'photo', 'author', 'replyTo', 'body', 'when', 'likes', 'liked', 'deleted', 'replies', 'parent'], 'count'])
            ->assertJsonPath('comment.name', 'Tolu Ade')
            ->assertJsonPath('comment.body', 'The boots!')
            ->assertJsonPath('comment.when', 'now')
            ->assertJsonPath('comment.liked', false)
            ->assertJsonPath('comment.deleted', false)
            ->assertJsonPath('comment.replies', [])
            ->assertJsonPath('comment.parent', null)
            ->assertJsonPath('count', 1);

        $this->assertDatabaseHas('comments', [
            'user_id' => $reader->id,
            'work_id' => $item->work_id,
            'work_item_id' => $item->id,
            'parent_id' => null,
            'reply_to_user_id' => null,
            'audience' => 'public',
            'body' => 'The boots!',
        ]);
        $this->assertSame(1, $item->fresh()->comments_count);
        $this->assertSame(1, $item->work->fresh()->comments_count);
    }

    public function test_a_reply_to_a_reply_sits_under_the_top_comment_and_tells_the_writer_it_answers(): void
    {
        Notification::fake([CommentReply::class]);
        $item = $this->chapter();
        $topWriter = User::factory()->create(['name' => 'Ada Okafor']);
        $replier = User::factory()->create(['name' => 'Bisi Lawal']);
        $poster = User::factory()->create(['name' => 'Chidi Eze']);
        $top = Comment::factory()->create(['user_id' => $topWriter->id, 'work_id' => $item->work_id, 'work_item_id' => $item->id]);
        $reply = Comment::factory()->replyTo($top)->create(['user_id' => $replier->id]);

        $this->actingAs($poster)->postJson('/comments', ['work_item_id' => $item->id, 'body' => 'Tell me more.', 'reply_to' => $reply->id])
            ->assertCreated()
            ->assertJsonPath('comment.parent', $top->id)
            ->assertJsonPath('comment.replyTo', 'Bisi Lawal')
            ->assertJsonMissingPath('comment.replies');

        $this->assertDatabaseHas('comments', [
            'user_id' => $poster->id,
            'parent_id' => $top->id,
            'reply_to_user_id' => $replier->id,
        ]);
        Notification::assertSentTo($replier, CommentReply::class);
        Notification::assertNotSentTo($topWriter, CommentReply::class);
    }

    public function test_a_reply_must_answer_a_visible_comment_on_the_same_chapter(): void
    {
        $item = $this->chapter();
        $otherChapter = $this->chapter($item->work, position: 2);
        $targets = [
            Comment::factory()->create(['work_id' => $otherChapter->work_id, 'work_item_id' => $otherChapter->id]),
            Comment::factory()->hidden()->create(['work_id' => $item->work_id, 'work_item_id' => $item->id]),
            Comment::factory()->deleted()->create(['work_id' => $item->work_id, 'work_item_id' => $item->id]),
            Comment::factory()->review()->create(['work_id' => $item->work_id, 'work_item_id' => $item->id]),
        ];

        foreach ($targets as $target) {
            $this->actingAs(User::factory()->create())->postJson('/comments', ['work_item_id' => $item->id, 'body' => 'Re.', 'reply_to' => $target->id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('reply_to');
        }

        $this->assertDatabaseCount('comments', count($targets));
    }

    public function test_a_chapter_readers_cannot_see_is_not_found(): void
    {
        $reader = User::factory()->create();
        $private = Work::factory()->novel()->reviewers()->published()->create();
        $privateChapter = $this->chapter($private);
        $unreleased = $this->chapter(Work::factory()->novel()->public()->published()->create(), position: 1);
        $unreleased->update(['published_at' => now()->addDay()]);

        $this->actingAs($reader)->postJson('/comments', ['work_item_id' => $privateChapter->id, 'body' => 'Hi'])->assertNotFound();
        $this->actingAs($reader)->postJson('/comments', ['work_item_id' => $unreleased->id, 'body' => 'Hi'])->assertNotFound();

        $this->assertDatabaseCount('comments', 0);
    }

    public function test_a_body_is_required_and_capped_at_2000_characters(): void
    {
        $item = $this->chapter();
        $reader = User::factory()->create();

        $this->actingAs($reader)->postJson('/comments', ['work_item_id' => $item->id, 'body' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
        $this->actingAs($reader)->postJson('/comments', ['work_item_id' => $item->id, 'body' => str_repeat('a', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
        $this->actingAs($reader)->postJson('/comments', ['work_item_id' => $item->id, 'body' => str_repeat('a', 2000)])
            ->assertCreated();
    }
}
