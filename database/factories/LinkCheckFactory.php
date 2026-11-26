<?php

namespace Database\Factories;

use App\Models\LinkCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LinkCheck>
 */
class LinkCheckFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $url = fake()->unique()->url();

        return [
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'ok' => true,
            'status_code' => 200,
            'checked_at' => now(),
        ];
    }

    /**
     * Indicate that the link is broken.
     */
    public function broken(): static
    {
        return $this->state(['ok' => false, 'status_code' => 404]);
    }
}
