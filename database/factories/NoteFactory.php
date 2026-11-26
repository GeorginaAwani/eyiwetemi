<?php

namespace Database\Factories;

use App\Enums\PublishStatus;
use App\Models\Note;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Note>
 */
class NoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $draft = EditorDocument::make();

        return [
            'status' => PublishStatus::Draft,
            'draft_body' => $draft['body'],
            'live_body' => null,
            'draft_text' => $draft['text'],
            'live_text' => null,
            'media_id' => null,
            'draft_media_id' => null,
            'work_id' => null,
            'draft_work_id' => null,
            'notify_followers' => true,
            'scheduled_at' => null,
            'published_at' => null,
            'live_updated_at' => null,
        ];
    }

    /**
     * Indicate that the note is live with no unpublished changes.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PublishStatus::Published,
            'live_body' => $attributes['draft_body'],
            'live_text' => $attributes['draft_text'],
            'published_at' => now()->subDay(),
            'live_updated_at' => now()->subDay(),
        ]);
    }

    /**
     * Indicate that the note is set to publish later.
     */
    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => PublishStatus::Scheduled,
            'scheduled_at' => now()->addDays(2),
        ]);
    }
}
