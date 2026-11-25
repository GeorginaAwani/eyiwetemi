<?php

namespace Database\Factories;

use App\Models\LoginLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LoginLink>
 */
class LoginLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addMinutes(15),
            'used_at' => null,
        ];
    }

    /**
     * Indicate that the link has been used to sign in.
     */
    public function used(): static
    {
        return $this->state(['used_at' => now()]);
    }

    /**
     * Indicate that the link has run out without being used.
     */
    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }
}
