<?php

namespace Database\Factories;

use App\Models\Work;
use App\Models\WorldGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorldGroup>
 */
class WorldGroupFactory extends Factory
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
            'position' => 0,
        ];
    }
}
