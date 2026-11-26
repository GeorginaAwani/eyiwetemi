<?php

namespace Tests\Feature\Database;

use App\Models\Character;
use App\Models\Comment;
use App\Models\Follow;
use App\Models\Like;
use App\Models\ReadingProgress;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_title_key_is_unique(): void
    {
        Work::factory()->create(['title' => 'The Quiet Year']);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(fn () => Work::factory()->create(['title' => 'the quiet year']));
    }

    public function test_slug_is_unique(): void
    {
        Work::factory()->create(['slug' => 'same-slug']);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(fn () => Work::factory()->create(['slug' => 'same-slug']));
    }

    public function test_a_piece_can_be_in_only_one_collection(): void
    {
        $piece = Work::factory()->story()->create();
        $firstCollection = Work::factory()->collection()->create();
        $secondCollection = Work::factory()->collection()->create();

        WorkItem::factory()->piece()->create([
            'work_id' => $firstCollection->id,
            'piece_work_id' => $piece->id,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(fn () => WorkItem::factory()->piece()->create([
            'work_id' => $secondCollection->id,
            'piece_work_id' => $piece->id,
        ]));
    }

    public function test_chapter_positions_can_be_swapped_inside_one_transaction(): void
    {
        $work = Work::factory()->create();
        $first = WorkItem::factory()->for($work)->create(['position' => 1]);
        $second = WorkItem::factory()->for($work)->create(['position' => 2]);

        DB::transaction(function () use ($first, $second): void {
            $first->update(['position' => 2]);
            $second->update(['position' => 1]);
        });

        $this->assertSame(2, $first->fresh()->position);
        $this->assertSame(1, $second->fresh()->position);
    }

    public function test_a_duplicate_chapter_position_fails_when_the_transaction_is_checked(): void
    {
        $work = Work::factory()->create();
        WorkItem::factory()->for($work)->create(['position' => 1]);
        $second = WorkItem::factory()->for($work)->create(['position' => 2]);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function () use ($second): void {
            $second->update(['position' => 1]);

            // Commit runs the deferred check; forcing it here shows the duplicate is rejected.
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }

    public function test_a_user_can_like_a_target_only_once(): void
    {
        $user = User::factory()->create();
        $item = WorkItem::factory()->create();
        Like::factory()->for($item, 'likeable')->create(['user_id' => $user->id]);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(fn () => Like::factory()->for($item, 'likeable')->create(['user_id' => $user->id]));
    }

    public function test_a_user_can_follow_a_target_only_once(): void
    {
        $user = User::factory()->create();
        $work = Work::factory()->create();
        Follow::factory()->for($work, 'followable')->create(['user_id' => $user->id]);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(fn () => Follow::factory()->for($work, 'followable')->create(['user_id' => $user->id]));
    }

    public function test_deleting_a_work_removes_its_items_characters_and_comments(): void
    {
        $work = Work::factory()->create();
        $item = WorkItem::factory()->for($work)->create();
        $character = Character::factory()->create(['work_id' => $work->id]);
        $comment = Comment::factory()->create(['work_id' => $work->id, 'work_item_id' => $item->id]);

        $work->delete();

        $this->assertDatabaseMissing('work_items', ['id' => $item->id]);
        $this->assertDatabaseMissing('characters', ['id' => $character->id]);
        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
    }

    public function test_deleting_a_user_keeps_their_comments_anonymised_and_removes_their_reactions(): void
    {
        $user = User::factory()->create();
        $work = Work::factory()->create();
        $item = WorkItem::factory()->for($work)->create();
        $comment = Comment::factory()->create(['user_id' => $user->id, 'work_id' => $work->id]);
        $like = Like::factory()->for($item, 'likeable')->create(['user_id' => $user->id]);
        $follow = Follow::factory()->for($work, 'followable')->create(['user_id' => $user->id]);
        $progress = ReadingProgress::factory()->create(['user_id' => $user->id, 'work_item_id' => $item->id]);

        $user->delete();

        $this->assertDatabaseHas('comments', ['id' => $comment->id, 'user_id' => null]);
        $this->assertDatabaseMissing('likes', ['id' => $like->id]);
        $this->assertDatabaseMissing('follows', ['id' => $follow->id]);
        $this->assertDatabaseMissing('reading_progress', ['id' => $progress->id]);
    }

    public function test_deleting_a_character_clears_the_pov_on_its_chapters(): void
    {
        $work = Work::factory()->create();
        $character = Character::factory()->create(['work_id' => $work->id]);
        $item = WorkItem::factory()->for($work)->create(['pov_character_id' => $character->id]);

        $character->delete();

        $this->assertNull($item->fresh()->pov_character_id);
    }
}
