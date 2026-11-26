<?php

namespace Database\Factories;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TrustedDevice>
 */
class TrustedDeviceFactory extends Factory
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
            'label' => fake()->randomElement(['Chrome on Windows', 'Safari on iPhone', 'Firefox on Linux']),
            'user_agent' => fake()->userAgent(),
            'last_used_at' => now(),
            'expires_at' => now()->addDays(30),
        ];
    }

    /**
     * Indicate that the device trust has run out.
     */
    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }
}
