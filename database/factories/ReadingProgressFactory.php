<?php

namespace Database\Factories;

use App\Models\ReadingProgress;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReadingProgress>
 */
class ReadingProgressFactory extends Factory
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
            'work_item_id' => WorkItem::factory(),
            'block_id' => 'b1',
            'percent' => fake()->numberBetween(0, 99),
            'finished_at' => null,
        ];
    }

    /**
     * Indicate that the reader has finished the item.
     */
    public function finished(): static
    {
        return $this->state(['percent' => 100, 'finished_at' => now()]);
    }
}
