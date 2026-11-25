<?php

namespace Database\Factories;

use App\Enums\VersionLabel;
use App\Models\Version;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Version>
 */
class VersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $document = EditorDocument::make();

        return [
            'versionable_type' => 'work_item',
            'versionable_id' => WorkItem::factory(),
            'label' => VersionLabel::Published,
            'body' => $document['body'],
            'words' => $document['words'],
            'words_added' => fake()->numberBetween(0, 500),
            'words_cut' => fake()->numberBetween(0, 200),
            'created_by' => null,
        ];
    }
}
