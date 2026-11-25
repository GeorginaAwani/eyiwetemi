<?php

namespace Database\Factories;

use App\Enums\SendStatus;
use App\Models\NewsletterSend;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewsletterSend>
 */
class NewsletterSendFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scheduled_for' => now()->addDays(fake()->numberBetween(1, 30)),
            'status' => SendStatus::Planned,
            'intro_lines' => null,
            'sent_at' => null,
            'sent_count' => 0,
            'bounced_count' => 0,
            'stopped_count' => 0,
        ];
    }

    /**
     * Indicate that the send went out.
     */
    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => SendStatus::Sent,
            'sent_at' => now()->subDay(),
            'sent_count' => fake()->numberBetween(1, 500),
        ]);
    }
}
