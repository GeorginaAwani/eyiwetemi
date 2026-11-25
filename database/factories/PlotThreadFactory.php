<?php

namespace Database\Factories;

use App\Models\PlotThread;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlotThread>
 */
class PlotThreadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_id' => Work::factory(),
            'name' => Str::title(fake()->words(2, true)),
            'colour' => fake()->numberBetween(0, 7),
            'position' => 0,
        ];
    }
}
