<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Follow;
use App\Models\Note;
use App\Models\Series;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use App\Notifications\CommentReply;
use App\Notifications\NewChapter;
use App\Notifications\NewNote;
use App\Notifications\NewWork;
use App\Support\Announcer;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AnnounceReleasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
    }

    private function announce(): int
    {
        return app(Announcer::class)->announce(now()->subHour(), now());
    }

    private function publishedNovel(string $title = 'The Ember Gate', ?Series $series = null): Work
    {
        return Work::factory()->novel()->public()->published()->create([
            'title' => $title,
            'series_id' => $series?->id,
            'series_position' => $series ? 1 : null,
        ]);
    }

    private function chapter(Work $work, int $position, CarbonInterface $publishedAt): WorkItem
    {
        return WorkItem::factory()->chapter()->published()->for($work)->create([
            'position' => $position,
            'published_at' => $publishedAt,
        ]);
    }

    private function follow(User $user, Work|Series $followable): void
    {
        Follow::factory()->for($user)->create([
            'followable_type' => $followable instanceof Series ? 'series' : 'work',
            'followable_id' => $followable->id,
        ]);
    }

    public function test_the_first_run_announces_nothing_and_starts_the_clock(): void
    {
        Notification::fake();
        $reader = User::factory()->create();
        $work = $this->publishedNovel();
        $this->follow($reader, $work);
        $this->chapter($work, 1, now()->subMinute());

        $this->artisan('eyiwetemi:announce')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame(now()->toIso8601String(), SiteSetting::get('announce.until'));
    }

    public function test_a_later_run_announces_what_came_out_since_the_last_run_and_stores_now(): void
    {
        Notification::fake([NewChapter::class]);
        SiteSetting::put('announce.until', now()->subHour()->toIso8601String());
        $reader = User::factory()->create();
        $work = $this->publishedNovel();
        $this->follow($reader, $work);
        $this->chapter($work, 1, now()->subDays(2));
        $chapter = $this->chapter($work, 2, now()->subMinutes(20));

        $this->artisan('eyiwetemi:announce')->assertSuccessful();

        Notification::assertSentTo($reader, NewChapter::class, fn (NewChapter $notification): bool => $notification->toArray($reader) === [
            'kind' => 'chapter',
            'work_item_id' => $chapter->id,
        ]);
        $this->assertSame(now()->toIso8601String(), SiteSetting::get('announce.until'));
    }

    public function test_a_new_chapter_reaches_each_follower_once_but_not_strangers_or_the_author(): void
    {
        Notification::fake([NewWork::class, NewChapter::class]);
        $series = Series::factory()->create();
        $work = $this->publishedNovel(series: $series);
        $this->chapter($work, 1, now()->subDays(2));
        $chapter = $this->chapter($work, 2, now()->subMinutes(20));
        $workFollower = User::factory()->create();
        $seriesFollower = User::factory()->create();
        $bothFollower = User::factory()->create();
        $stranger = User::factory()->create();
        $author = User::factory()->author()->create();
        $this->follow($workFollower, $work);
        $this->follow($seriesFollower, $series);
        $this->follow($bothFollower, $work);
        $this->follow($bothFollower, $series);
        $this->follow($author, $work);

        $this->assertSame(3, $this->announce());

        Notification::assertSentTo($workFollower, NewChapter::class, fn (NewChapter $notification): bool => $notification->workItemId === $chapter->id);
        Notification::assertSentTo($seriesFollower, NewChapter::class, fn (NewChapter $notification): bool => $notification->workItemId === $chapter->id);
        Notification::assertSentToTimes($bothFollower, NewChapter::class, 1);
        Notification::assertNotSentTo($stranger, NewChapter::class);
        Notification::assertNotSentTo($author, NewChapter::class);
        Notification::assertNotSentTo($workFollower, NewWork::class);
    }

    public function test_a_first_chapter_is_a_new_work_for_followers_and_for_readers_who_asked_for_new_work(): void
    {
        Notification::fake([NewWork::class, NewChapter::class]);
        $work = $this->publishedNovel();
        $this->chapter($work, 1, now()->subMinutes(20));
        $follower = User::factory()->create();
        $wantsNewWork = User::factory()->create(['preferences' => ['notifications' => ['new.site' => true]]]);
        $noSwitchSet = User::factory()->create();
        $this->follow($follower, $work);

        $this->announce();

        Notification::assertSentTo($follower, NewWork::class, fn (NewWork $notification): bool => $notification->preference === 'chapters'
            && $notification->toArray($follower) === ['kind' => 'work', 'work_id' => $work->id]);
        Notification::assertSentTo($wantsNewWork, NewWork::class, fn (NewWork $notification): bool => $notification->preference === 'new');
        Notification::assertNotSentTo($noSwitchSet, NewWork::class);
        Notification::assertNotSentTo($follower, NewChapter::class);
    }

    public function test_a_hidden_work_announces_nothing(): void
    {
        Notification::fake();
        $work = Work::factory()->novel()->reviewers()->published()->create();
        $this->chapter($work, 1, now()->subMinutes(20));
        $this->follow(User::factory()->create(), $work);

        $this->assertSame(0, $this->announce());

        Notification::assertNothingSent();
    }

    public function test_a_note_reaches_followers_only_when_it_asks_to_notify_them(): void
    {
        Notification::fake([NewNote::class]);
        $work = $this->publishedNovel();
        $follower = User::factory()->create();
        $this->follow($follower, $work);
        $notifying = Note::factory()->published()->create([
            'work_id' => $work->id,
            'notify_followers' => true,
            'published_at' => now()->subMinutes(10),
        ]);
        Note::factory()->published()->create([
            'work_id' => $work->id,
            'notify_followers' => false,
            'published_at' => now()->subMinutes(10),
        ]);

        $this->announce();

        Notification::assertSentTo($follower, NewNote::class, fn (NewNote $notification): bool => $notification->toArray($follower) === [
            'kind' => 'note',
            'note_id' => $notifying->id,
        ]);
        Notification::assertSentToTimes($follower, NewNote::class, 1);
    }

    public function test_a_reader_with_the_site_switch_off_gets_email_only(): void
    {
        $reader = User::factory()->create(['preferences' => ['notifications' => ['chapters.site' => false]]]);
        $work = $this->publishedNovel();
        $this->chapter($work, 1, now()->subDays(2));
        $chapter = $this->chapter($work, 2, now()->subMinutes(20));

        $this->assertSame(['mail'], (new NewChapter($chapter->id, 'chapters'))->via($reader));
    }

    public function test_chapters_reach_the_site_and_email_by_default(): void
    {
        $reader = User::factory()->create();
        $work = $this->publishedNovel();
        $this->chapter($work, 1, now()->subDays(2));
        $chapter = $this->chapter($work, 2, now()->subMinutes(20));

        $this->assertSame(['database', 'mail'], (new NewChapter($chapter->id, 'chapters'))->via($reader));
    }

    public function test_notes_reach_only_the_site_by_default(): void
    {
        $reader = User::factory()->create();
        $note = Note::factory()->published()->create(['work_id' => $this->publishedNovel()->id]);

        $this->assertSame(['database'], (new NewNote($note->id, 'notes'))->via($reader));
    }

    public function test_seeded_history_never_sends_mail(): void
    {
        $reader = User::factory()->create();
        $work = $this->publishedNovel();
        $this->chapter($work, 1, now()->subDays(2));
        $chapter = $this->chapter($work, 2, now()->subMinutes(20));

        $this->assertSame(['database'], (new NewChapter($chapter->id, 'chapters', false))->via($reader));
    }

    public function test_new_work_reaches_nobody_who_has_not_switched_it_on(): void
    {
        $reader = User::factory()->create();
        $work = $this->publishedNovel();

        $this->assertSame([], (new NewWork($work->id, 'new'))->via($reader));
    }

    public function test_a_reply_notifies_the_reader_whose_comment_it_answers(): void
    {
        Notification::fake([CommentReply::class]);
        $reader = User::factory()->create();
        $author = User::factory()->author()->create();
        $parent = Comment::factory()->create(['user_id' => $reader->id, 'work_id' => $this->publishedNovel()->id]);
        $reply = Comment::factory()->replyTo($parent)->create(['user_id' => $author->id]);

        app(Announcer::class)->reply($reply);

        Notification::assertSentTo($reader, CommentReply::class, fn (CommentReply $notification): bool => $notification->toArray($reader) === [
            'kind' => 'reply',
            'comment_id' => $reply->id,
        ]);
    }

    public function test_replying_to_yourself_notifies_nobody(): void
    {
        Notification::fake([CommentReply::class]);
        $reader = User::factory()->create();
        $parent = Comment::factory()->create(['user_id' => $reader->id, 'work_id' => $this->publishedNovel()->id]);
        $reply = Comment::factory()->replyTo($parent)->create(['user_id' => $reader->id]);

        app(Announcer::class)->reply($reply);

        Notification::assertNotSentTo($reader, CommentReply::class);
    }

    public function test_a_reply_to_the_author_never_notifies_the_author(): void
    {
        Notification::fake([CommentReply::class]);
        $author = User::factory()->author()->create();
        $reader = User::factory()->create();
        $parent = Comment::factory()->create(['user_id' => $author->id, 'work_id' => $this->publishedNovel()->id]);
        $reply = Comment::factory()->replyTo($parent)->create(['user_id' => $reader->id]);

        app(Announcer::class)->reply($reply);

        Notification::assertNotSentTo($author, CommentReply::class);
    }

    public function test_a_hidden_reply_notifies_nobody(): void
    {
        Notification::fake([CommentReply::class]);
        $reader = User::factory()->create();
        $author = User::factory()->author()->create();
        $parent = Comment::factory()->create(['user_id' => $reader->id, 'work_id' => $this->publishedNovel()->id]);
        $reply = Comment::factory()->replyTo($parent)->hidden()->create(['user_id' => $author->id]);

        app(Announcer::class)->reply($reply);

        Notification::assertNotSentTo($reader, CommentReply::class);
    }

    public function test_the_email_names_the_chapter_in_its_subject_and_body(): void
    {
        $reader = User::factory()->create();
        $work = $this->publishedNovel();
        $this->chapter($work, 1, now()->subDays(2));
        $chapter = $this->chapter($work, 2, now()->subMinutes(20));

        $message = (new NewChapter($chapter->id, 'chapters'))->toMail($reader);

        $this->assertSame('Chapter 2 of The Ember Gate is out', $message->subject);
        $this->assertStringContainsString('Chapter 2 of The Ember Gate is out', $message->render());
    }

    public function test_stored_data_holds_only_the_ids_the_notice_is_about(): void
    {
        $reader = User::factory()->make();

        $this->assertSame(['kind' => 'chapter', 'work_item_id' => 5], (new NewChapter(5, 'chapters'))->toArray($reader));
        $this->assertSame(['kind' => 'work', 'work_id' => 3], (new NewWork(3, 'new'))->toArray($reader));
        $this->assertSame(['kind' => 'note', 'note_id' => 7], (new NewNote(7, 'notes'))->toArray($reader));
        $this->assertSame(['kind' => 'reply', 'comment_id' => 9], (new CommentReply(9, 'replies'))->toArray($reader));
    }
}
