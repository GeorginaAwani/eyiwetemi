<?php

namespace Database\Factories;

use App\Models\Character;
use App\Models\CharacterLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CharacterLink>
 */
class CharacterLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'character_id' => Character::factory(),
            'other_character_id' => Character::factory(),
            'label' => fake()->randomElement(['sister', 'friend', 'rival', 'mentor']),
        ];
    }
}
