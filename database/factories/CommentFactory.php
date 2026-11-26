<?php

namespace Database\Factories;

use App\Enums\CommentAudience;
use App\Enums\CommentKind;
use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'work_id' => Work::factory(),
            'work_item_id' => null,
            'parent_id' => null,
            'reply_to_user_id' => null,
            'audience' => CommentAudience::Public,
            'kind' => CommentKind::Comment,
            'anchor' => null,
            'suggestion' => null,
            'body' => fake()->paragraph(),
            'status' => null,
            'resolved_at' => null,
            'hidden_at' => null,
            'seen_at' => null,
            'edited_at' => null,
        ];
    }

    /**
     * Indicate that the comment is a review comment, open for the author to act on.
     */
    public function review(): static
    {
        return $this->state(['audience' => CommentAudience::Review, 'status' => CommentStatus::Open]);
    }

    /**
     * Indicate that the comment is a line comment on a chapter's text, from a reviewer.
     */
    public function line(): static
    {
        return $this->state(function (): array {
            $item = WorkItem::factory()->create();

            return [
                'work_id' => $item->work_id,
                'work_item_id' => $item->id,
                'audience' => CommentAudience::Review,
                'kind' => CommentKind::Line,
                'status' => CommentStatus::Open,
                'anchor' => ['block_id' => 'b1', 'start' => 0, 'end' => 12, 'quote' => fake()->sentence(2)],
            ];
        });
    }

    /**
     * Indicate that the comment is hidden from readers.
     */
    public function hidden(): static
    {
        return $this->state(['hidden_at' => now()]);
    }

    /**
     * Indicate that the comment was soft deleted.
     */
    public function deleted(): static
    {
        return $this->state(['body' => null, 'deleted_at' => now()]);
    }

    /**
     * Indicate that the comment answers another comment on the same work.
     */
    public function replyTo(Comment $parent): static
    {
        return $this->state([
            'parent_id' => $parent->id,
            'work_id' => $parent->work_id,
            'work_item_id' => $parent->work_item_id,
            'audience' => $parent->audience,
            'reply_to_user_id' => $parent->user_id,
        ]);
    }
}
