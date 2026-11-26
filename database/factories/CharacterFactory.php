<?php

namespace Database\Factories;

use App\Models\Character;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Character>
 */
class CharacterFactory extends Factory
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
            'name' => fake()->firstName(),
            'typed_as' => 'Person',
            'aliases' => [],
            'role' => fake()->randomElement(['Protagonist', 'Antagonist', 'Supporting']),
            'notes' => null,
            'position' => 0,
        ];
    }
}
