<?php

namespace Database\Factories;

use App\Models\Work;
use App\Models\WritingDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WritingDay>
 */
class WritingDayFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'day' => fake()->unique()->date(),
            'work_id' => Work::factory(),
            'words_added' => fake()->numberBetween(0, 3000),
            'words_cut' => fake()->numberBetween(0, 800),
        ];
    }
}
