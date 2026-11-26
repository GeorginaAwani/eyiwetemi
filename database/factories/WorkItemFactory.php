<?php

namespace Database\Factories;

use App\Enums\PublishStatus;
use App\Enums\WorkItemType;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkItem>
 */
class WorkItemFactory extends Factory
{
    /**
     * Positions come from one sequence, so they never clash inside a work.
     */
    private static int $lastPosition = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $draft = EditorDocument::make();

        return [
            'work_id' => Work::factory(),
            'type' => WorkItemType::Chapter,
            'position' => ++self::$lastPosition,
            'piece_work_id' => null,
            'title' => rtrim(fake()->sentence(3), '.'),
            'description' => null,
            'draft_body' => $draft['body'],
            'live_body' => null,
            'draft_text' => $draft['text'],
            'live_text' => null,
            'draft_words' => $draft['words'],
            'live_words' => 0,
            'draft_revision' => 0,
            'draft_saved_at' => now(),
            'status' => PublishStatus::Draft,
            'scheduled_at' => null,
            'published_at' => null,
            'live_updated_at' => null,
            'pov_character_id' => null,
            'epigraph' => null,
            'epigraph_by' => null,
            'soundtrack_url' => null,
            'soundtrack_label' => null,
        ];
    }

    /**
     * Indicate that the item is a chapter.
     */
    public function chapter(): static
    {
        return $this->state(['type' => WorkItemType::Chapter]);
    }

    /**
     * Indicate that the item is a part heading with no body of its own.
     */
    public function part(): static
    {
        return $this->state(fn (): array => [
            'type' => WorkItemType::Part,
            'description' => fake()->sentence(),
            'draft_body' => null,
            'draft_text' => null,
            'draft_words' => 0,
        ]);
    }

    /**
     * Indicate that the item is a piece in a collection, pointing at its own work.
     */
    public function piece(): static
    {
        return $this->state(fn (): array => [
            'type' => WorkItemType::Piece,
            'title' => null,
            'piece_work_id' => Work::factory()->story(),
            'draft_body' => null,
            'draft_text' => null,
            'draft_words' => 0,
        ]);
    }

    /**
     * Indicate that the draft is live, with no unpublished changes.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PublishStatus::Published,
            'live_body' => $attributes['draft_body'],
            'live_text' => $attributes['draft_text'],
            'live_words' => $attributes['draft_words'],
            'published_at' => now()->subWeek(),
            'live_updated_at' => now()->subDay(),
        ]);
    }

    /**
     * Indicate that the item is set to publish later.
     */
    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => PublishStatus::Scheduled,
            'scheduled_at' => now()->addDays(3),
        ]);
    }

    /**
     * Indicate that the item was live and has been taken down.
     */
    public function unpublished(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PublishStatus::Unpublished,
            'live_body' => $attributes['draft_body'],
            'live_text' => $attributes['draft_text'],
            'live_words' => $attributes['draft_words'],
            'published_at' => now()->subMonth(),
            'live_updated_at' => now()->subWeek(),
        ]);
    }
}
