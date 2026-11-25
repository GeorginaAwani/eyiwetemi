<?php

namespace Database\Factories;

use App\Models\SlugHistory;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SlugHistory>
 */
class SlugHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sluggable_type' => 'work',
            'sluggable_id' => Work::factory(),
            'slug' => Str::lower(Str::random(12)),
        ];
    }
}
