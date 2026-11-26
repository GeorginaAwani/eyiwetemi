<?php

namespace Tests\Feature;

use App\Enums\CollectionKind;
use App\Models\Note;
use App\Models\SitePage;
use App\Models\SiteSetting;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A public novel with chapters released on the given days ago (oldest first).
     *
     * @param  list<int>  $daysAgo
     */
    private function novel(string $title, array $daysAgo, array $attributes = []): Work
    {
        $work = Work::factory()->novel()->public()->published()->create(['title' => $title, ...$attributes]);

        foreach ($daysAgo as $i => $days) {
            WorkItem::factory()->chapter()->published()->for($work)->create([
                'position' => $i + 1,
                'title' => $i === 0 ? 'The gate in the mud' : null,
                'published_at' => now()->subDays($days),
            ]);
        }

        return $work;
    }

    private function piece(string $kind, string $title, int $daysAgo): Work
    {
        $work = Work::factory()->{$kind}()->public()->published()->create(['title' => $title, 'description' => null]);
        WorkItem::factory()->published()->for($work)->create([
            'type' => 'piece',
            'piece_work_id' => null,
            'title' => null,
            'position' => 1,
            'published_at' => now()->subDays($daysAgo),
            'live_body' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First line here. And more.']]]]],
            'live_words' => 460,
        ]);

        return $work;
    }

    public function test_the_newest_released_work_leads_and_the_pile_holds_the_next_ones(): void
    {
        $older = $this->novel('Salt Season', [40, 30]);
        $newest = $this->piece('poem', 'Harmattan', 2);
        $this->piece('essay', 'On leaving home twice', 9);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('hero.title', 'Harmattan')
            ->where('hero.label', 'A poem · '.now('Africa/Lagos')->subDays(2)->format('j M'))
            ->where('hero.actions.0.label', 'Start reading')
            ->where('pile.0.title', 'On leaving home twice')
            ->where('pile.1.title', 'Salt Season')
            ->where('pile.1.sub', 'Novel · 2 chapters')
            ->count('pile', 2)
        );

        $this->assertNotNull($older->fresh());
        $this->assertNotNull($newest->fresh());
    }

    public function test_a_pinned_novel_leads_until_its_date_with_start_and_newest(): void
    {
        $ember = $this->novel('The Ember Gate', [20, 10, 5]);
        $this->piece('poem', 'Harmattan', 1);
        SiteSetting::put('home_pin', ['slug' => $ember->slug, 'until' => now()->addDay()->toIso8601String()]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('hero.title', 'The Ember Gate')
            ->where('hero.label', 'Chapter 3 · '.now('Africa/Lagos')->subDays(5)->format('j M'))
            ->where('hero.actions', [
                ['label' => 'Start from chapter 1', 'href' => '/library/'.$ember->slug.'/1?start', 'style' => 'solid'],
                ['label' => 'Newest: chapter 3', 'href' => '/library/'.$ember->slug.'/3', 'style' => 'link'],
            ])
        );

        SiteSetting::put('home_pin', ['slug' => $ember->slug, 'until' => now()->subMinute()->toIso8601String()]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('hero.title', 'Harmattan'));
    }

    public function test_private_reviewer_and_unreleased_work_never_shows(): void
    {
        $this->novel('Public novel', [3]);
        $this->novel('For reviewers', [1], ['visibility' => 'reviewers']);
        $this->novel('Private', [1], ['visibility' => 'private']);
        $drafted = $this->novel('Public with a draft chapter', [6]);
        WorkItem::factory()->chapter()->for($drafted)->create(['position' => 2]);
        WorkItem::factory()->chapter()->scheduled()->for($drafted)->create(['position' => 3]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('hero.title', 'Public novel')
            ->where('novels.lead.title', 'Public novel')
            ->where('novels.more.0.title', 'Public with a draft chapter')
            ->where('novels.more.0.status.text', '1 chapter')
            ->where('novels.total', 2)
            ->count('pile', 1)
        );
    }

    public function test_the_novel_lead_lists_its_newest_three_chapters_and_its_newest_note(): void
    {
        $novel = $this->novel('Paper Boats', [30, 20, 10, 3]);
        Note::factory()->published()->create(['work_id' => $novel->id, 'live_text' => "Chapter 5 will be late. I'm rewriting it.", 'published_at' => now()->subDay()]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('novels.lead.title', 'Paper Boats')
            ->where('novels.lead.statusLine', 'In progress · 4 chapters so far')
            ->where('novels.lead.chapters.0.label', 'Chapter 4')
            ->where('novels.lead.chapters.0.isNew', true)
            ->where('novels.lead.chapters.2.label', 'Chapter 2')
            ->where('novels.lead.chapters.2.isNew', false)
            ->count('novels.lead.chapters', 3)
            ->where('novels.lead.note.text', 'Chapter 5 will be late.')
            ->where('noteLine.text', 'Chapter 5 will be late.')
        );
    }

    public function test_piece_sections_lead_with_the_newest_and_collections_count_once(): void
    {
        $this->piece('story', 'Night bus', 5);
        $this->piece('story', 'The Ferryman', 9);
        $collection = Work::factory()->collection(CollectionKind::Story)->public()->published()->create(['title' => 'Rain Stories']);
        foreach ([20, 15] as $i => $days) {
            WorkItem::factory()->published()->for($collection)->create(['type' => 'piece', 'piece_work_id' => null, 'position' => $i + 1, 'published_at' => now()->subDays($days)]);
        }

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('stories.lead.title', 'Night bus')
            ->where('stories.lead.excerpt', 'First line here. And more.')
            ->where('stories.lead.minutes', 2)
            ->where('stories.more.0.title', 'The Ferryman')
            ->where('stories.more.1.title', 'Rain Stories')
            ->where('stories.more.1.status.text', 'Collection · 2 stories')
            ->where('stories.total', 3)
            ->where('poems', null)
        );
    }

    public function test_a_book_in_print_shows_its_lowest_naira_price_for_the_first_format(): void
    {
        $this->novel('Lead', [1]);
        $salt = $this->novel('Salt Season', [400], ['status' => 'finished']);
        SitePage::factory()->inPrint()->create(['live' => ['books' => [[
            'slug' => $salt->slug,
            'formats' => [
                ['format' => 'Paperback', 'stores' => [
                    ['store' => 'Amazon', 'price' => ['amount' => 1299, 'currency' => 'USD']],
                    ['store' => 'Jumia', 'price' => ['amount' => 900000, 'currency' => 'NGN']],
                    ['store' => 'Roving Heights', 'price' => ['amount' => 850000, 'currency' => 'NGN']],
                ]],
                ['format' => 'Ebook', 'stores' => [['store' => 'Okada Books', 'price' => ['amount' => 300000, 'currency' => 'NGN']]]],
            ],
        ]]]]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('novels.more.0.title', 'Salt Season')
            ->where('novels.more.0.inPrint', '₦8,500')
            ->where('novels.more.0.status', ['icon' => 'check', 'text' => '1 chapter'])
        );
    }

    public function test_an_empty_site_still_renders(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('hero', null)
            ->where('pile', [])
            ->where('novels', null)
            ->where('noteLine', null)
        );
    }
}
