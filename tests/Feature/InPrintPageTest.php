<?php

namespace Tests\Feature;

use App\Models\Follow;
use App\Models\SitePage;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InPrintPageTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $name, int $amount, string $currency): array
    {
        return ['store' => $name, 'price' => ['amount' => $amount, 'currency' => $currency], 'url' => 'https://'.strtolower($name).'.com', 'ok' => true];
    }

    public function test_books_show_a_price_per_format_coming_books_and_readings(): void
    {
        $this->travelTo(Carbon::parse('2026-11-26 12:00', 'Africa/Lagos'));
        $salt = Work::factory()->novel()->public()->published()->create(['title' => 'Salt Season']);
        SitePage::factory()->inPrint()->create(['live' => [
            'books' => [[
                'slug' => $salt->slug, 'title' => 'Salt Season', 'kind' => 'A novel', 'cover' => '#C24E77',
                'formats' => [
                    ['format' => 'Paperback', 'stores' => [$this->store('Amazon', 1299, 'USD'), $this->store('Jumia', 900000, 'NGN'), $this->store('Roving', 850000, 'NGN')]],
                    ['format' => 'Audiobook', 'stores' => [$this->store('Audible', 1999, 'USD')]],
                    ['format' => 'Ebook', 'stores' => []],
                ],
            ]],
            'coming' => [
                ['slug' => 'nope', 'title' => 'Paper Boats', 'kind' => 'A novel', 'cover' => '#977AA0', 'when' => 'March 2027', 'mode' => 'pre', 'store' => 'Roving', 'price' => ['amount' => 800000, 'currency' => 'NGN'], 'url' => 'https://roving.com/pb'],
                ['slug' => 'nope2', 'title' => 'Midnight Poems', 'kind' => 'Poems', 'cover' => '#A24F68', 'when' => '2027', 'mode' => 'follow', 'store' => '', 'price' => ['amount' => 0, 'currency' => 'NGN'], 'url' => null],
            ],
            'events' => [
                ['id' => 'p1', 'title' => 'Book festival', 'starts_at' => '2026-11-16T13:00:00Z', 'place' => 'Port Harcourt', 'online' => false, 'url' => null, 'cancelled' => false],
                ['id' => 'e2', 'title' => 'Ake', 'starts_at' => '2027-02-14T10:00:00Z', 'place' => 'Abeokuta', 'online' => false, 'url' => 'https://ake.org', 'cancelled' => false],
                ['id' => 'e1', 'title' => 'Midnight, live', 'starts_at' => '2026-11-26T08:30:00Z', 'place' => '', 'online' => true, 'url' => 'https://yt.com', 'cancelled' => false],
            ],
        ]]);

        $this->get('/in-print')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('InPrint')
            ->where('columns', ['Paperback', 'Hardback', 'Ebook', 'Signed', 'Audiobook'])
            ->where('books.0.href', '/library/'.$salt->slug)
            ->count('books.0.formats', 2)
            ->where('books.0.formats.0.from', '₦8,500')
            ->where('books.0.formats.1.stores.0', ['store' => 'Audible', 'price' => '$19.99', 'url' => 'https://audible.com'])
            ->where('coming.0.href', '/library')
            ->where('coming.0.preorder', ['price' => '₦8,000', 'url' => 'https://roving.com/pb'])
            ->where('coming.1.preorder', null)
            ->where('upcoming.0.title', 'Midnight, live')
            ->where('upcoming.0.where', 'Online · 9:30 am')
            ->where('upcoming.0.link', ['label' => 'Join', 'url' => 'https://yt.com'])
            ->where('upcoming.1.where', 'Abeokuta · 11 am · 2027')
            ->where('past.0', ['id' => 'p1', 'day' => 16, 'month' => 'Nov', 'title' => 'Book festival', 'online' => false, 'where' => 'Port Harcourt · 2026', 'cancelled' => false, 'link' => null])
        );
    }

    public function test_a_signed_in_reader_sees_which_coming_books_they_follow(): void
    {
        $reader = User::factory()->create();
        $coming = Work::factory()->novel()->public()->comingSoon()->create(['title' => 'Paper Boats']);
        Follow::factory()->for($reader)->create(['followable_type' => 'work', 'followable_id' => $coming->id]);
        SitePage::factory()->inPrint()->create(['live' => [
            'books' => [],
            'coming' => [['slug' => $coming->slug, 'title' => 'Paper Boats', 'kind' => 'A novel', 'cover' => '#977AA0', 'when' => '2027', 'mode' => 'follow', 'store' => '', 'price' => ['amount' => 0, 'currency' => 'NGN'], 'url' => null]],
        ]]);

        $this->get('/in-print')->assertInertia(fn (Assert $page) => $page->where('coming.0.following', false));
        $this->actingAs($reader)->get('/in-print')->assertInertia(fn (Assert $page) => $page->where('coming.0.following', true));
    }

    public function test_in_print_is_not_found_until_published(): void
    {
        SitePage::factory()->inPrint()->create(['live' => null]);

        $this->get('/in-print')->assertNotFound();
    }
}
