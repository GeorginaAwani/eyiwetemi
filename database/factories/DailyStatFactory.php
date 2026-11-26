<?php

namespace Database\Factories;

use App\Enums\StatSubject;
use App\Models\DailyStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyStat>
 */
class DailyStatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $reads = fake()->numberBetween(0, 5000);

        return [
            'day' => fake()->unique()->date(),
            'subject' => StatSubject::Site,
            'subject_id' => 0,
            'work_id' => null,
            'reads' => $reads,
            'readers' => fake()->numberBetween(0, $reads),
            'finished' => fake()->numberBetween(0, 200),
        ];
    }
}
