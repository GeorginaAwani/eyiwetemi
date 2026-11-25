<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UsernameHold;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsernameHold>
 */
class UsernameHoldFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->regexify('[a-z][a-z0-9]{5,12}'),
            'user_id' => User::factory(),
            'released_at' => now()->addDays(30),
        ];
    }

    /**
     * Indicate that the hold has been released and is due for pruning.
     */
    public function released(): static
    {
        return $this->state(['released_at' => now()->subDay()]);
    }
}
