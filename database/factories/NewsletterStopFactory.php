<?php

namespace Database\Factories;

use App\Models\NewsletterStop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewsletterStop>
 */
class NewsletterStopFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'newsletter_send_id' => null,
        ];
    }
}
