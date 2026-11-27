<?php

namespace Database\Factories;

use App\Enums\CollectionKind;
use App\Enums\HeadingStyle;
use App\Enums\SceneBreak;
use App\Enums\Visibility;
use App\Enums\WorkKind;
use App\Enums\WorkStatus;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Work>
 */
class WorkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = rtrim(fake()->unique()->sentence(3), '.');

        return [
            'kind' => WorkKind::Novel,
            'collection_kind' => null,
            'title' => $title,
            'title_key' => fn (array $attributes): string => self::titleKey($attributes['title']),
            'slug' => fn (array $attributes): string => Str::slug($attributes['title']).'-'.Str::lower(Str::random(6)),
            'description' => fake()->optional(0.6)->paragraph(),
            'status' => WorkStatus::InProgress,
            'visibility' => Visibility::Private,
            'series_id' => null,
            'series_position' => null,
            'collection_id' => null,
            'cover_media_id' => null,
            'cover_color' => fake()->hexColor(),
            'heading_style' => HeadingStyle::ChapterWord,
            'show_chapter_titles' => true,
            'show_pov' => false,
            'part_word' => null,
            'part_style' => null,
            'show_part_numbers' => true,
            'scene_break' => SceneBreak::LineDot,
            'opening_quote' => null,
            'opening_quote_by' => null,
            'content_notes' => null,
            'plan_notes' => null,
            'mark_names' => false,
            'first_published_at' => null,
            'last_published_at' => null,
        ];
    }

    /**
     * Indicate that the work is a novel.
     */
    public function novel(): static
    {
        return $this->state(['kind' => WorkKind::Novel, 'collection_kind' => null]);
    }

    /**
     * Indicate that the work is a short story.
     */
    public function story(): static
    {
        return $this->state(['kind' => WorkKind::Story, 'collection_kind' => null]);
    }

    /**
     * Indicate that the work is a poem.
     */
    public function poem(): static
    {
        return $this->state(['kind' => WorkKind::Poem, 'collection_kind' => null]);
    }

    /**
     * Indicate that the work is an essay.
     */
    public function essay(): static
    {
        return $this->state(['kind' => WorkKind::Essay, 'collection_kind' => null]);
    }

    /**
     * Indicate that the work is a collection of pieces of one kind.
     */
    public function collection(CollectionKind $kind = CollectionKind::Story): static
    {
        return $this->state(['kind' => WorkKind::Collection, 'collection_kind' => $kind]);
    }

    /**
     * Indicate that the work is visible to everyone.
     */
    public function public(): static
    {
        return $this->state(['visibility' => Visibility::Public]);
    }

    /**
     * Indicate that the work is visible to its reviewers only.
     */
    public function reviewers(): static
    {
        return $this->state(['visibility' => Visibility::Reviewers]);
    }

    /**
     * Indicate that the work has been published.
     */
    public function published(): static
    {
        return $this->state(fn (): array => [
            'first_published_at' => now()->subMonth(),
            'last_published_at' => now()->subDay(),
        ]);
    }

    /**
     * Indicate that the work is announced as coming soon and has nothing out yet.
     */
    public function comingSoon(): static
    {
        return $this->state(['status' => WorkStatus::ComingSoon, 'first_published_at' => null, 'last_published_at' => null]);
    }

    /**
     * Normalise a title the way title_key is stored: lowercase with collapsed whitespace.
     */
    public static function titleKey(string $title): string
    {
        return Str::lower(preg_replace('/\s+/u', ' ', trim($title)));
    }
}
