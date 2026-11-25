<?php

namespace Database\Factories;

use App\Enums\StatDimension;
use App\Models\DailyBreakdown;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyBreakdown>
 */
class DailyBreakdownFactory extends Factory
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
            'dimension' => StatDimension::Country,
            'value' => fake()->country(),
            'reads' => fake()->numberBetween(0, 1000),
        ];
    }
}
