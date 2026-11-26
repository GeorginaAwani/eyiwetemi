<?php

namespace Database\Factories;

use App\Enums\SitePageKey;
use App\Models\SitePage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SitePage>
 */
class SitePageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $draft = EditorDocument::make();

        return [
            'key' => SitePageKey::About,
            'draft' => $draft['body'],
            'live' => null,
            'draft_saved_at' => now(),
            'published_at' => null,
        ];
    }

    /**
     * Indicate that the page is the About page.
     */
    public function about(): static
    {
        return $this->state(['key' => SitePageKey::About]);
    }

    /**
     * Indicate that the page is the In print page.
     */
    public function inPrint(): static
    {
        return $this->state(['key' => SitePageKey::InPrint]);
    }

    /**
     * Indicate that the page is the Privacy page.
     */
    public function privacy(): static
    {
        return $this->state(['key' => SitePageKey::Privacy]);
    }

    /**
     * Indicate that the page is the Terms.
     */
    public function terms(): static
    {
        return $this->state(['key' => SitePageKey::Terms]);
    }

    /**
     * Indicate that the draft has been published, with no unpublished changes.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'live' => $attributes['draft'],
            'published_at' => now()->subDay(),
        ]);
    }
}
