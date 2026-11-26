<?php

namespace Tests\Feature;

use App\Enums\PublishStatus;
use App\Enums\Visibility;
use App\Models\Note;
use App\Models\Series;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SiteSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A public novel that is out, with no description so only its title is searched.
     */
    private function novel(string $title, array $attributes = []): Work
    {
        return Work::factory()->novel()->public()->published()->create(['title' => $title, 'description' => null, ...$attributes]);
    }

    /**
     * A released chapter with the given text; the text is what lines search.
     */
    private function chapter(Work $work, string $text, array $attributes = []): WorkItem
    {
        return WorkItem::factory()->chapter()->published()->for($work)->create(['title' => null, 'live_text' => $text, ...$attributes]);
    }

    public function test_a_title_prefix_finds_the_work_and_its_address(): void
    {
        $work = $this->novel('The Ember Gate');
        $this->chapter($work, 'Quiet morning.');
        $this->novel('Night Bus');

        $this->getJson('/search?q=emb')->assertOk()->assertExactJson([
            'q' => 'emb',
            'works' => [['title' => 'The Ember Gate', 'small' => 'Novel · 1 chapter', 'href' => '/library/'.$work->slug]],
            'lines' => [],
            'notes' => [],
        ]);
    }

    public function test_matching_ignores_case_and_accents_and_every_word_must_match(): void
    {
        $bus = $this->novel('Night Bus');
        $this->chapter($bus, 'The Café opened at dawn, and the steam rose.');
        $this->chapter($this->novel('Harbour'), 'The café was empty.');

        $this->getJson('/search?q=CAFE')->assertOk()->assertJsonCount(2, 'lines');
        $this->getJson('/search?q=cafe+steam')->assertOk()
            ->assertJsonCount(1, 'lines')
            ->assertJsonPath('lines.0.title', 'Chapter 1 · Night Bus');
    }

    public function test_a_chapter_line_has_its_title_address_and_snippet(): void
    {
        $work = $this->novel('The Ember Gate');
        $this->chapter($work, 'Rain on the roof.');
        $this->chapter($work, 'Two.');
        $this->chapter($work, 'The corridor was dark. Somewhere ahead, the ember of the lamp still glowed.');

        $this->getJson('/search?q=lamp')->assertOk()->assertExactJson([
            'q' => 'lamp',
            'works' => [],
            'lines' => [[
                'title' => 'Chapter 3 · The Ember Gate',
                'small' => '“…ember of the lamp still glowed…”',
                'href' => '/library/'.$work->slug.'/3',
            ]],
            'notes' => [],
        ]);
    }

    public function test_a_chapter_found_by_its_title_alone_shows_that_title(): void
    {
        $work = $this->novel('Salt Season');
        $this->chapter($work, 'The tide went out on a Tuesday.', ['title' => 'Lantern']);

        $this->getJson('/search?q=lantern')->assertOk()->assertJsonPath('lines', [[
            'title' => 'Chapter 1 · Salt Season',
            'small' => 'Lantern',
            'href' => '/library/'.$work->slug.'/1',
        ]]);
    }

    public function test_print_only_chapters_and_what_is_not_public_or_released_never_appear(): void
    {
        $paid = $this->novel('Paid Book', ['free_chapters' => 1]);
        $this->chapter($paid, 'Free opening.');
        $this->chapter($paid, 'The secret lamp burned.');

        $private = $this->novel('Hidden Book', ['visibility' => Visibility::Private]);
        $this->chapter($private, 'The secret lamp burned.');

        $reviewers = $this->novel('Reviewer Book', ['visibility' => Visibility::Reviewers]);
        $this->chapter($reviewers, 'The secret lamp burned.');

        $unlisted = Work::factory()->novel()->public()->create(['title' => 'Unlisted Book', 'description' => null]);
        $this->chapter($unlisted, 'The secret lamp burned.', ['status' => PublishStatus::Published, 'published_at' => now()->subDay()]);

        $upcoming = $this->novel('Next Book');
        $this->chapter($upcoming, 'The secret lamp burned.', ['published_at' => now()->addDay()]);
        $this->chapter($upcoming, 'The secret lamp burned.', ['status' => PublishStatus::Scheduled, 'published_at' => null]);

        Note::factory()->scheduled()->create(['live_text' => 'The secret lamp burned.']);

        $this->getJson('/search?q=secret')->assertOk()->assertExactJson(['q' => 'secret', 'works' => [], 'lines' => [], 'notes' => []]);
        $this->getJson('/search?q=hidden')->assertOk()->assertJsonCount(0, 'works');
        $this->getJson('/search?q=reviewer')->assertOk()->assertJsonCount(0, 'works');
        $this->getJson('/search?q=unlisted')->assertOk()->assertJsonCount(0, 'works');
        $this->getJson('/search?q=free')->assertOk()
            ->assertJsonCount(1, 'lines')
            ->assertJsonPath('lines.0.title', 'Chapter 1 · Paid Book')
            ->assertJsonPath('lines.0.small', '“…Free opening…”');

        $this->actingAs(User::factory()->create())->getJson('/search?q=secret')
            ->assertOk()
            ->assertExactJson(['q' => 'secret', 'works' => [], 'lines' => [], 'notes' => []]);
    }

    public function test_notes_show_when_released_newest_first_with_their_day(): void
    {
        $this->travelTo(Carbon::parse('2026-11-26 12:00', 'Africa/Lagos'));

        foreach (['2026-11-19 09:00', '2026-11-20 09:00', '2026-11-21 09:00', '2026-11-22 09:00'] as $at) {
            Note::factory()->published()->create([
                'live_text' => 'Salt Season is now in print.',
                'published_at' => Carbon::parse($at, 'Africa/Lagos')->utc(),
            ]);
        }
        Note::factory()->scheduled()->create(['live_text' => 'Salt Season is now in print.']);
        Note::factory()->create(['live_text' => 'Salt Season is now in print.']);

        $newest = Note::query()->whereNotNull('published_at')->orderByDesc('published_at')->first();

        $this->getJson('/search?q=print')->assertOk()
            ->assertJsonCount(3, 'notes')
            ->assertJsonPath('notes.0.title', 'Note · 22 Nov')
            ->assertJsonPath('notes.0.href', '/notes#n-'.$newest->id)
            ->assertJsonPath('notes.0.small', '“…is now in print…”')
            ->assertJsonPath('notes.2.title', 'Note · 20 Nov');
    }

    public function test_a_series_matches_by_name_and_only_when_a_public_book_is_out(): void
    {
        $series = Series::factory()->create(['name' => 'Ember Cycle']);
        $book = $this->novel('The Ember Gate', ['series_id' => $series->id, 'series_position' => 1]);
        $this->chapter($book, 'Quiet morning.');

        $upcoming = Series::factory()->create(['name' => 'Ember Soon']);
        Work::factory()->novel()->public()->comingSoon()->create(['title' => 'Soon Book', 'description' => null, 'series_id' => $upcoming->id, 'series_position' => 1]);

        $hidden = Series::factory()->create(['name' => 'Ember Secrets']);
        Work::factory()->novel()->reviewers()->published()->create(['title' => 'Secret Book', 'description' => null, 'series_id' => $hidden->id, 'series_position' => 1]);

        $this->getJson('/search?q=ember')->assertOk()->assertExactJson([
            'q' => 'ember',
            'works' => [
                ['title' => 'Ember Cycle', 'small' => 'Series · 1 book', 'href' => '/series/'.$series->slug],
                ['title' => 'The Ember Gate', 'small' => 'Novel · 1 chapter', 'href' => '/library/'.$book->slug],
            ],
            'lines' => [],
            'notes' => [],
        ]);
    }

    public function test_short_searches_run_nothing_and_long_ones_are_refused(): void
    {
        $this->novel('Ember Gate');

        $this->getJson('/search?q=e')->assertOk()->assertExactJson(['q' => 'e', 'works' => [], 'lines' => [], 'notes' => []]);
        $this->getJson('/search?'.http_build_query(['q' => '   e   ']))->assertOk()->assertJsonPath('q', 'e')->assertJsonCount(0, 'works');
        $this->getJson('/search?'.http_build_query(['q' => str_repeat('e', 101)]))->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/search?'.http_build_query(['q' => str_repeat(' ', 5).'ember'.str_repeat(' ', 95)]))
            ->assertOk()
            ->assertJsonPath('q', 'ember')
            ->assertJsonCount(1, 'works');
    }

    public function test_results_are_capped_at_five_works_and_six_lines(): void
    {
        $work = $this->novel('Ember One');
        foreach (range(1, 7) as $n) {
            $this->chapter($work, "Ember line {$n}.");
        }
        foreach (range(2, 6) as $n) {
            $this->novel("Ember {$n}");
        }

        $this->getJson('/search?q=ember')->assertOk()
            ->assertJsonCount(5, 'works')
            ->assertJsonCount(6, 'lines');
    }

    public function test_guests_and_signed_in_readers_get_the_same_results(): void
    {
        $this->chapter($this->novel('The Ember Gate'), 'The ember of the lamp.');

        $guest = $this->getJson('/search?q=ember')->assertOk()->json();
        $reader = $this->actingAs(User::factory()->create())->getJson('/search?q=ember')->assertOk()->json();

        $this->assertSame($guest, $reader);
        $this->assertCount(1, $guest['works']);
        $this->assertCount(1, $guest['lines']);
    }
}
