<?php

namespace Database\Factories;

use App\Enums\ResearchKind;
use App\Models\Media;
use App\Models\ResearchItem;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResearchItem>
 */
class ResearchItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_id' => Work::factory(),
            'kind' => ResearchKind::Link,
            'title' => rtrim(fake()->sentence(3), '.'),
            'url' => fake()->url(),
            'note' => null,
            'media_id' => null,
            'position' => 0,
        ];
    }

    /**
     * Indicate that the item is a saved image.
     */
    public function image(): static
    {
        return $this->state(['kind' => ResearchKind::Image, 'url' => null, 'media_id' => Media::factory()]);
    }

    /**
     * Indicate that the item is a saved file.
     */
    public function file(): static
    {
        return $this->state(['kind' => ResearchKind::File, 'url' => null, 'media_id' => Media::factory()]);
    }

    /**
     * Indicate that the item is a plain note.
     */
    public function note(): static
    {
        return $this->state(['kind' => ResearchKind::Note, 'url' => null, 'note' => fake()->paragraph()]);
    }
}
