<?php

namespace Tests\Feature;

use App\Models\Series;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LibraryPageTest extends TestCase
{
    use RefreshDatabase;

    private function released(Work $work, int $daysAgo, int $count = 1): Work
    {
        foreach (range(1, $count) as $i) {
            WorkItem::factory()->published()->for($work)->create([
                'type' => $work->kind->value === 'novel' ? 'chapter' : 'piece',
                'piece_work_id' => null,
                'title' => null,
                'position' => $i,
                'published_at' => now()->subDays($daysAgo + $count - $i),
            ]);
        }

        return $work;
    }

    private function poem(string $title, int $daysAgo): Work
    {
        return $this->released(Work::factory()->poem()->public()->published()->create(['title' => $title]), $daysAgo);
    }

    public function test_it_opens_on_all_with_the_newest_work_leading_and_counts_per_tab(): void
    {
        $this->poem('Harmattan', 1);
        $this->poem('Tin Roof', 5);
        $this->released(Work::factory()->novel()->public()->published()->create(['title' => 'Paper Boats']), 3, 2);
        $this->released(Work::factory()->novel()->published()->create(['title' => 'Private novel']), 0);

        $this->get('/library')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Library')
            ->where('tab', 'all')
            ->where('tabs.0', ['key' => 'all', 'label' => 'All', 'count' => 3])
            ->where('tabs.1.count', 1)
            ->where('tabs.3.count', 2)
            ->where('panels.all.lead.title', 'Harmattan')
            ->where('panels.all.rows.0.title', 'Paper Boats')
            ->where('panels.all.rows.0.line', ['type' => 'chapter', 'text' => 'Chapter 2', 'isNew' => true])
            ->where('panels.all.rows.1.title', 'Tin Roof')
            ->where('panels.all.shown', 3)
            ->where('panels.all.total', 3)
            ->where('panels.novel.lead.title', 'Paper Boats')
            ->where('panels.novel.lead.startLabel', 'Start from chapter 1')
        );
    }

    public function test_the_tab_in_the_address_is_opened(): void
    {
        $this->poem('Harmattan', 1);

        $this->get('/library?tab=poem')->assertInertia(fn (Assert $page) => $page->where('tab', 'poem'));
        $this->get('/library?tab=nonsense')->assertInertia(fn (Assert $page) => $page->where('tab', 'all'));
    }

    public function test_a_series_sits_together_in_the_novels_tab_in_book_order(): void
    {
        $series = Series::factory()->create(['name' => 'The Harbour books']);
        $this->released(Work::factory()->novel()->public()->published()->create(['title' => 'Newest novel']), 1);
        $this->released(Work::factory()->novel()->public()->published()->create(['title' => 'Book two', 'series_id' => $series->id, 'series_position' => 2]), 5);
        $this->released(Work::factory()->novel()->public()->published()->create(['title' => 'Loose novel']), 9);
        $this->released(Work::factory()->novel()->public()->published()->create(['title' => 'Book one', 'series_id' => $series->id, 'series_position' => 1, 'status' => 'finished']), 300);

        $this->get('/library?tab=novel')->assertInertia(fn (Assert $page) => $page
            ->where('panels.novel.lead.title', 'Newest novel')
            ->where('panels.novel.rows.0.type', 'series')
            ->where('panels.novel.rows.0.name', 'The Harbour books')
            ->where('panels.novel.rows.0.sub', 'A series · 2 books')
            ->where('panels.novel.rows.0.rows.0.title', 'Book one')
            ->where('panels.novel.rows.0.rows.0.sub', 'Novel · Book 1')
            ->where('panels.novel.rows.0.rows.0.line.type', 'finished')
            ->where('panels.novel.rows.0.rows.1.title', 'Book two')
            ->where('panels.novel.rows.1.title', 'Loose novel')
            ->where('panels.all.rows.0.sub', 'Novel · Book 2 of The Harbour books')
        );
    }

    public function test_show_more_returns_the_next_page_of_rows(): void
    {
        foreach (range(1, 20) as $i) {
            $this->poem('Poem '.$i, $i);
        }

        $this->get('/library')->assertInertia(fn (Assert $page) => $page
            ->where('panels.all.shown', 14)
            ->count('panels.all.rows', 13)
            ->where('panels.all.total', 20)
        );

        $this->getJson('/library-rows?tab=all&shown=14')
            ->assertOk()
            ->assertJsonCount(6, 'rows')
            ->assertJsonPath('rows.0.title', 'Poem 15')
            ->assertJsonPath('shown', 20)
            ->assertJsonPath('total', 20);

        $this->getJson('/library-rows?tab=gossip&shown=0')->assertUnprocessable();
    }

    public function test_a_book_only_in_print_opens_its_free_chapter(): void
    {
        $work = $this->released(Work::factory()->novel()->public()->published()->create(['title' => 'The Second Rain', 'status' => 'finished', 'free_chapters' => 1]), 400, 3);
        $this->poem('Lead poem', 1);

        $this->get('/library?tab=novel')->assertInertia(fn (Assert $page) => $page
            ->where('panels.all.rows.0.title', 'The Second Rain')
            ->where('panels.all.rows.0.href', '/library/'.$work->slug.'/1')
            ->where('panels.all.rows.0.line', ['type' => 'print', 'text' => 'Chapter 1 is free to read'])
            ->where('panels.all.rows.0.status', ['icon' => 'book', 'text' => 'In print'])
        );
    }
}
