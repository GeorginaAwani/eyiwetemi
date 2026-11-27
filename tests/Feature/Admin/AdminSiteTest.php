<?php

namespace Tests\Feature\Admin;

use App\Enums\PublishStatus;
use App\Enums\SitePageKey;
use App\Models\LinkCheck;
use App\Models\Media;
use App\Models\Note;
use App\Models\SitePage;
use App\Models\User;
use App\Models\Work;
use App\Notifications\SiteChanged;
use App\Support\NotificationPresenter;
use App\Support\SitePublisher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminSiteTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

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
     * An About draft whose hello is the given paragraph, with nothing else on it.
     *
     * @return array<string, mixed>
     */
    private function about(string $paragraph = 'one two three'): array
    {
        return [
            'image' => null,
            'hello' => ['heading' => 'Hi', 'paragraphs' => [$paragraph]],
            'sections' => [],
            'books' => '',
            'home' => ['quote' => '', 'line' => ''],
        ];
    }

    /**
     * A Privacy or Terms draft with the given sections (id, heading, html each).
     *
     * @param  list<array{id: string, heading: string, html: string}>  $sections
     * @return array<string, mixed>
     */
    private function contents(array $sections, ?array $change = null): array
    {
        return ['updated_on' => '2026-11-01', 'sections' => $sections, 'change' => $change];
    }

    /**
     * @return list<array{id: string, heading: string, html: string}>
     */
    private function privacySections(string $rightsHtml = '<p>You can ask for a copy.</p>'): array
    {
        return [
            ['id' => 'who', 'heading' => 'Who looks after it', 'html' => '<p>Write to me.</p>'],
            ['id' => 'rights', 'heading' => 'Your rights', 'html' => $rightsHtml],
        ];
    }

    /**
     * An In print draft with one book in one format at one store, one pre-order and one reading.
     *
     * @return array<string, mixed>
     */
    private function inPrint(): array
    {
        return [
            'books' => [[
                'slug' => 'the-ember-gate',
                'title' => 'The Ember Gate',
                'kind' => 'novel',
                'cover' => '#C24E77',
                'formats' => [[
                    'format' => 'Paperback',
                    'stores' => [[
                        'store' => 'Amazon',
                        'price' => ['amount' => 850000, 'currency' => 'NGN'],
                        'url' => 'https://example.org/ember',
                        'ok' => true,
                    ]],
                ]],
            ]],
            'coming' => [[
                'slug' => 'night-bus',
                'title' => 'Night bus',
                'kind' => 'story',
                'cover' => '#DD6F94',
                'when' => 'Dec 2026',
                'out' => ['d' => '', 'm' => '12', 'y' => '2026'],
                'mode' => 'pre',
                'store' => 'Okada Books',
                'price' => ['amount' => 1200, 'currency' => 'USD'],
                'url' => 'https://example.org/night-bus',
            ]],
            'events' => [[
                'id' => 'e1',
                'title' => 'Reading at the library',
                'starts_at' => '2026-12-05T09:00:00+00:00',
                'place' => 'Port Harcourt',
                'online' => false,
                'url' => null,
                'cancelled' => false,
            ]],
        ];
    }

    private function emberGate(): Work
    {
        return Work::factory()->novel()->public()->published()->create([
            'title' => 'The Ember Gate',
            'title_key' => 'the ember gate',
            'slug' => 'the-ember-gate',
        ]);
    }

    private function nightBus(): Work
    {
        return Work::factory()->story()->public()->published()->create([
            'title' => 'Night bus',
            'title_key' => 'night bus',
            'slug' => 'night-bus',
        ]);
    }

    /**
     * The page's props as Inertia sends them to the page: the JSON form, which needs no built assets.
     */
    private function siteIndex(): TestResponse
    {
        return $this->asAdmin()->get($this->url('/site'))->assertOk();
    }

    private function saveDraft(string $key, array $draft): TestResponse
    {
        return $this->asAdmin()->patchJson($this->url('/site/pages/'.$key), ['draft' => $draft]);
    }

    private function publish(string $key, array $body = []): TestResponse
    {
        return $this->asAdmin()->postJson($this->url('/site/pages/'.$key.'/publish'), $body);
    }

    public function test_guests_and_readers_are_kept_away_from_the_site_pages(): void
    {
        $this->get($this->url('/site'))->assertRedirect(route('admin.signin'));

        $this->actingAs($this->reader)->withSession(['admin.verified' => true])
            ->patchJson($this->url('/site/pages/about'), ['draft' => $this->about()])
            ->assertUnauthorized();
        $this->assertDatabaseCount('site_pages', 0);
    }

    public function test_the_page_lists_each_site_page_with_its_draft_live_and_history(): void
    {
        $this->emberGate();
        $this->nightBus();
        $this->saveDraft('about', $this->about())->assertOk();
        $this->publish('about')->assertOk();

        $this->siteIndex()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Site')
            ->where('pages.about.draft.hello.heading', 'Hi')
            ->where('pages.about.live.hello.paragraphs.0', 'one two three')
            ->where('pages.about.savedAt', '2026-11-26T10:00')
            ->where('pages.about.publishedAt', '2026-11-26T10:00')
            ->where('pages.about.versions.0.changes', 'First version')
            ->where('pages.about.versions.0.matters', false)
            ->where('pages.in_print.live', null)
            ->where('pages.in_print.versions', [])
            ->where('pages.privacy.draft.updated_on', '2026-11-26')
            ->where('pages.privacy.draft.change', null)
            ->where('pages.terms.live', null)
            ->where('works.0.slug', 'night-bus')
            ->where('works.0.kind', 'story')
            ->where('works.0.public', true)
            ->where('works.1.slug', 'the-ember-gate')
            ->where('links', [])
            ->where('portrait.draft', null)
            ->where('portrait.live', null)
        );
    }

    public function test_a_draft_is_saved_without_changing_what_is_live(): void
    {
        $this->saveDraft('about', $this->about())->assertOk()->assertJsonPath('savedAt', '2026-11-26T10:00');
        $this->publish('about')->assertOk();
        $live = SitePage::query()->firstWhere('key', SitePageKey::About)->live;

        $this->saveDraft('about', $this->about('four five six'))->assertOk();

        $page = SitePage::query()->firstWhere('key', SitePageKey::About);
        $this->assertSame('four five six', $page->draft['hello']['paragraphs'][0]);
        $this->assertSame($live, $page->live);
    }

    public function test_a_save_keeps_only_the_keys_a_page_knows(): void
    {
        $this->saveDraft('about', [...$this->about(), 'secret' => 'not kept'])->assertOk();

        $this->assertArrayNotHasKey('secret', SitePage::query()->firstWhere('key', SitePageKey::About)->draft);
    }

    public function test_privacy_and_terms_html_is_cleaned_on_the_way_in(): void
    {
        $sections = [['id' => 'who', 'heading' => 'Who', 'html' => '<p>Hello<script>alert(1)</script> <a href="javascript:x()">me</a></p>']];

        $this->saveDraft('privacy', $this->contents($sections))->assertOk();

        $html = SitePage::query()->firstWhere('key', SitePageKey::Privacy)->draft['sections'][0]['html'];
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('javascript', $html);
        $this->assertStringContainsString('Hello', $html);
    }

    public function test_an_in_print_address_without_a_scheme_is_saved_with_https(): void
    {
        $this->emberGate();
        $this->nightBus();

        $this->saveDraft('in_print', $this->inPrint())->assertOk();
        $draft = SitePage::query()->firstWhere('key', SitePageKey::InPrint)->draft;
        $this->assertSame('https://example.org/ember', $draft['books'][0]['formats'][0]['stores'][0]['url']);

        $draft = $this->inPrint();
        $draft['books'][0]['formats'][0]['stores'][0]['url'] = 'example.org/shop';
        $this->saveDraft('in_print', $draft)->assertOk();
        $this->assertSame('https://example.org/shop', SitePage::query()->firstWhere('key', SitePageKey::InPrint)->draft['books'][0]['formats'][0]['stores'][0]['url']);
    }

    public function test_a_draft_that_breaks_a_rule_is_refused_with_the_field_named(): void
    {
        $this->emberGate();
        $this->nightBus();

        $currency = $this->inPrint();
        $currency['books'][0]['formats'][0]['stores'][0]['price']['currency'] = 'JPY';
        $this->saveDraft('in_print', $currency)->assertUnprocessable()->assertJsonValidationErrors(['draft.books.0.formats.0.stores.0.price.currency']);

        $slug = $this->inPrint();
        $slug['books'][0]['slug'] = 'no-such-book';
        $this->saveDraft('in_print', $slug)->assertUnprocessable()->assertJsonValidationErrors(['draft.books.0.slug']);

        $heading = $this->about();
        $heading['hello']['heading'] = str_repeat('a', 121);
        $this->saveDraft('about', $heading)->assertUnprocessable()->assertJsonValidationErrors(['draft.hello.heading']);

        $this->assertDatabaseCount('site_pages', 0);
    }

    public function test_publishing_copies_the_draft_live_and_keeps_a_version_of_it(): void
    {
        $this->saveDraft('about', $this->about())->assertOk();

        $this->publish('about')
            ->assertOk()
            ->assertJsonPath('page.live.hello.paragraphs.0', 'one two three')
            ->assertJsonPath('page.publishedAt', '2026-11-26T10:00')
            ->assertJsonPath('page.versions.0.changes', 'First version')
            ->assertJsonPath('noteId', null);

        $page = SitePage::query()->firstWhere('key', SitePageKey::About);
        $this->assertSame($page->draft, $page->live);
        $this->assertCount(1, $page->versions);
        $this->assertSame(4, $page->versions->first()->words);
    }

    public function test_publishing_an_unchanged_draft_is_refused(): void
    {
        $this->saveDraft('about', $this->about())->assertOk();
        $this->publish('about')->assertOk();

        $this->publish('about')->assertStatus(409);
        $this->assertCount(1, SitePage::query()->firstWhere('key', SitePageKey::About)->versions);
    }

    public function test_the_history_counts_the_words_added_and_cut_between_versions(): void
    {
        $this->saveDraft('about', $this->about('one two three'))->assertOk();
        $this->publish('about')->assertOk();
        $this->saveDraft('about', $this->about('one two four five'))->assertOk();
        $this->publish('about')->assertOk();

        $this->siteIndex()->assertInertia(fn (Assert $page) => $page
            ->where('pages.about.versions.0.changes', '+2 words, −1')
            ->where('pages.about.versions.1.changes', 'First version')
        );
    }

    public function test_privacy_cannot_be_published_without_a_section(): void
    {
        $this->saveDraft('privacy', $this->contents([]))->assertOk();

        $this->publish('privacy')->assertUnprocessable()->assertJsonValidationErrors(['sections']);
        $this->assertNull(SitePage::query()->firstWhere('key', SitePageKey::Privacy)->live);
    }

    public function test_publishing_privacy_dates_it_today_and_a_change_that_matters_writes_a_note_and_tells_readers(): void
    {
        $this->saveDraft('privacy', $this->contents($this->privacySections()))->assertOk();
        $this->publish('privacy')->assertOk();

        $this->saveDraft('privacy', $this->contents($this->privacySections('<p>You can ask for a copy of it.</p>')))->assertOk();
        $response = $this->publish('privacy', ['matters' => true, 'line' => '  I’ve added what I keep and why.  '])
            ->assertOk()
            ->assertJsonPath('page.live.updated_on', '2026-11-26')
            ->assertJsonPath('page.live.change.line', 'I’ve added what I keep and why.')
            ->assertJsonPath('page.live.change.ids', ['rights'])
            ->assertJsonPath('page.versions.0.matters', true)
            ->assertJsonPath('page.versions.0.changes', 'I’ve added what I keep and why.');

        $noteId = $response->json('noteId');
        $note = Note::query()->findOrFail($noteId);
        $this->assertSame(PublishStatus::Draft, $note->status);
        $this->assertSame('I’ve changed my Privacy page. I’ve added what I keep and why.', $note->draft_text);
        $this->assertSame('/privacy', $note->draft_body['content'][0]['content'][1]['marks'][0]['attrs']['href']);

        $this->assertSame(1, $this->reader->notifications()->count());
        $this->assertSame(['kind' => 'site_change', 'page' => 'privacy', 'line' => 'I’ve added what I keep and why.'], $this->reader->notifications()->first()->data);
        $this->assertSame(0, $this->author->notifications()->count());
    }

    public function test_without_a_line_the_change_names_the_sections_it_touched(): void
    {
        $this->saveDraft('terms', $this->contents([['id' => 'writing', 'heading' => 'My writing', 'html' => '<p>Please don’t copy it.</p>']]))->assertOk();
        $this->publish('terms')->assertOk();

        $sections = [
            ['id' => 'writing', 'heading' => 'My writing', 'html' => '<p>Please don’t copy it, even a little.</p>'],
            ['id' => 'comments', 'heading' => 'Comments', 'html' => '<p>Be kind.</p>'],
        ];
        $this->saveDraft('terms', $this->contents($sections))->assertOk();
        $this->publish('terms', ['matters' => true])
            ->assertOk()
            ->assertJsonPath('page.live.change.line', 'Changes to My writing and Comments.')
            ->assertJsonPath('page.live.change.ids', ['writing', 'comments']);

        $this->assertSame('Terms', Note::query()->firstOrFail()->draft_body['content'][0]['content'][1]['text']);
        $this->assertSame('/terms', Note::query()->firstOrFail()->draft_body['content'][0]['content'][1]['marks'][0]['attrs']['href']);
    }

    public function test_a_change_that_does_not_matter_writes_no_note_and_tells_no_one(): void
    {
        $this->saveDraft('privacy', $this->contents($this->privacySections()))->assertOk();
        $this->publish('privacy')->assertOk();
        $this->saveDraft('privacy', $this->contents($this->privacySections('<p>Changed a little.</p>')))->assertOk();

        $this->publish('privacy')->assertOk()->assertJsonPath('noteId', null)->assertJsonPath('page.live.change', null);
        $this->assertSame(0, Note::query()->count());
        $this->assertSame(0, $this->reader->notifications()->count());
    }

    public function test_undo_takes_a_publish_back_with_its_version_note_and_notices(): void
    {
        $this->saveDraft('privacy', $this->contents($this->privacySections()))->assertOk();
        $this->publish('privacy')->assertOk();
        $previous = SitePage::query()->firstWhere('key', SitePageKey::Privacy)->live;

        $this->saveDraft('privacy', $this->contents($this->privacySections('<p>Changed.</p>')))->assertOk();
        $noteId = $this->publish('privacy', ['matters' => true, 'line' => 'Changed my rights section.'])->json('noteId');
        $this->assertSame(1, $this->reader->notifications()->count());

        $this->asAdmin()->postJson($this->url('/site/pages/privacy/undo'))
            ->assertOk()
            ->assertJsonPath('page.live.change', null)
            ->assertJsonCount(1, 'page.versions');

        $page = SitePage::query()->firstWhere('key', SitePageKey::Privacy);
        $this->assertSame($previous, $page->live);
        $this->assertCount(1, $page->versions);
        $this->assertNull(Note::query()->find($noteId));
        $this->assertSame(0, $this->reader->notifications()->count());
        $this->assertFalse(Cache::has(SitePublisher::undoKey(SitePageKey::Privacy)));
        $this->asAdmin()->postJson($this->url('/site/pages/privacy/undo'))->assertStatus(409);
    }

    public function test_undo_is_only_open_for_two_minutes(): void
    {
        $this->saveDraft('about', $this->about())->assertOk();
        $this->publish('about')->assertOk();
        $this->saveDraft('about', $this->about('four five six'))->assertOk();
        $this->publish('about')->assertOk();

        $this->travel(3)->minutes();

        $this->asAdmin()->postJson($this->url('/site/pages/about/undo'))->assertStatus(409);
        $this->assertCount(2, SitePage::query()->firstWhere('key', SitePageKey::About)->versions);
    }

    public function test_restoring_a_version_puts_its_text_into_the_draft_without_publishing_it(): void
    {
        $this->saveDraft('about', $this->about('one two three'))->assertOk();
        $this->publish('about')->assertOk();
        $version = SitePage::query()->firstWhere('key', SitePageKey::About)->versions()->first();

        $this->saveDraft('about', $this->about('four five six'))->assertOk();
        $this->publish('about')->assertOk();

        $this->asAdmin()->postJson($this->url('/site/pages/about/versions/'.$version->id.'/restore'))
            ->assertOk()
            ->assertJsonPath('page.draft.hello.paragraphs.0', 'one two three')
            ->assertJsonPath('page.live.hello.paragraphs.0', 'four five six');

        $page = SitePage::query()->firstWhere('key', SitePageKey::About);
        $this->assertArrayNotHasKey('_meta', $page->draft);
        $this->assertSame('one two three', $page->draft['hello']['paragraphs'][0]);
    }

    public function test_a_version_of_another_page_cannot_be_restored_here(): void
    {
        $this->saveDraft('about', $this->about())->assertOk();
        $this->publish('about')->assertOk();
        $version = SitePage::query()->firstWhere('key', SitePageKey::About)->versions()->first();

        $this->asAdmin()->postJson($this->url('/site/pages/terms/versions/'.$version->id.'/restore'))->assertNotFound();
    }

    public function test_a_portrait_is_stored_and_its_address_comes_back(): void
    {
        Storage::fake(config('eyiwetemi.media_disk'));

        $response = $this->asAdmin()->postJson($this->url('/site/portrait'), [
            'image' => UploadedFile::fake()->image('me.jpg', 600, 600),
        ])->assertCreated()->assertJsonStructure(['id', 'src']);

        $media = Media::query()->findOrFail($response->json('id'));
        $this->assertStringStartsWith('portraits/', $media->path);
        $this->assertSame('image/webp', $media->mime);
        Storage::disk(config('eyiwetemi.media_disk'))->assertExists($media->path);
        $this->assertSame('/storage/'.$media->path, $response->json('src'));

        $this->saveDraft('about', [...$this->about(), 'image' => $media->id])->assertOk();
        $this->siteIndex()->assertInertia(fn (Assert $page) => $page
            ->where('portrait.draft', $response->json('src'))
            ->where('portrait.live', null)
        );
    }

    public function test_a_link_is_checked_and_its_result_is_kept(): void
    {
        Http::fake([
            'https://example.org/*' => Http::response('', 200),
        ]);

        $this->asAdmin()->postJson($this->url('/site/link-check'), ['url' => 'example.org/ember'])
            ->assertOk()
            ->assertJson(['ok' => true, 'checkedOn' => '2026-11-26']);

        $check = LinkCheck::query()->firstWhere('url', 'https://example.org/ember');
        $this->assertTrue($check->ok);
        $this->assertSame(200, $check->status_code);
    }

    public function test_a_link_that_does_not_open_is_recorded_as_broken(): void
    {
        Http::fake([
            'https://example.org/*' => Http::response('', 404),
        ]);

        $this->asAdmin()->postJson($this->url('/site/link-check'), ['url' => 'https://example.org/gone'])
            ->assertOk()
            ->assertJson(['ok' => false]);

        $check = LinkCheck::query()->firstWhere('url', 'https://example.org/gone');
        $this->assertFalse($check->ok);
        $this->assertSame(404, $check->status_code);
    }

    public function test_a_redirect_is_followed_but_never_to_this_machine(): void
    {
        Http::fake([
            'https://example.org/moved' => Http::response('', 301, ['Location' => '/shop']),
            'https://example.org/shop' => Http::response('', 200),
            'https://example.org/sneaky' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin']),
        ]);

        $this->asAdmin()->postJson($this->url('/site/link-check'), ['url' => 'https://example.org/moved'])->assertJson(['ok' => true]);
        $this->asAdmin()->postJson($this->url('/site/link-check'), ['url' => 'https://example.org/sneaky'])->assertJson(['ok' => false]);

        $this->assertFalse(LinkCheck::query()->firstWhere('url', 'https://example.org/sneaky')->ok);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '127.0.0.1'));
    }

    public function test_an_address_on_this_machine_is_refused_and_never_opened(): void
    {
        Http::fake();

        $this->asAdmin()->postJson($this->url('/site/link-check'), ['url' => 'http://127.0.0.1/'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['url']);

        Http::assertNothingSent();
        $this->assertSame(0, LinkCheck::query()->count());
    }

    public function test_the_daily_check_rechecks_every_live_store_and_pre_order_link(): void
    {
        $this->emberGate();
        $this->nightBus();
        $this->saveDraft('in_print', $this->inPrint())->assertOk();
        $this->publish('in_print')->assertOk();
        Http::fake([
            'https://example.org/ember' => Http::response('', 200),
            'https://example.org/night-bus' => Http::response('', 410),
        ]);

        $this->artisan('eyiwetemi:check-links')->assertSuccessful();

        $this->assertTrue(LinkCheck::query()->firstWhere('url', 'https://example.org/ember')->ok);
        $this->assertFalse(LinkCheck::query()->firstWhere('url', 'https://example.org/night-bus')->ok);
        $this->assertSame(2, LinkCheck::query()->count());
    }

    public function test_the_page_shows_each_link_with_its_last_check(): void
    {
        $this->emberGate();
        $this->nightBus();
        $this->saveDraft('in_print', $this->inPrint())->assertOk();
        LinkCheck::factory()->create(['url' => 'https://example.org/ember', 'url_hash' => hash('sha256', 'https://example.org/ember'), 'ok' => false, 'status_code' => 404, 'checked_at' => now()->subDay()]);

        $this->siteIndex()->assertInertia(fn (Assert $page) => $page
            ->where('links', ['https://example.org/ember' => ['ok' => false, 'checkedOn' => '2026-11-25']])
        );
    }

    public function test_a_site_change_notice_reads_as_the_page_it_is_about(): void
    {
        $terms = NotificationPresenter::present(['kind' => 'site_change', 'page' => 'terms', 'line' => 'Changes to Comments.']);

        $this->assertSame(['title' => 'My Terms have changed', 'small' => 'Changes to Comments.', 'href' => '/terms', 'thumb' => ['type' => 'mark']], $terms);
        $this->assertSame('/privacy', NotificationPresenter::present(['kind' => 'site_change', 'page' => 'privacy', 'line' => ''])['href']);
        $this->assertNull(NotificationPresenter::present(['kind' => 'site_change', 'page' => 'about', 'line' => 'x']));
    }

    public function test_site_changes_are_queued_to_the_site_only(): void
    {
        $notice = new SiteChanged('terms', 'Changes to Comments.');

        $this->assertSame(['database'], $notice->via($this->reader));
        $this->assertSame(['kind' => 'site_change', 'page' => 'terms', 'line' => 'Changes to Comments.'], $notice->toArray($this->reader));
    }
}
