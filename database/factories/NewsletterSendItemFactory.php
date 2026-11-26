<?php

namespace Database\Factories;

use App\Models\NewsletterSend;
use App\Models\NewsletterSendItem;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewsletterSendItem>
 */
class NewsletterSendItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'newsletter_send_id' => NewsletterSend::factory(),
            'item_type' => 'work_item',
            'item_id' => WorkItem::factory(),
            'left_out' => false,
            'position' => 0,
        ];
    }

    /**
     * Indicate that the item was left out of the send.
     */
    public function leftOut(): static
    {
        return $this->state(['left_out' => true]);
    }
}
