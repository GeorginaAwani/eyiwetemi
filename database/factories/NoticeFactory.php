<?php

namespace Database\Factories;

use App\Models\Notice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notice>
 */
class NoticeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'text' => fake()->sentence(8),
            'link_target' => null,
            'link_url' => null,
            'starts_at' => now()->subHour(),
            'ends_at' => null,
            'taken_down_at' => null,
            'closed_count' => 0,
        ];
    }

    /**
     * Indicate that the notice has not started yet.
     */
    public function scheduled(): static
    {
        return $this->state(['starts_at' => now()->addDay()]);
    }

    /**
     * Indicate that the notice has run its course.
     */
    public function expired(): static
    {
        return $this->state(['ends_at' => now()->subMinute()]);
    }

    /**
     * Indicate that the notice was taken down by the owner.
     */
    public function takenDown(): static
    {
        return $this->state(['taken_down_at' => now()]);
    }
}
