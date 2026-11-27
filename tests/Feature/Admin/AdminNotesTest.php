<?php

namespace Tests\Feature\Admin;

use App\Enums\PublishStatus;
use App\Models\Follow;
use App\Models\Like;
use App\Models\Media;
use App\Models\Note;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Work;
use App\Notifications\NewNote;
use App\Support\Announcer;
use App\Support\Prose;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminNotesTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->author()->create(['name' => 'Georgina Awani']);
        $this->reader = User::factory()->reader()->create(['name' => 'Ife Bello', 'username' => 'ife.bello']);
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
     * A note's document: one paragraph of plain text.
     *
     * @return array<string, mixed>
     */
    private function doc(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['id' => 'p1'], 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    /**
     * A note with words in its draft, and anything else it needs.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function draft(string $text, array $attributes = []): Note
    {
        return Note::factory()->create([
            'draft_body' => $this->doc($text),
            'draft_text' => $text,
            ...$attributes,
        ]);
    }

    /**
     * A note that is out, with its draft the same as what is out, published two days ago unless told otherwise.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function published(string $text, array $attributes = []): Note
    {
        return $this->draft($text, [
            'status' => PublishStatus::Published,
            'live_body' => $this->doc($text),
            'live_text' => $text,
            'published_at' => now()->subDays(2),
            'live_updated_at' => now()->subDays(2),
            ...$attributes,
        ]);
    }

    private function heart(Note $note, CarbonInterface $at): void
    {
        Like::factory()->create([
            'user_id' => User::factory()->reader()->create()->id,
            'likeable_type' => 'note',
            'likeable_id' => $note->id,
            'created_at' => $at,
        ]);
    }

    private function emberGate(): Work
    {
        return Work::factory()->novel()->public()->published()->create([
            'title' => 'The Ember Gate',
            'title_key' => 'the ember gate',
            'slug' => 'the-ember-gate',
        ]);
    }

    public function test_the_page_lists_every_note_with_its_state_hearts_and_works(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Africa/Lagos'));
        $work = $this->emberGate();
        Work::factory()->novel()->reviewers()->create(['title' => 'Harbour Songs', 'title_key' => 'harbour songs', 'slug' => 'harbour-songs']);
        Follow::factory()->for($this->reader)->create(['followable_type' => 'work', 'followable_id' => $work->id]);

        $draft = $this->draft('A draft about the lamps', ['draft_work_id' => $work->id, 'notify_followers' => false]);
        $scheduled = $this->draft('Chapter 8 is ready', [
            'status' => PublishStatus::Scheduled,
            'scheduled_at' => CarbonImmutable::parse('2026-11-27 18:00:00', 'Africa/Lagos')->utc(),
        ]);
        $live = $this->published('The lamps are on');
        $this->heart($live, now()->subDays(10));
        $this->heart($live, now()->subDay());
        $changed = $this->published('Old words');
        $changed->forceFill(['draft_body' => $this->doc('New words'), 'draft_text' => 'New words'])->save();

        $response = $this->asAdmin()->get($this->url('/notes?n='.$live->id.'&w=the-ember-gate'))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Notes')
                ->where('open', $live->id)
                ->where('w', 'the-ember-gate')
                ->where('now', '2026-11-26T10:00')
                ->has('notes', 4)
                ->where('works.0.slug', 'harbour-songs')
                ->where('works.0.public', false)
                ->where('works.0.followers', 0)
                ->where('works.1.slug', 'the-ember-gate')
                ->where('works.1.public', true)
                ->where('works.1.followers', 1));

        $notes = collect($response->viewData('page')['props']['notes'])->keyBy('id');

        $this->assertSame('draft', $notes[$draft->id]['state']);
        $this->assertSame('A draft about the lamps', $notes[$draft->id]['text']);
        $this->assertSame('the-ember-gate', $notes[$draft->id]['work']);
        $this->assertTrue($notes[$draft->id]['quiet']);
        $this->assertNull($notes[$draft->id]['day']);
        $this->assertSame('sch', $notes[$scheduled->id]['state']);
        $this->assertSame('2026-11-27T18:00', $notes[$scheduled->id]['at']);
        $this->assertSame('live', $notes[$live->id]['state']);
        $this->assertFalse($notes[$live->id]['changed']);
        $this->assertSame('2026-11-24', $notes[$live->id]['day']);
        $this->assertSame(2, $notes[$live->id]['likes']);
        $this->assertSame(1, $notes[$live->id]['newLikes']);
        $this->assertTrue($notes[$changed->id]['changed']);
    }

    public function test_guests_and_readers_cannot_reach_the_notes_or_change_a_note(): void
    {
        $note = $this->draft('Not for them');

        $this->get($this->url('/notes'))->assertRedirect(route('admin.signin'));

        $this->actingAs($this->reader)->withSession(['admin.verified' => true])
            ->patchJson($this->url('/notes/'.$note->id), ['quiet' => true])
            ->assertUnauthorized();

        $this->postJson($this->url('/notes'))->assertUnauthorized();

        $this->assertTrue($note->fresh()->notify_followers);
        $this->assertSame(1, Note::query()->count());
    }

    public function test_a_new_note_is_an_empty_draft_that_tells_followers(): void
    {
        $this->asAdmin()->postJson($this->url('/notes'))
            ->assertCreated()
            ->assertJsonPath('note.state', 'draft')
            ->assertJsonPath('note.text', '')
            ->assertJsonPath('note.quiet', false)
            ->assertJsonPath('note.body.content.0.type', 'paragraph');

        $note = Note::query()->sole();
        $this->assertSame(PublishStatus::Draft, $note->status);
        $this->assertTrue($note->notify_followers);
        $this->assertNull($note->published_at);
    }

    public function test_autosave_cleans_the_body_keeps_its_spaces_and_sets_the_work_and_quiet_setting(): void
    {
        $note = $this->draft('Old');
        $work = $this->emberGate();

        $this->asAdmin()->patchJson($this->url('/notes/'.$note->id), [
            'body' => ['type' => 'doc', 'content' => [
                ['type' => 'heading', 'attrs' => ['id' => 'h1', 'level' => 1], 'content' => [['type' => 'text', 'text' => 'Dropped heading']]],
                ['type' => 'paragraph', 'attrs' => ['id' => 'p1'], 'content' => [
                    ['type' => 'text', 'text' => 'Plain'],
                    ['type' => 'text', 'text' => ' ', 'marks' => [['type' => 'italic']]],
                    ['type' => 'text', 'text' => 'loud', 'marks' => [['type' => 'bold'], ['type' => 'underline']]],
                    ['type' => 'script', 'text' => 'dropped'],
                ]],
            ]],
            'work' => 'the-ember-gate',
            'quiet' => true,
        ])
            ->assertOk()
            ->assertJsonPath('note.quiet', true)
            ->assertJsonPath('note.work', 'the-ember-gate')
            ->assertJsonPath('note.text', 'Plain loud');

        $note->refresh();
        $this->assertEquals([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'attrs' => ['id' => 'p1'],
                'content' => [
                    ['type' => 'text', 'text' => 'Plain'],
                    ['type' => 'text', 'text' => ' ', 'marks' => [['type' => 'italic']]],
                    ['type' => 'text', 'text' => 'loud', 'marks' => [['type' => 'bold']]],
                ],
            ]],
        ], $note->draft_body);
        $this->assertSame('Plain loud', $note->draft_text);
        $this->assertSame($work->id, $note->draft_work_id);
        $this->assertFalse($note->notify_followers);
        $this->assertNull($note->live_body);
    }

    public function test_an_unknown_work_is_refused_and_nothing_is_saved(): void
    {
        $note = $this->draft('Old');

        $this->asAdmin()->patchJson($this->url('/notes/'.$note->id), ['work' => 'no-such-work', 'body' => $this->doc('New')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work']);

        $note->refresh();
        $this->assertNull($note->draft_work_id);
        $this->assertSame('Old', $note->draft_text);
    }

    public function test_publishing_a_draft_puts_its_words_and_work_out_and_stamps_the_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Africa/Lagos'));
        $work = $this->emberGate();
        $note = $this->draft('The lamps are on', ['draft_work_id' => $work->id]);

        $this->asAdmin()->postJson($this->url('/notes/'.$note->id.'/publish'))
            ->assertOk()
            ->assertJsonPath('note.state', 'live')
            ->assertJsonPath('note.justNow', true)
            ->assertJsonPath('note.changed', false);

        $note->refresh();
        $this->assertSame(PublishStatus::Published, $note->status);
        $this->assertEquals($note->draft_body, $note->live_body);
        $this->assertSame('The lamps are on', $note->live_text);
        $this->assertSame($work->id, $note->work_id);
        $this->assertTrue($note->published_at->equalTo(now()));
        $this->assertTrue($note->live_updated_at->equalTo(now()));
        $this->assertNull($note->scheduled_at);
    }

    public function test_publishing_a_note_with_no_words_and_no_picture_is_refused(): void
    {
        $note = $this->draft('', ['draft_body' => Prose::empty()]);

        $this->asAdmin()->postJson($this->url('/notes/'.$note->id.'/publish'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);

        $note->refresh();
        $this->assertSame(PublishStatus::Draft, $note->status);
        $this->assertNull($note->live_body);
    }

    public function test_updating_a_published_note_sends_the_draft_out_again_and_keeps_its_first_publish_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Africa/Lagos'));
        $note = $this->draft('First words');
        $this->asAdmin()->postJson($this->url('/notes/'.$note->id.'/publish'))->assertOk();

        $this->travel(1)->hours();

        $this->asAdmin()->patchJson($this->url('/notes/'.$note->id), ['body' => $this->doc('Better words')])
            ->assertJsonPath('note.changed', true);

        $this->asAdmin()->postJson($this->url('/notes/'.$note->id.'/publish'))
            ->assertOk()
            ->assertJsonPath('note.changed', false)
            ->assertJsonPath('note.justNow', false);

        $note->refresh();
        $this->assertSame('Better words', $note->live_text);
        $this->assertTrue($note->published_at->equalTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Africa/Lagos')));
        $this->assertTrue($note->live_updated_at->equalTo(now()));
    }

    public function test_a_note_is_scheduled_for_a_wat_time_and_stored_in_utc(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Africa/Lagos'));
        $note = $this->draft('Chapter 8 is ready');

        $this->asAdmin()->postJson($this->url('/notes/'.$note->id.'/schedule'), ['day' => '2026-11-27', 'time' => '18:00'])
            ->assertOk()
            ->assertJsonPath('note.state', 'sch')
            ->assertJsonPath('note.at', '2026-11-27T18:00');

        $note->refresh();
        $this->assertSame(PublishStatus::Scheduled, $note->status);
        $this->assertSame('2026-11-27 17:00:00', $note->scheduled_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($note->published_at);
    }

    public function test_a_time_that_has_already_passed_cannot_schedule_a_note(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Africa/Lagos'));
        $note = $this->draft('Chapter 8 is ready');

        $this->asAdmin()->postJson($this->url('/notes/'.$note->id.'/schedule'), ['day' => '2026-11-26', 'time' => '09:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['day']);

        $this->assertSame(PublishStatus::Draft, $note->fresh()->status);
    }

    public function test_a_note_that_is_out_cannot_be_scheduled(): void
    {
        $note = $this->published('Already out');

        $this->asAdmin()->postJson($this->url('/notes/'.$note->id.'/schedule'), ['day' => '2030-01-01', 'time' => '09:00'])
            ->assertUnprocessable();

        $this->assertSame(PublishStatus::Published, $note->fresh()->status);
    }

    public function test_unscheduling_returns_a_scheduled_note_to_a_draft(): void
    {
        $note = $this->draft('Chapter 8 is ready', [
            'status' => PublishStatus::Scheduled,
            'scheduled_at' => now()->addDays(3),
        ]);

        $this->asAdmin()->deleteJson($this->url('/notes/'.$note->id.'/schedule'))
            ->assertOk()
            ->assertJsonPath('note.state', 'draft');

        $note->refresh();
        $this->assertSame(PublishStatus::Draft, $note->status);
        $this->assertNull($note->scheduled_at);
    }

    public function test_the_publish_due_command_sends_a_due_note_out_at_now_and_leaves_a_later_one_scheduled(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-26 18:00:00', 'Africa/Lagos'));
        $work = $this->emberGate();
        Follow::factory()->for($this->reader)->create(['followable_type' => 'work', 'followable_id' => $work->id]);
        Notification::fake([NewNote::class]);

        $due = $this->draft('The lamps are on', [
            'status' => PublishStatus::Scheduled,
            'scheduled_at' => CarbonImmutable::parse('2026-11-26 17:59:00', 'Africa/Lagos')->utc(),
            'draft_work_id' => $work->id,
        ]);
        $later = $this->draft('Tomorrow', [
            'status' => PublishStatus::Scheduled,
            'scheduled_at' => CarbonImmutable::parse('2026-11-27 18:00:00', 'Africa/Lagos')->utc(),
        ]);

        $this->artisan('eyiwetemi:publish-due')
            ->expectsOutput('Published 1.')
            ->assertSuccessful();

        $due->refresh();
        $this->assertSame(PublishStatus::Published, $due->status);
        $this->assertSame('The lamps are on', $due->live_text);
        $this->assertTrue($due->published_at->equalTo(now()));
        $this->assertSame(PublishStatus::Scheduled, $later->fresh()->status);
        $this->assertNull($later->fresh()->live_body);

        app(Announcer::class)->announce(now()->subHour(), now());

        Notification::assertSentTo($this->reader, NewNote::class, fn (NewNote $notification): bool => $notification->noteId === $due->id);
    }

    public function test_a_due_note_emptied_since_it_was_scheduled_goes_back_to_a_draft_instead_of_going_out_blank(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-26 18:00:00', 'Africa/Lagos'));
        $note = $this->draft('', [
            'draft_body' => Prose::empty(),
            'status' => PublishStatus::Scheduled,
            'scheduled_at' => CarbonImmutable::parse('2026-11-26 17:59:00', 'Africa/Lagos')->utc(),
        ]);

        $this->artisan('eyiwetemi:publish-due')->assertSuccessful();

        $note->refresh();
        $this->assertSame(PublishStatus::Draft, $note->status);
        $this->assertNull($note->scheduled_at);
        $this->assertNull($note->live_body);
    }

    public function test_undo_within_two_minutes_takes_a_note_back_to_a_draft_and_removes_its_new_note_notices(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Africa/Lagos'));
        $work = $this->emberGate();
        $note = $this->draft('The lamps are on', ['draft_work_id' => $work->id]);
        $other = $this->published('Another note');
        $this->asAdmin()->postJson($this->url('/notes/'.$note->id.'/publish'))->assertOk();

        $this->reader->notifications()->create(['id' => Str::uuid()->toString(), 'type' => NewNote::class, 'data' => ['kind' => 'note', 'note_id' => $note->id]]);
        $this->reader->notifications()->create(['id' => Str::uuid()->toString(), 'type' => NewNote::class, 'data' => ['kind' => 'note', 'note_id' => $other->id]]);

        $this->asAdmin()->postJson($this->url('/notes/'.$note->id.'/undo'))
            ->assertOk()
            ->assertJsonPath('note.state', 'draft')
            ->assertJsonPath('note.justNow', false);

        $note->refresh();
        $this->assertSame(PublishStatus::Draft, $note->status);
        $this->assertNull($note->live_body);
        $this->assertNull($note->work_id);
        $this->assertNull($note->published_at);
        $this->assertSame('The lamps are on', $note->draft_text);

        $this->assertCount(1, $this->reader->notifications()->get());
        $this->assertSame($other->id, $this->reader->notifications()->sole()->data['note_id']);
    }

    public function test_undo_after_two_minutes_is_refused_and_the_note_stays_out(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Africa/Lagos'));
        $note = $this->draft('The lamps are on');
        $this->asAdmin()->postJson($this->url('/notes/'.$note->id.'/publish'))->assertOk();
        $this->reader->notifications()->create(['id' => Str::uuid()->toString(), 'type' => NewNote::class, 'data' => ['kind' => 'note', 'note_id' => $note->id]]);

        $this->travel(3)->minutes();

        $this->asAdmin()->postJson($this->url('/notes/'.$note->id.'/undo'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['undo']);

        $this->assertSame(PublishStatus::Published, $note->fresh()->status);
        $this->assertCount(1, $this->reader->notifications()->get());
    }

    public function test_deleting_a_note_takes_its_hearts_with_it_and_leaves_the_others(): void
    {
        $note = $this->published('Going');
        $keep = $this->published('Staying');
        $this->heart($note, now());
        $this->heart($keep, now());

        $this->asAdmin()->deleteJson($this->url('/notes/'.$note->id))->assertNoContent();

        $this->assertModelMissing($note);
        $this->assertDatabaseMissing('likes', ['likeable_type' => 'note', 'likeable_id' => $note->id]);
        $this->assertDatabaseHas('likes', ['likeable_type' => 'note', 'likeable_id' => $keep->id]);
    }

    public function test_marking_the_hearts_seen_restarts_the_count_of_new_ones(): void
    {
        $note = $this->published('The lamps are on');
        $this->heart($note, now()->subDay());

        $this->asAdmin()->postJson($this->url('/notes/seen'))->assertNoContent();

        $this->assertNotNull(SiteSetting::get('desk.hearts_seen_at'));
        $response = $this->asAdmin()->get($this->url('/notes'))->assertOk();
        $notes = collect($response->viewData('page')['props']['notes'])->keyBy('id');
        $this->assertSame(1, $notes[$note->id]['likes']);
        $this->assertSame(0, $notes[$note->id]['newLikes']);
    }

    public function test_a_picture_is_stored_as_the_drafts_picture_and_its_side_is_kept_to_1600(): void
    {
        Storage::fake(config('eyiwetemi.media_disk'));
        $note = $this->draft('A picture today');

        $response = $this->asAdmin()->postJson($this->url('/notes/'.$note->id.'/photo'), [
            'image' => UploadedFile::fake()->image('harbour.jpg', 3000, 2000),
        ])->assertCreated();

        $media = Media::query()->sole();
        $response->assertJsonPath('note.photo.id', $media->id);
        $this->assertSame($media->id, $note->fresh()->draft_media_id);
        $this->assertNull($note->fresh()->live_body);

        $this->assertStringStartsWith('images/', $media->path);
        $this->assertStringEndsWith('.webp', $media->path);
        $this->assertSame(1600, $media->width);
    }

    public function test_a_file_that_is_not_a_picture_is_refused_with_the_message_the_writing_screen_gives(): void
    {
        $note = $this->draft('A picture today');

        $this->asAdmin()->postJson($this->url('/notes/'.$note->id.'/photo'), [
            'image' => UploadedFile::fake()->create('notes.txt', 4, 'text/plain'),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.image.0', 'That isn’t a picture we can use. Try a JPG, PNG or WebP.');

        $this->assertNull($note->fresh()->draft_media_id);
    }
}
