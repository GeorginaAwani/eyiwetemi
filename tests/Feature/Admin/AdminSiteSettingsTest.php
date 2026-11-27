<?php

namespace Tests\Feature\Admin;

use App\Models\Media;
use App\Models\Notice;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use App\Support\SocialNetworks;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminSiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        Storage::fake('local');

        $this->author = User::factory()->author()->create(['name' => 'Georgina Awani', 'email' => 'georgina@example.com']);
        $this->reader = User::factory()->reader()->create(['name' => 'Ife Bello', 'username' => 'ife.bello']);
        $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Africa/Lagos'));
    }

    private function asAdmin(): static
    {
        return $this->actingAs($this->author)->withSession(['admin.verified' => true]);
    }

    private function url(string $path): string
    {
        return 'http://'.config('eyiwetemi.admin_domain').$path;
    }

    /**
     * A reading-site address as a full URL: the test client keeps the admin host after an admin request, so public requests name theirs.
     */
    private function visitor(string $path): string
    {
        return 'http://localhost'.$path;
    }

    /**
     * A West Africa Time moment, as the page sends it: "2026-12-01 18:00".
     */
    private function wat(string $local): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $local, 'Africa/Lagos')->utc();
    }

    /**
     * A public novel with chapters released on the given days ago (oldest first).
     *
     * @param  list<int>  $daysAgo
     */
    private function novel(string $title, array $daysAgo = [], array $attributes = []): Work
    {
        $work = Work::factory()->novel()->public()->published()->create(['title' => $title, 'slug' => Str::slug($title), ...$attributes]);

        foreach ($daysAgo as $i => $days) {
            WorkItem::factory()->chapter()->published()->for($work)->create([
                'position' => $i + 1,
                'title' => null,
                'published_at' => now()->subDays($days),
            ]);
        }

        return $work;
    }

    /**
     * A public short story released the given days ago.
     */
    private function story(string $title, int $daysAgo): Work
    {
        $work = Work::factory()->story()->public()->published()->create(['title' => $title, 'slug' => Str::slug($title)]);

        WorkItem::factory()->published()->for($work)->create([
            'type' => 'piece',
            'piece_work_id' => null,
            'title' => null,
            'position' => 1,
            'published_at' => now()->subDays($daysAgo),
            'live_body' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'The bus was late.']]]]],
            'live_words' => 460,
        ]);

        return $work;
    }

    private function siteIndex(): TestResponse
    {
        return $this->asAdmin()->get($this->url('/site'))->assertOk();
    }

    private function pin(string $slug, string $day, string $time): TestResponse
    {
        return $this->asAdmin()->putJson($this->url('/site/home-pin'), ['slug' => $slug, 'until' => ['day' => $day, 'time' => $time]]);
    }

    /**
     * A notice that is up now, started an hour ago and with no end.
     */
    private function upNotice(string $text = 'Signed copies this weekend'): Notice
    {
        return Notice::factory()->create(['text' => $text, 'link_target' => 'in_print', 'starts_at' => now()->subHour(), 'ends_at' => null]);
    }

    public function test_guests_and_readers_cannot_change_the_site_tabs(): void
    {
        $this->putJson($this->url('/site/home-pin'), [])->assertUnauthorized();

        $this->actingAs($this->reader)->withSession(['admin.verified' => true])->putJson($this->url('/site/home-pin'), ['slug' => 'x', 'until' => ['day' => '2026-12-01', 'time' => '18:00']])->assertUnauthorized();
        $this->actingAs($this->reader)->withSession(['admin.verified' => true])->postJson($this->url('/site/notice'), ['text' => 'Hi', 'starts' => 'now'])->assertUnauthorized();
        $this->actingAs($this->reader)->withSession(['admin.verified' => true])->patchJson($this->url('/site/contact'), ['email' => 'a@example.com'])->assertUnauthorized();
        $this->actingAs($this->reader)->withSession(['admin.verified' => true])->patchJson($this->url('/site/sharing'), ['slug' => null, 'line' => 'x'])->assertUnauthorized();

        $this->assertDatabaseCount('notices', 0);
    }

    public function test_home_shows_its_lead_side_and_no_next_without_a_pin(): void
    {
        $this->novel('The Ember Gate', [3, 1], ['cover_color' => '#C24E77']);
        $this->story('Night bus', 2);

        $this->siteIndex()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Site')
            ->where('home.pin', null)
            ->where('home.lead.title', 'The Ember Gate')
            ->where('home.lead.label', 'Chapter 2 · 25 Nov')
            ->where('home.lead.image', null)
            ->where('home.lead.color', '#C24E77')
            ->where('home.lead.button', 'Start from chapter 1')
            ->where('home.side', [['label' => 'Story · 24 Nov', 'title' => 'Night bus']])
            ->where('home.next', null)
        );
    }

    public function test_a_pin_leads_home_until_its_day_and_names_what_follows(): void
    {
        $this->novel('The Ember Gate', [3, 1]);
        $this->story('Night bus', 2);

        $this->pin('night-bus', '2026-12-01', '18:00')
            ->assertOk()
            ->assertJsonPath('home.pin.slug', 'night-bus')
            ->assertJsonPath('home.pin.until', '2026-12-01T18:00')
            ->assertJsonPath('home.lead.title', 'Night bus')
            ->assertJsonPath('home.lead.label', 'A short story · 24 Nov')
            ->assertJsonPath('home.lead.button', 'Start reading')
            ->assertJsonPath('home.next', 'The Ember Gate, chapter 2');

        $this->assertSame('2026-12-01T17:00:00+00:00', SiteSetting::get('home_pin')['until']);
        $this->siteIndex()->assertInertia(fn (Assert $page) => $page->where('home.pin.until', '2026-12-01T18:00')->where('home.lead.title', 'Night bus'));
    }

    public function test_a_pin_must_end_in_the_future(): void
    {
        $this->story('Night bus', 2);

        $this->pin('night-bus', '2026-11-26', '09:00')->assertUnprocessable()->assertJsonValidationErrors(['until']);
        $this->assertNull(SiteSetting::get('home_pin'));
    }

    public function test_a_private_work_or_one_with_nothing_out_cannot_lead_home(): void
    {
        Work::factory()->novel()->create(['title' => 'Private', 'slug' => 'private']);
        Work::factory()->novel()->public()->comingSoon()->create(['title' => 'Coming', 'slug' => 'coming']);

        $this->pin('private', '2026-12-01', '18:00')->assertUnprocessable()->assertJsonValidationErrors(['slug']);
        $this->pin('coming', '2026-12-01', '18:00')->assertUnprocessable()->assertJsonValidationErrors(['slug']);
        $this->assertNull(SiteSetting::get('home_pin'));
    }

    public function test_moving_the_pin_to_another_day_keeps_its_time_of_day(): void
    {
        $this->story('Night bus', 2);
        $this->pin('night-bus', '2026-12-01', '18:00')->assertOk();

        $this->asAdmin()->patchJson($this->url('/site/home-pin'), ['until' => '2026-12-05'])
            ->assertOk()
            ->assertJsonPath('home.pin.until', '2026-12-05T18:00');

        $this->assertSame('2026-12-05T17:00:00+00:00', SiteSetting::get('home_pin')['until']);
    }

    public function test_the_pin_date_needs_a_pin_and_a_day_still_ahead(): void
    {
        $this->story('Night bus', 2);

        $this->asAdmin()->patchJson($this->url('/site/home-pin'), ['until' => '2026-12-05'])->assertConflict();

        $this->pin('night-bus', '2026-12-01', '18:00')->assertOk();
        $this->asAdmin()->patchJson($this->url('/site/home-pin'), ['until' => '2026-11-20'])->assertUnprocessable()->assertJsonValidationErrors(['until']);
    }

    public function test_unpinning_puts_the_newest_work_back_at_the_lead(): void
    {
        $this->novel('The Ember Gate', [1]);
        $this->story('Night bus', 2);
        $this->pin('night-bus', '2026-12-01', '18:00')->assertOk();

        $this->asAdmin()->deleteJson($this->url('/site/home-pin'))
            ->assertOk()
            ->assertJsonPath('home.pin', null)
            ->assertJsonPath('home.lead.title', 'The Ember Gate')
            ->assertJsonPath('home.next', null);

        $this->assertNull(SiteSetting::get('home_pin'));
    }

    public function test_a_pin_whose_moment_has_passed_no_longer_leads(): void
    {
        $this->novel('The Ember Gate', [1]);
        $pinned = $this->story('Night bus', 2);
        SiteSetting::put('home_pin', ['slug' => $pinned->slug, 'until' => now()->subMinute()->toIso8601String()]);

        $this->siteIndex()->assertInertia(fn (Assert $page) => $page->where('home.pin', null)->where('home.lead.title', 'The Ember Gate'));
    }

    public function test_the_notice_tab_shows_the_one_up_and_the_earlier_ones_newest_first_with_their_days(): void
    {
        $this->upNotice();
        Notice::factory()->create(['text' => 'Salt Season is now in print in the UK', 'starts_at' => $this->wat('2026-11-01 09:00'), 'ends_at' => $this->wat('2026-11-14 23:59'), 'taken_down_at' => $this->wat('2026-11-14 23:59'), 'closed_count' => 412]);
        Notice::factory()->create(['text' => 'The launch', 'starts_at' => $this->wat('2026-10-25 09:00'), 'ends_at' => $this->wat('2026-11-02 23:59'), 'taken_down_at' => $this->wat('2026-11-02 23:59'), 'closed_count' => 188]);
        Notice::factory()->create(['text' => 'Ended on its own', 'starts_at' => $this->wat('2026-11-10 09:00'), 'ends_at' => $this->wat('2026-11-11 23:59'), 'taken_down_at' => null]);
        Notice::factory()->create(['text' => 'Last year', 'starts_at' => $this->wat('2025-11-25 09:00'), 'ends_at' => $this->wat('2025-11-25 23:59'), 'taken_down_at' => $this->wat('2025-11-25 23:59')]);

        $this->siteIndex()->assertInertia(fn (Assert $page) => $page
            ->where('notice.current.text', 'Signed copies this weekend')
            ->where('notice.current.link', 'in_print')
            ->where('notice.current.state', 'up')
            ->where('notice.current.startsAt', '2026-11-26T09:00')
            ->where('notice.current.endsAt', null)
            ->has('notice.earlier', 4)
            ->where('notice.earlier.0.when', '10–11 Nov')
            ->where('notice.earlier.1.when', '1–14 Nov')
            ->where('notice.earlier.1.closed', 412)
            ->where('notice.earlier.2.when', '25 Oct–2 Nov')
            ->where('notice.earlier.3.when', '25 Nov 2025')
        );
    }

    public function test_a_notice_can_start_now(): void
    {
        $this->asAdmin()->postJson($this->url('/site/notice'), ['text' => 'Signed copies this weekend', 'link' => '', 'starts' => 'now', 'ends' => null])
            ->assertOk()
            ->assertJsonPath('notice.current.text', 'Signed copies this weekend')
            ->assertJsonPath('notice.current.link', '')
            ->assertJsonPath('notice.current.state', 'up')
            ->assertJsonPath('notice.current.startsAt', '2026-11-26T10:00')
            ->assertJsonPath('notice.current.endsAt', null);

        $this->assertNull(Notice::query()->firstOrFail()->link_target);
    }

    public function test_a_notice_can_be_scheduled_for_a_day_at_nine_with_an_end(): void
    {
        $this->asAdmin()->postJson($this->url('/site/notice'), [
            'text' => 'Reading at the library',
            'link' => 'readings',
            'starts' => '2026-12-05',
            'ends' => ['day' => '2026-12-05', 'time' => '18:00'],
        ])
            ->assertOk()
            ->assertJsonPath('notice.current.state', 'sch')
            ->assertJsonPath('notice.current.link', 'readings')
            ->assertJsonPath('notice.current.startsAt', '2026-12-05T09:00')
            ->assertJsonPath('notice.current.endsAt', '2026-12-05T18:00');
    }

    public function test_a_second_notice_is_refused_while_one_is_up_or_scheduled(): void
    {
        $this->upNotice();

        $this->asAdmin()->postJson($this->url('/site/notice'), ['text' => 'Another', 'starts' => 'now'])->assertConflict();
        $this->assertDatabaseCount('notices', 1);
    }

    public function test_a_notice_is_checked_before_it_is_kept(): void
    {
        $this->asAdmin()->postJson($this->url('/site/notice'), ['text' => str_repeat('a', 71), 'starts' => 'now'])->assertUnprocessable()->assertJsonValidationErrors(['text']);
        $this->asAdmin()->postJson($this->url('/site/notice'), ['text' => '', 'starts' => 'now'])->assertUnprocessable()->assertJsonValidationErrors(['text']);
        $this->asAdmin()->postJson($this->url('/site/notice'), ['text' => 'Hi', 'starts' => '2026-11-20'])->assertUnprocessable()->assertJsonValidationErrors(['starts']);
        $this->asAdmin()->postJson($this->url('/site/notice'), ['text' => 'Hi', 'link' => 'nowhere', 'starts' => 'now'])->assertUnprocessable()->assertJsonValidationErrors(['link']);
        $this->asAdmin()->postJson($this->url('/site/notice'), ['text' => 'Hi', 'starts' => '2026-12-05', 'ends' => ['day' => '2026-12-04', 'time' => '10:00']])->assertUnprocessable()->assertJsonValidationErrors(['ends']);

        $this->assertDatabaseCount('notices', 0);
    }

    public function test_editing_the_notice_that_is_up_keeps_its_start_when_it_says_now(): void
    {
        $notice = $this->upNotice();
        $start = $notice->starts_at->toIso8601String();

        $this->asAdmin()->patchJson($this->url('/site/notice/'.$notice->id), ['text' => 'Signed copies now', 'link' => 'about', 'starts' => 'now', 'ends' => null])
            ->assertOk()
            ->assertJsonPath('notice.current.text', 'Signed copies now')
            ->assertJsonPath('notice.current.link', 'about');

        $this->assertSame($start, $notice->fresh()->starts_at->toIso8601String());
    }

    public function test_editing_a_scheduled_notice_to_now_starts_it(): void
    {
        $notice = Notice::factory()->scheduled()->create(['text' => 'Soon', 'link_target' => null]);

        $this->asAdmin()->patchJson($this->url('/site/notice/'.$notice->id), ['text' => 'Soon', 'link' => '', 'starts' => 'now', 'ends' => null])
            ->assertOk()
            ->assertJsonPath('notice.current.state', 'up')
            ->assertJsonPath('notice.current.startsAt', '2026-11-26T10:00');
    }

    public function test_an_earlier_notice_cannot_be_edited(): void
    {
        $notice = Notice::factory()->takenDown()->create();

        $this->asAdmin()->patchJson($this->url('/site/notice/'.$notice->id), ['text' => 'Changed', 'starts' => 'now'])->assertConflict();
        $this->assertNotSame('Changed', $notice->fresh()->text);
    }

    public function test_taking_down_a_notice_that_is_up_moves_it_to_the_earlier_list(): void
    {
        $notice = $this->upNotice('Signed copies this weekend');

        $this->asAdmin()->postJson($this->url('/site/notice/'.$notice->id.'/down'))
            ->assertOk()
            ->assertJsonPath('notice.current', null)
            ->assertJsonPath('notice.earlier.0.when', '26 Nov')
            ->assertJsonPath('notice.earlier.0.text', 'Signed copies this weekend')
            ->assertJsonPath('notice.earlier.0.closed', 0);

        $this->assertNotNull($notice->fresh()->taken_down_at);
    }

    public function test_cancelling_a_scheduled_notice_deletes_it(): void
    {
        $notice = Notice::factory()->scheduled()->create(['text' => 'Not yet']);

        $this->asAdmin()->postJson($this->url('/site/notice/'.$notice->id.'/down'))
            ->assertOk()
            ->assertJsonPath('notice.current', null)
            ->assertJsonPath('notice.earlier', []);

        $this->assertModelMissing($notice);
        $this->asAdmin()->postJson($this->url('/site/notice/'.$notice->id.'/down'))->assertNotFound();
    }

    public function test_a_reader_closing_the_notice_is_counted_only_while_it_is_up(): void
    {
        $up = $this->upNotice();
        $scheduled = Notice::factory()->scheduled()->create();
        $takenDown = Notice::factory()->takenDown()->create();

        $this->post($this->visitor('/notice/'.$up->id.'/closed'))->assertNoContent();
        $this->post($this->visitor('/notice/'.$scheduled->id.'/closed'))->assertNoContent();
        $this->post($this->visitor('/notice/'.$takenDown->id.'/closed'))->assertNoContent();
        $this->post($this->visitor('/notice/999999/closed'))->assertNoContent();

        $this->assertSame(1, $up->fresh()->closed_count);
        $this->assertSame(0, $scheduled->fresh()->closed_count);
        $this->assertSame(0, $takenDown->fresh()->closed_count);
    }

    public function test_the_contact_tab_saves_the_address_line_and_handles_without_the_at_sign(): void
    {
        $this->asAdmin()->patchJson($this->url('/site/contact'), [
            'email' => 'hello@example.com',
            'line' => 'A new line',
            'links' => [['network' => 'Instagram', 'handle' => ' @eyiwetemi.writes '], ['network' => 'X', 'handle' => '']],
        ])->assertOk()->assertJsonPath('saved', true);

        $this->assertSame(['email' => 'hello@example.com'], SiteSetting::get('contact'));
        $this->assertSame(['line' => 'A new line'], SiteSetting::get('footer'));
        $this->assertEquals([['network' => 'Instagram', 'handle' => 'eyiwetemi.writes'], ['network' => 'X', 'handle' => '']], SiteSetting::get('links'));

        $this->siteIndex()->assertInertia(fn (Assert $page) => $page
            ->where('contact.email', 'hello@example.com')
            ->where('contact.line', 'A new line')
            ->where('contact.links.0', ['network' => 'Instagram', 'handle' => 'eyiwetemi.writes'])
            ->where('networks', SocialNetworks::names())
        );
    }

    public function test_the_contact_tab_refuses_a_bad_address_an_unknown_network_or_too_many_links(): void
    {
        $this->asAdmin()->patchJson($this->url('/site/contact'), ['email' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->asAdmin()->patchJson($this->url('/site/contact'), ['email' => 'hello@example.com', 'links' => [['network' => 'MySpace', 'handle' => 'me']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['links.0.network']);

        $links = array_fill(0, 13, ['network' => 'X', 'handle' => 'me']);
        $this->asAdmin()->patchJson($this->url('/site/contact'), ['email' => 'hello@example.com', 'links' => $links])->assertUnprocessable()->assertJsonValidationErrors(['links']);
    }

    public function test_a_sharing_line_is_kept_for_the_site_or_a_work_readers_can_see(): void
    {
        $this->novel('The Ember Gate', [1], ['description' => 'A gate in the mud, and a long way home.']);

        $this->asAdmin()->patchJson($this->url('/site/sharing'), ['slug' => null, 'line' => 'The site line'])
            ->assertOk()
            ->assertJsonPath('sharing.site.line', 'The site line')
            ->assertJsonPath('sharing.site.own', false);

        $this->asAdmin()->patchJson($this->url('/site/sharing'), ['slug' => 'the-ember-gate', 'line' => 'Its own line'])
            ->assertOk()
            ->assertJsonPath('sharing.works.the-ember-gate.line', 'Its own line')
            ->assertJsonPath('sharing.site.line', 'The site line');

        $this->siteIndex()->assertInertia(fn (Assert $page) => $page
            ->where('sharing.site.line', 'The site line')
            ->where('sharing.works.the-ember-gate.line', 'Its own line')
            ->where('sharing.works.the-ember-gate.own', false)
            ->where('sharing.works.the-ember-gate.image', null)
            ->where('works.0.desc', 'A gate in the mud, and a long way home.')
        );
    }

    public function test_a_sharing_change_is_refused_for_a_private_work_or_a_long_line(): void
    {
        Work::factory()->novel()->create(['title' => 'Private', 'slug' => 'private']);

        $this->asAdmin()->patchJson($this->url('/site/sharing'), ['slug' => 'private', 'line' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['slug']);
        $this->asAdmin()->patchJson($this->url('/site/sharing'), ['slug' => null, 'line' => str_repeat('a', 161)])->assertUnprocessable()->assertJsonValidationErrors(['line']);
    }

    public function test_own_picture_needs_a_picture_first(): void
    {
        $this->asAdmin()->patchJson($this->url('/site/sharing'), ['slug' => null, 'own' => true])->assertUnprocessable()->assertJsonValidationErrors(['own']);
    }

    public function test_a_picture_is_cropped_to_the_card_size_and_used_for_the_site(): void
    {
        $this->asAdmin()->post($this->url('/site/sharing/picture'), ['image' => UploadedFile::fake()->image('share.jpg', 2400, 1000)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('sharing.site.own', true)
            ->assertJsonPath('sharing.site.image', fn (string $url): bool => str_contains($url, '/storage/share/'));

        $media = Media::query()->latest('id')->firstOrFail();
        $this->assertSame([1200, 630], [$media->width, $media->height]);
        $this->assertSame('image/jpeg', $media->mime);

        [$width, $height, $type] = getimagesizefromstring(Storage::disk('public')->get($media->path));
        $this->assertSame([1200, 630, IMAGETYPE_JPEG], [$width, $height, $type]);
    }

    public function test_a_picture_for_a_work_is_kept_for_that_work_and_a_replaced_one_is_deleted(): void
    {
        $this->novel('The Ember Gate', [1]);

        $this->asAdmin()->post($this->url('/site/sharing/picture'), ['image' => UploadedFile::fake()->image('a.jpg', 900, 900), 'slug' => 'the-ember-gate'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('sharing.works.the-ember-gate.own', true);
        $first = Media::query()->latest('id')->firstOrFail();

        $this->asAdmin()->post($this->url('/site/sharing/picture'), ['image' => UploadedFile::fake()->image('b.jpg', 900, 900), 'slug' => 'the-ember-gate'], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertDatabaseMissing('media', ['id' => $first->id]);
        Storage::disk('public')->assertMissing($first->path);
        $this->assertSame(1, Media::query()->count());
    }

    public function test_a_picture_that_is_not_a_picture_is_refused(): void
    {
        $this->asAdmin()->post($this->url('/site/sharing/picture'), ['image' => UploadedFile::fake()->create('notes.pdf', 20)], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);
    }

    public function test_the_site_card_is_a_jpeg_and_a_public_works_card_is_too(): void
    {
        $this->novel('The Ember Gate', [1]);

        $site = $this->get($this->visitor('/share/site.jpg'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('max-age=3600', (string) $site->headers->get('Cache-Control'));
        $this->assertSame([1200, 630, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($site->getContent()), 0, 3));

        $work = $this->get($this->visitor('/share/the-ember-gate.jpg'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame([1200, 630, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($work->getContent()), 0, 3));

        $this->assertCount(2, Storage::disk('local')->allFiles('share-cards'));
    }

    public function test_a_private_work_has_no_share_card(): void
    {
        Work::factory()->novel()->create(['title' => 'Private', 'slug' => 'private']);

        $this->get($this->visitor('/share/private.jpg'))->assertNotFound();
        $this->get($this->visitor('/share/missing.jpg'))->assertNotFound();
    }

    public function test_the_card_is_the_own_picture_once_one_is_set_and_redrawn_when_its_line_changes(): void
    {
        $this->asAdmin()->post($this->url('/site/sharing/picture'), ['image' => UploadedFile::fake()->image('share.jpg', 1600, 900)], ['Accept' => 'application/json'])->assertOk();
        $media = Media::query()->latest('id')->firstOrFail();

        $this->get($this->visitor('/share/site.jpg'))->assertOk()->assertContent(Storage::disk('public')->get($media->path));

        $this->asAdmin()->patchJson($this->url('/site/sharing'), ['slug' => null, 'own' => false])->assertOk();
        $this->get($this->visitor('/share/site.jpg'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertNotSame(Storage::disk('public')->get($media->path), $this->get($this->visitor('/share/site.jpg'))->getContent());
    }

    public function test_the_home_page_carries_the_site_share_meta(): void
    {
        $this->get($this->visitor('/'))
            ->assertOk()
            ->assertSee('<meta property="og:type" content="website">', false)
            ->assertSee('<meta property="og:site_name" content="Eyiwetemi">', false)
            ->assertSee('<meta property="og:title" content="Eyiwetemi">', false)
            ->assertSee('<meta property="og:description" content="Everything here is free to read.">', false)
            ->assertSee('<meta property="og:image" content="'.url('/share/site.jpg').'">', false)
            ->assertSee('<meta property="og:image:width" content="1200">', false)
            ->assertSee('<meta property="og:image:height" content="630">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false);
    }

    public function test_a_work_page_and_its_chapter_carry_the_work_title_and_its_card(): void
    {
        $this->novel('The Ember Gate', [1]);
        $this->asAdmin()->patchJson($this->url('/site/sharing'), ['slug' => 'the-ember-gate', 'line' => 'Its own line'])->assertOk();

        $this->get($this->visitor('/library/the-ember-gate'))
            ->assertOk()
            ->assertSee('<meta property="og:title" content="The Ember Gate">', false)
            ->assertSee('<meta property="og:description" content="Its own line">', false)
            ->assertSee('<meta property="og:image" content="'.url('/share/the-ember-gate.jpg').'">', false);

        $this->get($this->visitor('/library/the-ember-gate/1'))
            ->assertOk()
            ->assertSee('<meta property="og:title" content="The Ember Gate">', false);
    }
}
