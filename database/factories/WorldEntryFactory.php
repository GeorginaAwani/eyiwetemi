<?php

namespace Database\Factories;

use App\Models\WorldEntry;
use App\Models\WorldGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorldEntry>
 */
class WorldEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'world_group_id' => WorldGroup::factory(),
            'name' => fake()->city(),
            'typed_as' => 'Place',
            'description' => fake()->sentence(),
            'notes' => null,
            'position' => 0,
        ];
    }
}
