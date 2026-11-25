<?php

namespace Database\Factories;

use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'disk' => 'public',
            'path' => 'media/'.Str::lower(Str::random(40)).'.jpg',
            'original_name' => fake()->word().'.jpg',
            'mime' => 'image/jpeg',
            'size' => fake()->numberBetween(20_000, 3_000_000),
            'width' => 1200,
            'height' => 1600,
            'alt' => fake()->sentence(),
            'credit' => fake()->name(),
            'focal_x' => fake()->randomFloat(4, 0, 1),
            'focal_y' => fake()->randomFloat(4, 0, 1),
            'uploaded_by' => null,
        ];
    }
}
