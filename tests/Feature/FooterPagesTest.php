<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\SitePage;
use App\Models\Work;
use App\Models\WorkItem;
use App\Support\SafeHtml;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FooterPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_shows_her_sections_with_their_html_cleaned(): void
    {
        SitePage::factory()->privacy()->create(['live' => [
            'updated_on' => '2026-11-25',
            'sections' => [['id' => 'me', 'heading' => 'Who looks after it', 'html' => '<p>Write to <a href="mailto:p@e.com" onclick="x()">me</a>.</p><script>bad()</script><p><a href="javascript:alert(1)">no</a> <span>kept</span></p>']],
        ]]);

        $this->get('/privacy')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Privacy')
            ->where('updated', '25 November 2026')
            ->where('sections.0.id', 'me')
            ->where('sections.0.html', '<p>Write to <a href="mailto:p@e.com" class="link">me</a>.</p><p><a class="link">no</a> kept</p>')
        );
    }

    public function test_terms_shows_her_sections_like_privacy(): void
    {
        SitePage::factory()->terms()->create(['live' => [
            'updated_on' => '2026-11-26',
            'sections' => [['id' => 'writing', 'heading' => 'My writing', 'html' => '<p>Please don’t copy it.</p><script>bad()</script>']],
        ]]);

        $this->get('/terms')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Terms')
            ->where('updated', '26 November 2026')
            ->where('sections.0.heading', 'My writing')
            ->where('sections.0.html', '<p>Please don’t copy it.</p>')
        );
    }

    public function test_terms_is_not_found_until_it_is_published(): void
    {
        SitePage::factory()->terms()->create(['live' => null]);

        $this->get('/terms')->assertNotFound();
    }

    public function test_safe_html_keeps_site_links_and_opens_others_in_a_new_tab(): void
    {
        $this->assertSame(
            '<p><a href="/settings#notifications" class="link">settings</a> <a href="https://x.com" target="_blank" rel="noopener" class="link">X</a></p>',
            SafeHtml::clean('<p><a href="/settings#notifications">settings</a> <a href="https://x.com">X</a></p>'),
        );
    }

    public function test_the_rss_page_lists_every_feed(): void
    {
        $this->get('/rss')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Rss')
            ->count('feeds', 6)
            ->where('feeds.0.label', 'Everything')
            ->where('feeds.1', ['label' => 'Novels', 'url' => preg_replace('~^https?://~', '', url('/feed/novels'))])
        );
    }

    public function test_feeds_carry_new_chapters_pieces_and_notes_but_not_print_only_chapters(): void
    {
        $novel = Work::factory()->novel()->public()->published()->create(['title' => 'Salt Season', 'free_chapters' => 1]);
        foreach ([1, 2] as $n) {
            WorkItem::factory()->chapter()->published()->for($novel)->create(['position' => $n, 'title' => $n === 1 ? 'Low water' : null, 'published_at' => now()->subDays(3 - $n)]);
        }
        Work::factory()->poem()->public()->published()->create(['title' => 'Harmattan', 'description' => 'Wind.']);
        Work::factory()->poem()->create(['title' => 'Secret', 'visibility' => 'private']);
        Note::factory()->published()->create(['live_text' => 'Rain all week. Wrote nothing.']);

        $everything = $this->get('/feed')->assertOk()->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8')->getContent();
        $this->assertStringContainsString('<title>Salt Season · Chapter 1 · Low water</title>', $everything);
        $this->assertStringNotContainsString('Chapter 2', $everything);
        $this->assertStringContainsString('<title>Harmattan</title>', $everything);
        $this->assertStringNotContainsString('Secret', $everything);
        $this->assertStringContainsString('<title>Rain all week.</title>', $everything);

        $poetry = $this->get('/feed/poetry')->assertOk()->getContent();
        $this->assertStringContainsString('<title>eyiwetemi · Poetry</title>', $poetry);
        $this->assertStringNotContainsString('Salt Season', $poetry);

        $this->get('/feed/comics')->assertNotFound();
    }

    public function test_a_change_that_matters_shows_its_line_and_marks_its_sections_for_thirty_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Africa/Lagos'));
        SitePage::factory()->privacy()->create(['live' => [
            'updated_on' => '2026-11-26',
            'sections' => [
                ['id' => 'who', 'heading' => 'Who looks after it', 'html' => '<p>Me.</p>'],
                ['id' => 'rights', 'heading' => 'Your rights', 'html' => '<p>Ask me.</p>'],
            ],
            'change' => ['on' => '2026-10-28', 'line' => 'I’ve added what I keep.', 'ids' => ['rights']],
        ]]);

        $this->get('/privacy')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Privacy')
            ->where('change.line', 'I’ve added what I keep.')
            ->where('change.ids', ['rights'])
        );

        $this->travel(3)->days();

        $this->get('/privacy')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Privacy')
            ->where('change', null)
        );
    }

    public function test_a_change_dated_in_the_future_or_without_a_line_is_not_shown(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Africa/Lagos'));
        SitePage::factory()->terms()->create(['live' => [
            'updated_on' => '2026-11-26',
            'sections' => [['id' => 'writing', 'heading' => 'My writing', 'html' => '<p>Please don’t copy it.</p>']],
            'change' => ['on' => '2026-11-26', 'line' => '   ', 'ids' => ['writing']],
        ]]);

        $this->get('/terms')->assertOk()->assertInertia(fn (Assert $page) => $page->where('change', null));

        SitePage::query()->update(['live' => [
            'updated_on' => '2026-11-26',
            'sections' => [['id' => 'writing', 'heading' => 'My writing', 'html' => '<p>Please don’t copy it.</p>']],
            'change' => ['on' => '2026-12-01', 'line' => 'Later.', 'ids' => ['writing']],
        ]]);

        $this->get('/terms')->assertOk()->assertInertia(fn (Assert $page) => $page->where('change', null));
    }
}
