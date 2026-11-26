<?php

namespace Database\Factories;

use App\Models\SweepName;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SweepName>
 */
class SweepNameFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plain = fake()->firstName();

        return [
            'work_id' => Work::factory(),
            'plain' => $plain,
            'readings' => [$plain],
            'position' => 0,
        ];
    }
}
