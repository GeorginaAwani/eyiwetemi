<?php

namespace Database\Factories;

use App\Models\DailyVisitor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DailyVisitor>
 */
class DailyVisitorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'day' => today()->toDateString(),
            'scope' => 'site',
            'hash' => hash('sha256', Str::random(40)),
        ];
    }
}
