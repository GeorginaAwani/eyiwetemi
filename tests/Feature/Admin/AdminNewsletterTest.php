<?php

namespace Tests\Feature\Admin;

use App\Enums\PublishStatus;
use App\Enums\SendStatus;
use App\Enums\WorkItemType;
use App\Mail\Newsletter;
use App\Mail\NewsletterWelcome;
use App\Models\NewsletterSend;
use App\Models\NewsletterStop;
use App\Models\Note;
use App\Models\Subscriber;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use App\Support\NewsletterEmail;
use App\Support\NewsletterPlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminNewsletterTest extends TestCase
{
    use RefreshDatabase;

    /** Three sentences of twenty words each: an excerpt keeps the first two, the third would go past 45 words. */
    private const LONG_CHAPTER = 'Alpha bravo charlie delta echo foxtrot golf hotel india juliet kilo lima mike november oscar papa quebec romeo sierra tango. '
        .'Uniform victor whiskey xray yankee zulu amber birch cedar dune ember fern grove heath ivy juniper kelp larch maple nettle. '
        .'Pebble quartz river stone thorn umber velvet willow yarrow zephyr brook cairn dale elm field gorse hazel iris jade knoll.';

    private User $author;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();

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
     * A West Africa Time moment, in UTC as the database keeps it.
     */
    private function at(string $wat): CarbonImmutable
    {
        return CarbonImmutable::parse($wat, 'Africa/Lagos')->utc();
    }

    /**
     * A document of one paragraph of plain text.
     *
     * @return array<string, mixed>
     */
    private function doc(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['id' => 'p1'], 'content' => [['type' => 'text', 'text' => $text]]]]];
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
     * A chapter (or piece) of a work with the given text, in whatever state the attributes give it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function chapter(Work $work, int $position, string $text, array $attributes = []): WorkItem
    {
        return WorkItem::factory()->for($work)->chapter()->create([
            'position' => $position,
            'title' => null,
            'draft_body' => $this->doc($text),
            'draft_text' => $text,
            'draft_words' => str_word_count($text),
            'status' => PublishStatus::Draft,
            ...$attributes,
        ]);
    }

    /**
     * A chapter that is out, published at the given time.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function outChapter(Work $work, int $position, string $text, CarbonImmutable $publishedAt, array $attributes = []): WorkItem
    {
        return $this->chapter($work, $position, $text, [
            'status' => PublishStatus::Published,
            'live_body' => $this->doc($text),
            'live_text' => $text,
            'live_words' => str_word_count($text),
            'published_at' => $publishedAt,
            'live_updated_at' => $publishedAt,
            ...$attributes,
        ]);
    }

    /**
     * A note that is out, published at the given time and sent to followers unless told otherwise.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function note(string $text, CarbonImmutable $publishedAt, array $attributes = []): Note
    {
        return Note::factory()->create([
            'status' => PublishStatus::Published,
            'draft_body' => $this->doc($text),
            'draft_text' => $text,
            'live_body' => $this->doc($text),
            'live_text' => $text,
            'notify_followers' => true,
            'published_at' => $publishedAt,
            'live_updated_at' => $publishedAt,
            ...$attributes,
        ]);
    }

    /**
     * A reader's address: confirmed on the given day, or still waiting to confirm when none is given.
     *
     * @param  list<string>  $kinds
     */
    private function subscriber(string $email, array $kinds, ?CarbonImmutable $confirmedAt = null): Subscriber
    {
        return Subscriber::factory()->create([
            'email' => $email,
            'kinds' => $kinds,
            'confirmed_at' => $confirmedAt,
            'confirm_token_hash' => $confirmedAt === null ? hash('sha256', $email) : null,
        ]);
    }

    private function send(string $wat, SendStatus $status, array $attributes = []): NewsletterSend
    {
        return NewsletterSend::factory()->create([
            'scheduled_for' => $this->at($wat)->utc(),
            'status' => $status,
            ...$attributes,
        ]);
    }

    private function attach(NewsletterSend $send, Model $item, int $position, bool $leftOut = false): void
    {
        $send->items()->create([
            'item_type' => $item->getMorphClass(),
            'item_id' => $item->getKey(),
            'left_out' => $leftOut,
            'position' => $position,
        ]);
    }

    /**
     * The key the newsletter gives an item in the upcoming send.
     */
    private function keyOf(Model $item): string
    {
        $row = NewsletterPlan::next()->items()
            ->where('item_type', $item->getMorphClass())
            ->where('item_id', $item->getKey())
            ->firstOrFail();

        return 'i'.$row->id;
    }

    public function test_guests_and_readers_are_kept_away_from_the_newsletter(): void
    {
        $this->get($this->url('/newsletter'))->assertRedirect(route('admin.signin'));

        $this->actingAs($this->reader)->withSession(['admin.verified' => true])
            ->putJson($this->url('/newsletter/weekly'), ['day' => 3, 'time' => '18:00'])
            ->assertUnauthorized();

        $this->postJson($this->url('/newsletter/test'), ['what' => 'send'])->assertUnauthorized();

        $this->assertSame(0, NewsletterSend::query()->count());
    }

    public function test_the_page_shows_the_upcoming_send_with_what_is_in_it_and_how_many_readers_get_it(): void
    {
        $ember = $this->emberGate();
        $this->send('2026-11-22 09:00', SendStatus::Sent);
        $this->outChapter($ember, 1, 'Sent already.', $this->at('2026-11-20 10:00'));
        $this->outChapter($ember, 2, 'Two sentences here. And the second.', $this->at('2026-11-24 10:00'), ['title' => 'Morning, after']);
        $this->chapter($ember, 3, 'Chapter three is coming.', [
            'status' => PublishStatus::Scheduled,
            'scheduled_at' => $this->at('2026-11-27 18:00'),
        ]);
        $this->note('A note about the lamps.', $this->at('2026-11-25 12:00'));
        $this->note('Quiet, so not in the newsletter.', $this->at('2026-11-25 13:00'), ['notify_followers' => false]);

        $this->subscriber('ada@example.com', ['novels'], $this->at('2026-11-20 10:00'));
        $this->subscriber('bisi@example.com', ['notes'], $this->at('2026-11-25 10:00'));
        $this->subscriber('chidi@example.com', ['poems'], $this->at('2026-11-10 10:00'));
        $this->subscriber('dayo@example.com', ['novels']);

        $this->asAdmin()->get($this->url('/newsletter'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Newsletter')
                ->where('tab', 's')
                ->where('now', '2026-11-26T10:00')
                ->where('weekly.day', 0)
                ->where('weekly.time', '09:00')
                ->where('next.at', '2026-11-29T09:00')
                ->where('next.after', '2026-12-06')
                ->where('next.moved', false)
                ->where('next.held', false)
                ->where('next.lines', '')
                ->where('next.readers', 2)
                ->has('next.items', 3)
                ->where('next.items.0.kind', 'novels')
                ->where('next.items.0.where', 'The Ember Gate, chapter 2')
                ->where('next.items.0.title', 'Morning, after')
                ->where('next.items.0.when', '2026-11-24')
                ->where('next.items.0.scheduled', false)
                ->where('next.items.0.out', false)
                ->where('next.items.1.kind', 'notes')
                ->where('next.items.1.where', 'A note')
                ->where('next.items.1.text', 'A note about the lamps.')
                ->where('next.items.2.where', 'The Ember Gate, chapter 3')
                ->where('next.items.2.when', '2026-11-27T18:00')
                ->where('next.items.2.scheduled', true)
                ->where('sent.0.day', '2026-11-22')
                ->where('sent.0.status', 'sent')
                ->where('subscribers.total', 3)
                ->where('subscribers.unconfirmed', 1)
                ->where('subscribers.newThisWeek', 2)
                ->where('subscribers.kinds.novels', 1)
                ->where('subscribers.kinds.notes', 1)
                ->where('subscribers.kinds.poems', 1)
                ->has('subscribers.growth', 12)
                ->where('subscribers.growth.11', 3)
                ->has('works', 1)
                ->where('works.0.slug', 'the-ember-gate'));

        $this->assertSame(1, NewsletterSend::query()->where('status', SendStatus::Planned)->count());
    }

    public function test_the_tab_in_the_address_opens_the_subscribers_or_the_welcome_letter(): void
    {
        $this->asAdmin()->get($this->url('/newsletter?tab=subs'))->assertInertia(fn (Assert $page) => $page->where('tab', 'u'));
        $this->asAdmin()->get($this->url('/newsletter?tab=welcome'))->assertInertia(fn (Assert $page) => $page->where('tab', 'w'));
    }

    public function test_the_sent_list_names_what_each_send_carried_newest_first(): void
    {
        $ember = $this->emberGate();
        $story = $this->nightBus();
        $sent = $this->send('2026-11-15 09:00', SendStatus::Sent, ['sent_count' => 40, 'bounced_count' => 2, 'stopped_count' => 1]);
        $this->send('2026-11-08 09:00', SendStatus::Sent);
        $this->send('2026-11-01 09:00', SendStatus::Held);

        $chapter = $this->outChapter($ember, 1, 'Opening words.', $this->at('2026-11-14 10:00'));
        $leftOut = $this->outChapter($ember, 2, 'Left out of the send.', $this->at('2026-11-14 11:00'));
        $piece = $this->outChapter($story, 1, 'A bus at night.', $this->at('2026-11-14 12:00'), ['type' => WorkItemType::Piece]);
        $this->attach($sent, $chapter, 1);
        $this->attach($sent, $piece, 2);
        $this->attach($sent, $leftOut, 3, leftOut: true);
        $this->attach($sent, $this->note('First note.', $this->at('2026-11-14 13:00')), 4);
        $this->attach($sent, $this->note('Second note.', $this->at('2026-11-14 14:00')), 5);

        $this->asAdmin()->get($this->url('/newsletter'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('sent', 3)
                ->where('sent.0.day', '2026-11-15')
                ->where('sent.0.what', 'Chapter 1, Night bus, 2 notes')
                ->where('sent.0.sent', 40)
                ->where('sent.0.bounced', 2)
                ->where('sent.0.stopped', 1)
                ->where('sent.1.day', '2026-11-08')
                ->where('sent.1.what', '')
                ->where('sent.2.status', 'held'));
    }

    public function test_a_weekly_change_moves_the_upcoming_send_unless_she_moved_it_by_hand(): void
    {
        $this->asAdmin()->putJson($this->url('/newsletter/weekly'), ['day' => 4, 'time' => '18:30'])
            ->assertOk()
            ->assertJsonPath('weekly.day', 4)
            ->assertJsonPath('weekly.time', '18:30')
            ->assertJsonPath('next.at', '2026-11-26T18:30')
            ->assertJsonPath('next.moved', false);

        $this->asAdmin()->patchJson($this->url('/newsletter/next'), ['at' => ['day' => '2026-12-02', 'time' => '09:00']])
            ->assertOk()
            ->assertJsonPath('next.at', '2026-12-02T09:00')
            ->assertJsonPath('next.moved', true);

        $this->asAdmin()->putJson($this->url('/newsletter/weekly'), ['day' => 0, 'time' => '09:00'])
            ->assertOk()
            ->assertJsonPath('next.at', '2026-12-02T09:00')
            ->assertJsonPath('next.moved', true);

        $this->asAdmin()->putJson($this->url('/newsletter/weekly'), ['day' => 0, 'time' => '09:15'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['time']);
    }

    public function test_a_send_time_that_has_passed_is_refused_and_no_time_puts_the_send_back_on_the_weekly_slot(): void
    {
        $this->asAdmin()->patchJson($this->url('/newsletter/next'), ['at' => ['day' => '2026-11-26', 'time' => '09:00']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['day' => 'Pick a time that hasn’t passed.']);

        $this->asAdmin()->patchJson($this->url('/newsletter/next'), ['at' => ['day' => '2026-12-02', 'time' => '09:00']])
            ->assertOk()
            ->assertJsonPath('next.at', '2026-12-02T09:00')
            ->assertJsonPath('next.moved', true);

        $this->asAdmin()->patchJson($this->url('/newsletter/next'), ['at' => null])
            ->assertOk()
            ->assertJsonPath('next.at', '2026-11-29T09:00')
            ->assertJsonPath('next.moved', false);
    }

    public function test_holding_the_upcoming_send_marks_it_held_and_its_words_are_kept_as_typed(): void
    {
        $this->asAdmin()->patchJson($this->url('/newsletter/next'), ['held' => true, 'lines' => "Dear reader,\n\nThanks."])
            ->assertOk()
            ->assertJsonPath('next.held', true)
            ->assertJsonPath('next.lines', "Dear reader,\n\nThanks.");

        $this->assertSame(SendStatus::Held, NewsletterSend::query()->sole()->status);

        $this->asAdmin()->patchJson($this->url('/newsletter/next'), ['held' => false])
            ->assertOk()
            ->assertJsonPath('next.held', false)
            ->assertJsonPath('next.lines', "Dear reader,\n\nThanks.");
    }

    public function test_leaving_an_item_out_takes_its_readers_out_and_putting_it_back_restores_them(): void
    {
        $ember = $this->emberGate();
        $chapter = $this->outChapter($ember, 1, 'The chapter.', $this->at('2026-11-24 10:00'));
        $note = $this->note('The note.', $this->at('2026-11-25 12:00'));
        $this->subscriber('ada@example.com', ['novels'], $this->at('2026-11-20 10:00'));
        $this->subscriber('bisi@example.com', ['notes'], $this->at('2026-11-20 10:00'));

        $chapterKey = $this->keyOf($chapter);
        $noteKey = $this->keyOf($note);

        $this->asAdmin()->patchJson($this->url('/newsletter/next/items/'.$chapterKey), ['out' => true])
            ->assertOk()
            ->assertJsonPath('next.readers', 1)
            ->assertJsonPath('next.items.0.out', true);

        $this->asAdmin()->patchJson($this->url('/newsletter/next/items/'.$noteKey), ['out' => true])
            ->assertOk()
            ->assertJsonPath('next.readers', 0);

        $this->asAdmin()->patchJson($this->url('/newsletter/next/items/'.$chapterKey), ['out' => false])
            ->assertOk()
            ->assertJsonPath('next.readers', 1)
            ->assertJsonPath('next.items.0.out', false);

        $this->asAdmin()->patchJson($this->url('/newsletter/next/items/i999'), ['out' => true])->assertNotFound();

        $chapter->forceFill(['status' => PublishStatus::Draft, 'published_at' => null])->save();

        $this->asAdmin()->get($this->url('/newsletter'))
            ->assertInertia(fn (Assert $page) => $page->has('next.items', 1)->where('next.items.0.kind', 'notes'));
    }

    public function test_the_subscriber_list_masks_addresses_and_shows_fourteen_at_a_time(): void
    {
        for ($day = 1; $day <= 15; $day++) {
            $this->subscriber(sprintf('reader%02d@example.com', $day), ['novels', 'stories', 'poems', 'essays', 'notes'], $this->at(sprintf('2026-11-%02d 10:00', $day)));
        }

        $this->asAdmin()->getJson($this->url('/newsletter/subscribers'))
            ->assertOk()
            ->assertJsonCount(14, 'rows')
            ->assertJsonPath('total', 15)
            ->assertJsonPath('more', true)
            ->assertJsonPath('rows.0.email', 're•••@example.com')
            ->assertJsonPath('rows.0.kinds', 'Everything')
            ->assertJsonPath('rows.0.joined', '2026-11-15');

        $this->asAdmin()->getJson($this->url('/newsletter/subscribers?page=2'))
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('more', false);
    }

    public function test_the_kind_filter_lists_only_readers_who_chose_that_kind(): void
    {
        $this->subscriber('ada@example.com', ['novels'], $this->at('2026-11-20 10:00'));
        $this->subscriber('bisi@example.com', ['poems', 'notes'], $this->at('2026-11-21 10:00'));
        $this->subscriber('chidi@example.com', ['poems'], $this->at('2026-11-22 10:00'));

        $this->asAdmin()->getJson($this->url('/newsletter/subscribers?kind=poems'))
            ->assertOk()
            ->assertJsonCount(2, 'rows')
            ->assertJsonPath('total', 2)
            ->assertJsonPath('rows.0.kinds', 'Poetry')
            ->assertJsonPath('rows.1.kinds', 'Poetry, Notes');
    }

    public function test_a_whole_address_search_shows_the_full_address_and_nothing_for_part_of_one(): void
    {
        $this->subscriber('ada.bello@example.com', ['novels'], $this->at('2026-11-20 10:00'));
        $this->subscriber('newcomer@example.com', ['notes']);

        $this->asAdmin()->getJson($this->url('/newsletter/subscribers?q=Ada.Bello@Example.com'))
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.email', 'ada.bello@example.com')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('more', false);

        $this->asAdmin()->getJson($this->url('/newsletter/subscribers?q=ada.bel'))
            ->assertOk()
            ->assertJsonCount(0, 'rows')
            ->assertJsonPath('total', 0);

        $this->asAdmin()->getJson($this->url('/newsletter/subscribers?q=newcomer@example.com'))
            ->assertOk()
            ->assertJsonCount(0, 'rows');
    }

    public function test_the_unconfirmed_list_shows_only_addresses_still_to_confirm_masked(): void
    {
        $this->subscriber('ada@example.com', ['novels'], $this->at('2026-11-20 10:00'));
        $this->subscriber('newcomer@example.com', ['notes']);

        $this->asAdmin()->getJson($this->url('/newsletter/subscribers?unconfirmed=1'))
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.email', 'ne•••@example.com')
            ->assertJsonPath('total', 1);
    }

    public function test_removing_an_address_deletes_it_and_records_a_stop_against_the_last_send(): void
    {
        $sent = $this->send('2026-11-22 09:00', SendStatus::Sent, ['stopped_count' => 1]);
        $ada = $this->subscriber('ada@example.com', ['novels'], $this->at('2026-11-20 10:00'));

        $this->asAdmin()->deleteJson($this->url('/newsletter/subscribers/'.$ada->id))->assertNoContent();

        $this->assertModelMissing($ada);
        $this->assertDatabaseHas('newsletter_stops', ['newsletter_send_id' => $sent->id]);
        $this->assertSame(2, $sent->fresh()->stopped_count);

        $this->asAdmin()->get($this->url('/newsletter'))
            ->assertInertia(fn (Assert $page) => $page->where('subscribers.stoppedThisWeek', 1));
    }

    public function test_a_reader_stopping_from_their_emails_page_records_a_stop_against_the_last_send(): void
    {
        $sent = $this->send('2026-11-22 09:00', SendStatus::Sent);
        $ada = $this->subscriber('ada@example.com', ['novels'], $this->at('2026-11-20 10:00'));

        $this->delete('/emails/'.$ada->manage_token)->assertRedirect('/emails/off');

        $this->assertModelMissing($ada);
        $this->assertSame(1, NewsletterStop::query()->where('newsletter_send_id', $sent->id)->count());
        $this->assertSame(1, $sent->fresh()->stopped_count);
    }

    public function test_the_welcome_letter_takes_at_most_three_public_works(): void
    {
        $this->emberGate();
        $this->nightBus();
        Work::factory()->poem()->public()->published()->create(['title' => 'Harmattan', 'title_key' => 'harmattan', 'slug' => 'harmattan']);
        Work::factory()->essay()->public()->published()->create(['title' => 'Fourth', 'title_key' => 'fourth', 'slug' => 'fourth']);
        Work::factory()->novel()->create(['title' => 'Hidden', 'title_key' => 'hidden', 'slug' => 'hidden']);

        $this->asAdmin()->patchJson($this->url('/newsletter/welcome'), ['works' => ['the-ember-gate', 'night-bus', 'harmattan', 'fourth']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['works']);

        $this->asAdmin()->patchJson($this->url('/newsletter/welcome'), ['works' => ['the-ember-gate', 'hidden']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['works.1']);

        $this->asAdmin()->patchJson($this->url('/newsletter/welcome'), ['lines' => 'Hello there.', 'works' => ['harmattan', 'the-ember-gate']])
            ->assertOk()
            ->assertJsonPath('welcome.lines', 'Hello there.')
            ->assertJsonPath('welcome.works.0.slug', 'harmattan')
            ->assertJsonPath('welcome.works.0.kind', 'poem')
            ->assertJsonPath('welcome.works.1.slug', 'the-ember-gate');

        $this->asAdmin()->patchJson($this->url('/newsletter/welcome'), ['works' => ['night-bus']])
            ->assertOk()
            ->assertJsonPath('welcome.lines', 'Hello there.')
            ->assertJsonCount(1, 'welcome.works');
    }

    public function test_the_preview_draws_each_item_and_only_the_kind_asked_for(): void
    {
        $ember = $this->emberGate();
        $this->outChapter($ember, 1, self::LONG_CHAPTER, $this->at('2026-11-24 10:00'), ['title' => 'Morning, after']);
        $this->note('A short note about the lamps.', $this->at('2026-11-25 12:00'));

        $this->asAdmin()->get($this->url('/newsletter/preview?what=send'))
            ->assertOk()
            ->assertSee('Morning, after')
            ->assertSee('A novel · 1 min')
            ->assertSee('Read chapter 1')
            ->assertSee('Alpha bravo charlie')
            ->assertSee('Uniform victor whiskey')
            ->assertDontSee('Pebble quartz')
            ->assertSee('A short note about the lamps.')
            ->assertSee('Read on the Diary')
            ->assertSee('color:#A24F68', false);

        $this->asAdmin()->get($this->url('/newsletter/preview?what=send&kind=novels'))
            ->assertOk()
            ->assertSee('Morning, after')
            ->assertDontSee('A short note about the lamps.');

        $this->asAdmin()->get($this->url('/newsletter/preview?what=send&kind=notes'))
            ->assertOk()
            ->assertSee('A short note about the lamps.')
            ->assertDontSee('Morning, after');

        $this->asAdmin()->get($this->url('/newsletter/preview?what=send&dark=1'))
            ->assertOk()
            ->assertSee('color:#D495A8', false);
    }

    public function test_the_preview_says_nothing_is_left_for_a_kind_or_for_anyone(): void
    {
        $ember = $this->emberGate();
        $chapter = $this->outChapter($ember, 1, 'The chapter.', $this->at('2026-11-24 10:00'));

        $this->asAdmin()->get($this->url('/newsletter/preview?what=send&kind=poems'))
            ->assertOk()
            ->assertSee('Nothing for poetry readers this week. They get no email.');

        $this->asAdmin()->patchJson($this->url('/newsletter/next/items/'.$this->keyOf($chapter)), ['out' => true])->assertOk();

        $this->asAdmin()->get($this->url('/newsletter/preview?what=send'))
            ->assertOk()
            ->assertSee('Nothing for anyone this week. They get no email.');
    }

    public function test_the_welcome_preview_shows_her_letter_and_the_works_to_start_with(): void
    {
        $this->emberGate();
        $this->nightBus();

        $this->asAdmin()->get($this->url('/newsletter/preview?what=welcome'))
            ->assertOk()
            ->assertSee('Thank you for being here.')
            ->assertSee('To start with')
            ->assertSee('Night bus')
            ->assertSee('The Ember Gate');
    }

    public function test_a_readers_copy_has_only_the_items_for_their_kinds_and_their_own_links(): void
    {
        $ember = $this->emberGate();
        $this->outChapter($ember, 1, 'The chapter.', $this->at('2026-11-24 10:00'));
        $this->note('The note.', $this->at('2026-11-25 12:00'));
        $notesOnly = $this->subscriber('ada@example.com', ['notes'], $this->at('2026-11-20 10:00'));
        $poemsOnly = $this->subscriber('bisi@example.com', ['poems'], $this->at('2026-11-20 10:00'));

        $send = NewsletterPlan::next();
        $email = NewsletterEmail::send($send, $notesOnly);

        $this->assertCount(1, $email['items']);
        $this->assertSame('A note', $email['items'][0]['kind']);
        $this->assertSame('Notes', $email['kinds']);
        $this->assertStringEndsWith('/emails/'.$notesOnly->manage_token, $email['manage']);
        $this->assertSame($email['manage'], $email['stop']);
        $this->assertNull(NewsletterEmail::send($send, $poemsOnly));
    }

    public function test_the_test_send_mails_the_newsletter_and_the_welcome_to_the_author_and_refuses_an_empty_send(): void
    {
        Mail::fake();

        $this->asAdmin()->postJson($this->url('/newsletter/test'), ['what' => 'send'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['what']);

        $ember = $this->emberGate();
        $this->outChapter($ember, 1, 'The chapter.', $this->at('2026-11-24 10:00'));

        $this->asAdmin()->postJson($this->url('/newsletter/test'), ['what' => 'send'])->assertNoContent();

        Mail::assertQueued(Newsletter::class, 1);
        Mail::assertQueued(Newsletter::class, fn (Newsletter $mail): bool => $mail->hasTo('georgina@example.com'));

        $this->asAdmin()->postJson($this->url('/newsletter/test'), ['what' => 'welcome'])->assertNoContent();

        Mail::assertQueued(NewsletterWelcome::class, fn (NewsletterWelcome $mail): bool => $mail->hasTo('georgina@example.com'));
    }
}
