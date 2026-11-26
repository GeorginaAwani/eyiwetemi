<?php

namespace Tests\Feature;

use App\Enums\CollectionKind;
use App\Models\Notice;
use App\Models\SiteSetting;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SiteLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_shares_the_footer_contact_details_and_links(): void
    {
        SiteSetting::put('contact', ['email' => 'write@example.com']);
        SiteSetting::put('footer', ['line' => 'Free to read.']);
        SiteSetting::put('links', [
            ['network' => 'Instagram', 'handle' => '@eyiwetemi.writes'],
            ['network' => 'TikTok', 'handle' => 'eyiwetemi'],
            ['network' => 'Website', 'handle' => 'example.com/me'],
            ['network' => 'X', 'handle' => ''],
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home')
                ->where('site.email', 'write@example.com')
                ->where('site.line', 'Free to read.')
                ->where('site.links', [
                    ['network' => 'Instagram', 'url' => 'https://instagram.com/eyiwetemi.writes'],
                    ['network' => 'TikTok', 'url' => 'https://tiktok.com/@eyiwetemi'],
                    ['network' => 'Website', 'url' => 'https://example.com/me'],
                ])
            );
    }

    public function test_library_counts_only_public_published_works_with_collections_counted_once(): void
    {
        Work::factory()->novel()->public()->published()->count(2)->create();
        Work::factory()->novel()->public()->comingSoon()->create();
        Work::factory()->novel()->published()->create();
        Work::factory()->novel()->reviewers()->published()->create();
        Work::factory()->novel()->public()->create();

        $stories = Work::factory()->collection(CollectionKind::Story)->public()->published()->create();
        Work::factory()->story()->public()->published()->create(['collection_id' => $stories->id]);
        Work::factory()->story()->public()->published()->create();
        Work::factory()->poem()->public()->published()->count(3)->create();
        Work::factory()->essay()->public()->published()->create();

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('site.library', ['all' => 9, 'novel' => 3, 'story' => 2, 'poem' => 3, 'essay' => 1])
        );
    }

    public function test_the_live_notice_is_shared_with_its_link_words(): void
    {
        Notice::factory()->expired()->create(['text' => 'Old news']);
        Notice::factory()->takenDown()->create(['text' => 'Taken down']);
        Notice::factory()->scheduled()->create(['text' => 'Not yet']);
        $live = Notice::factory()->create(['text' => 'Salt Season is in print.', 'link_target' => 'in_print']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('notice', ['id' => $live->id, 'text' => 'Salt Season is in print.', 'href' => '/in-print', 'go' => 'See where to buy'])
        );
    }

    public function test_no_notice_is_shared_when_none_is_up(): void
    {
        Notice::factory()->expired()->create();

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('notice', null));
    }
}
