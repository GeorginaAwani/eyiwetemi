<?php

namespace Database\Factories;

use App\Models\Character;
use App\Models\ResearchItem;
use App\Models\ResearchTie;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResearchTie>
 */
class ResearchTieFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'research_item_id' => ResearchItem::factory(),
            'tieable_type' => 'character',
            'tieable_id' => Character::factory(),
        ];
    }
}
