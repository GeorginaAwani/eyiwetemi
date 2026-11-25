<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->regexify('[a-z][a-z0-9._]{4,14}'),
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Reader,
            'avatar_seed' => Str::random(16),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user writes books.
     */
    public function author(): static
    {
        return $this->state(['role' => UserRole::Author]);
    }

    /**
     * Indicate that the user reviews drafts for authors.
     */
    public function reviewer(): static
    {
        return $this->state(['role' => UserRole::Reviewer]);
    }

    /**
     * Indicate that the user is a reader.
     */
    public function reader(): static
    {
        return $this->state(['role' => UserRole::Reader]);
    }

    /**
     * Indicate that the account signs in with Google only and has no password.
     */
    public function googleOnly(): static
    {
        return $this->state(['password' => null]);
    }
}
