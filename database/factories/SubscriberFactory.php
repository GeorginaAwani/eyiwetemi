<?php

namespace Database\Factories;

use App\Models\Subscriber;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Subscriber>
 */
class SubscriberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $kinds = ['novels', 'stories', 'poems', 'essays', 'notes'];

        return [
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'kinds' => fake()->randomElements($kinds, fake()->numberBetween(1, count($kinds))),
            'manage_token' => Str::random(64),
            'confirm_token_hash' => hash('sha256', Str::random(40)),
            'confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the subscriber has confirmed their address.
     */
    public function confirmed(): static
    {
        return $this->state(['confirmed_at' => now(), 'confirm_token_hash' => null]);
    }
}
