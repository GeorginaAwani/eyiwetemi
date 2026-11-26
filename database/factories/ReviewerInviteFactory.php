<?php

namespace Database\Factories;

use App\Models\ReviewerInvite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReviewerInvite>
 */
class ReviewerInviteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'name' => fake()->name(),
            'note' => fake()->optional()->sentence(),
            'token_hash' => hash('sha256', Str::random(40)),
            'invited_by' => User::factory()->author(),
            'expires_at' => now()->addDays(14),
            'accepted_at' => null,
            'accepted_user_id' => null,
            'cancelled_at' => null,
        ];
    }

    /**
     * Indicate that the invite ran out before it was accepted.
     */
    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    /**
     * Indicate that the invite was accepted by a reviewer.
     */
    public function accepted(): static
    {
        return $this->state([
            'accepted_at' => now(),
            'accepted_user_id' => User::factory()->reviewer(),
        ]);
    }

    /**
     * Indicate that the author cancelled the invite.
     */
    public function cancelled(): static
    {
        return $this->state(['cancelled_at' => now()]);
    }
}
