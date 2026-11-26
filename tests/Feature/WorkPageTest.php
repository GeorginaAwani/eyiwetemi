<?php

namespace Tests\Feature;

use App\Models\Follow;
use App\Models\Genre;
use App\Models\Series;
use App\Models\SitePage;
use App\Models\SlugHistory;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function novel(int $chapters, array $attributes = []): Work
    {
        $work = Work::factory()->novel()->public()->published()->create(['title' => 'The Ember Gate', 'description' => 'A gate in the mud.', ...$attributes]);

        foreach (range(1, $chapters) as $n) {
            WorkItem::factory()->chapter()->published()->for($work)->create([
                'position' => $n,
                'title' => $n === 1 ? 'The gate in the mud' : null,
                'published_at' => now()->subDays(($chapters - $n) * 7 + 1),
            ]);
        }

        return $work;
    }

    public function test_a_public_novel_shows_its_facts_genres_and_chapters(): void
    {
        $work = $this->novel(3);
        $work->genres()->attach(Genre::factory()->create(['name' => 'Fantasy', 'position' => 1]));

        $this->get('/library/'.$work->slug)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Work')
            ->where('work.title', 'The Ember Gate')
            ->where('work.statusLine', 'In progress · 3 chapters so far')
            ->where('work.genres', ['Fantasy'])
            ->where('work.blurb', 'A gate in the mud.')
            ->where('facts.1', ['label' => 'Chapters', 'value' => '3 so far'])
            ->where('actions.0.label', 'Start from chapter 1')
            ->where('actions.1.label', 'Newest: chapter 3')
            ->where('list.rows.0.label', 'The gate in the mud')
            ->where('list.rows.1.label', 'Chapter 2')
            ->where('list.rows.2.isNew', true)
            ->where('list.foldFrom', null)
        );
    }

    public function test_long_lists_fold_after_ten_rows_and_a_pulled_chapter_keeps_its_number(): void
    {
        $work = $this->novel(14);
        $work->items()->where('position', 5)->update(['status' => 'unpublished']);
        WorkItem::factory()->chapter()->for($work)->create(['position' => 15]);

        $this->get('/library/'.$work->slug)->assertInertia(fn (Assert $page) => $page
            ->where('list.count', 13)
            ->count('list.rows', 14)
            ->where('list.foldFrom', 10)
            ->where('list.rows.4.number', 5)
            ->where('list.rows.4.unavailable', true)
            ->where('list.rows.4.href', null)
            ->where('list.rows.5.number', 6)
        );
    }

    public function test_who_can_open_a_work_follows_its_visibility(): void
    {
        $private = $this->novel(1, ['title' => 'Private one', 'visibility' => 'private']);
        $forReviewers = $this->novel(1, ['title' => 'For reviewers', 'visibility' => 'reviewers']);
        $unreleased = Work::factory()->novel()->public()->create();
        $reviewer = User::factory()->reviewer()->create();
        $forReviewers->reviewers()->attach($reviewer);

        $this->get('/library/'.$private->slug)->assertNotFound();
        $this->get('/library/'.$forReviewers->slug)->assertNotFound();
        $this->get('/library/'.$unreleased->slug)->assertNotFound();
        $this->actingAs(User::factory()->reader()->create())->get('/library/'.$forReviewers->slug)->assertNotFound();
        $this->actingAs($reviewer)->get('/library/'.$forReviewers->slug)->assertOk();
        $this->actingAs(User::factory()->author()->create())->get('/library/'.$private->slug)->assertOk();
    }

    public function test_a_signed_in_reader_sees_whether_they_follow_the_work(): void
    {
        $work = $this->novel(1);
        $reader = User::factory()->create();
        Follow::factory()->for($reader)->create(['followable_type' => 'work', 'followable_id' => $work->id]);

        $this->get('/library/'.$work->slug)->assertInertia(fn (Assert $page) => $page->where('work.following', false));
        $this->actingAs($reader)->get('/library/'.$work->slug)->assertInertia(fn (Assert $page) => $page->where('work.following', true));
    }

    public function test_an_old_address_moves_to_the_current_one(): void
    {
        $work = $this->novel(1);
        SlugHistory::factory()->create(['sluggable_type' => 'work', 'sluggable_id' => $work->id, 'slug' => 'the-old-name']);

        $this->get('/library/the-old-name')->assertStatus(301)->assertRedirect('/library/'.$work->slug);
        $this->get('/library/never-existed')->assertNotFound();
    }

    public function test_in_print_formats_show_one_store_directly_and_several_from_the_lowest_naira_price(): void
    {
        $work = $this->novel(2, ['status' => 'finished']);
        SitePage::factory()->inPrint()->create(['live' => ['books' => [[
            'slug' => $work->slug,
            'formats' => [
                ['format' => 'Paperback', 'stores' => [
                    ['store' => 'Amazon', 'url' => 'https://amazon.com/x', 'price' => ['amount' => 1299, 'currency' => 'USD']],
                    ['store' => 'Jumia', 'url' => 'https://jumia.com.ng/x', 'price' => ['amount' => 900000, 'currency' => 'NGN']],
                    ['store' => 'Roving Heights', 'url' => 'https://rovingheights.com/x', 'price' => ['amount' => 850000, 'currency' => 'NGN']],
                ]],
                ['format' => 'Hardback', 'stores' => [['store' => 'Amazon', 'url' => 'https://amazon.com/y', 'price' => ['amount' => 2499, 'currency' => 'USD']]]],
            ],
        ]]]]);

        $this->get('/library/'.$work->slug)->assertInertia(fn (Assert $page) => $page
            ->where('prints.0.from', '₦8,500')
            ->count('prints.0.stores', 3)
            ->where('prints.1.from', null)
            ->where('prints.1.stores.0', ['store' => 'Amazon', 'price' => '$24.99', 'url' => 'https://amazon.com/y'])
            ->count('actions', 1)
        );
    }

    public function test_a_series_lists_its_public_books_in_order(): void
    {
        $series = Series::factory()->create(['name' => 'The Harbour books', 'description' => 'Three novels about one harbour town.']);
        $this->novel(1, ['title' => 'Book two', 'series_id' => $series->id, 'series_position' => 2]);
        $this->novel(2, ['title' => 'Book one', 'series_id' => $series->id, 'series_position' => 1, 'status' => 'finished']);
        Work::factory()->novel()->public()->comingSoon()->create(['title' => 'Book three', 'series_id' => $series->id, 'series_position' => 3]);
        $this->novel(1, ['title' => 'Secret', 'series_id' => $series->id, 'series_position' => 4, 'visibility' => 'private']);

        $this->get('/series/'.$series->slug)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Series')
            ->where('series.statusLine', 'A series · 3 books')
            ->where('series.blurb', 'Three novels about one harbour town.')
            ->where('books.0.title', 'Book one')
            ->where('books.0.statusText', '2 chapters')
            ->where('books.1.statusText', '1 chapter so far')
            ->where('books.2.soon', true)
            ->where('books.2.href', null)
            ->count('books', 3)
            ->where('actions.0.label', 'Start from book 1')
        );

        $hidden = Series::factory()->create();
        $this->novel(1, ['title' => 'Hidden book', 'series_id' => $hidden->id, 'series_position' => 1, 'visibility' => 'private']);
        $this->get('/series/'.$hidden->slug)->assertNotFound();
    }
}
