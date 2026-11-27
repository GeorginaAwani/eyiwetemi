<?php

namespace Tests\Feature;

use App\Enums\CommentKind;
use App\Mail\CommentsDigest;
use App\Mail\ReviewerNotes;
use App\Models\Comment;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CommentEmailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
    }

    private function author(): User
    {
        return User::factory()->author()->create(['email' => 'ada@example.com']);
    }

    private function novel(): Work
    {
        return Work::factory()->novel()->public()->published()->create(['title' => 'The Ember Gate']);
    }

    /** @return array{0: WorkItem, 1: WorkItem} The novel's first and second chapters. */
    private function twoChapters(Work $novel): array
    {
        return [
            WorkItem::factory()->chapter()->published()->for($novel)->create(['position' => 1]),
            WorkItem::factory()->chapter()->published()->for($novel)->create(['position' => 2]),
        ];
    }

    /** A reader's comment on a work, from half an hour ago unless told otherwise. */
    private function readerComment(array $attributes = []): Comment
    {
        return Comment::factory()->create(array_merge([
            'user_id' => User::factory()->reader(),
            'created_at' => now()->subMinutes(30),
        ], $attributes));
    }

    /** A reviewer's note on a chapter, from half an hour ago unless told otherwise. */
    private function reviewerNote(User $reviewer, WorkItem $chapter, array $attributes = []): Comment
    {
        return Comment::factory()->review()->create(array_merge([
            'user_id' => $reviewer->id,
            'work_id' => $chapter->work_id,
            'work_item_id' => $chapter->id,
            'body' => 'Too many adjectives',
            'created_at' => now()->subMinutes(30),
        ], $attributes));
    }

    /** The plain-text part of a queued mail, rendered the way it goes out. */
    private function plainText(Mailable $mail): string
    {
        return view($mail->content()->text, $mail->buildViewData())->render();
    }

    public function test_the_digest_mails_the_writer_the_new_reader_comments_by_place_and_moves_the_clock(): void
    {
        Mail::fake();
        $author = $this->author();
        $ada = User::factory()->reader()->create(['username' => 'ada_reads']);
        $tomi = User::factory()->reader()->create(['username' => 'tomi.reader']);
        $novel = $this->novel();
        [, $chapterTwo] = $this->twoChapters($novel);
        $salt = Work::factory()->story()->public()->published()->create(['title' => 'Salt']);
        SiteSetting::put('mail.readers_digest_at', now()->subHours(3)->toIso8601String());

        Comment::factory()->create([
            'user_id' => $ada->id,
            'work_id' => $novel->id,
            'work_item_id' => $chapterTwo->id,
            'body' => 'Before the last digest',
            'created_at' => now()->subHours(4),
        ]);
        $first = $this->readerComment(['user_id' => $ada->id, 'work_id' => $novel->id, 'work_item_id' => $chapterTwo->id, 'body' => 'The tide came in slowly', 'created_at' => now()->subMinutes(30)]);
        Comment::factory()->replyTo($first)->create(['user_id' => $tomi->id, 'body' => 'Good', 'created_at' => now()->subMinutes(20)]);
        $this->readerComment(['user_id' => null, 'work_id' => $salt->id, 'body' => 'Lovely', 'created_at' => now()->subMinutes(10)]);

        $this->artisan('eyiwetemi:comments-digest')->assertSuccessful();

        Mail::assertQueued(CommentsDigest::class, 1);
        Mail::assertQueued(CommentsDigest::class, function (CommentsDigest $mail) use ($author): bool {
            return $mail->hasTo($author->email)
                && $mail->envelope()->subject === '3 new comments'
                && $mail->count === 3
                && $mail->more === 0
                && $mail->places === [
                    ['title' => 'Salt', 'lines' => ['Someone: Lovely']],
                    ['title' => 'The Ember Gate · Chapter 2', 'lines' => ['tomi.reader: Good', 'ada_reads: The tide came in slowly']],
                ];
        });

        $html = Mail::queued(CommentsDigest::class)->first()->render();
        $this->assertStringContainsString('The Ember Gate · Chapter 2', $html);
        $this->assertStringContainsString('tomi.reader: Good', $html);
        $this->assertStringContainsString('href="https://'.config('eyiwetemi.admin_domain').'/comments"', $html);
        $this->assertStringContainsString('Open comments:', $this->plainText(Mail::queued(CommentsDigest::class)->first()));

        $this->assertSame(now()->toIso8601String(), SiteSetting::get('mail.readers_digest_at'));
    }

    public function test_nothing_new_sends_nothing_and_still_moves_the_clock(): void
    {
        Mail::fake();
        $this->author();
        SiteSetting::put('mail.readers_digest_at', now()->subHours(3)->toIso8601String());
        $this->readerComment(['created_at' => now()->subHours(4)]);

        $this->artisan('eyiwetemi:comments-digest')->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertSame(now()->toIso8601String(), SiteSetting::get('mail.readers_digest_at'));
    }

    public function test_switching_the_daily_mail_off_sends_nothing_and_keeps_the_clock(): void
    {
        Mail::fake();
        $this->author();
        SiteSetting::put('mail.readers_daily', false);
        $this->readerComment();

        $this->artisan('eyiwetemi:comments-digest')->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertNull(SiteSetting::get('mail.readers_digest_at'));
    }

    public function test_comments_already_seen_are_left_out_of_the_digest(): void
    {
        Mail::fake();
        $author = $this->author();
        SiteSetting::put('mail.readers_digest_at', now()->subHours(3)->toIso8601String());
        $this->readerComment(['body' => 'Seen already', 'seen_at' => now()]);
        $this->readerComment(['body' => 'Not seen yet']);

        $this->artisan('eyiwetemi:comments-digest')->assertSuccessful();

        Mail::assertQueued(CommentsDigest::class, fn (CommentsDigest $mail): bool => $mail->hasTo($author->email)
            && $mail->count === 1
            && $mail->envelope()->subject === '1 new comment');
    }

    public function test_at_most_thirty_comments_are_listed_and_the_rest_are_counted(): void
    {
        Mail::fake();
        $this->author();
        $novel = $this->novel();
        SiteSetting::put('mail.readers_digest_at', now()->subHours(3)->toIso8601String());

        foreach (range(1, 31) as $minutesAgo) {
            $this->readerComment(['work_id' => $novel->id, 'created_at' => now()->subMinutes($minutesAgo)]);
        }

        $this->artisan('eyiwetemi:comments-digest')->assertSuccessful();

        Mail::assertQueued(CommentsDigest::class, fn (CommentsDigest $mail): bool => $mail->count === 31
            && $mail->more === 1
            && count($mail->places[0]['lines']) === 30);
    }

    public function test_the_digest_is_scheduled_at_eight_in_the_evening_lagos_time(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command, 'eyiwetemi:comments-digest'));

        $this->assertNotNull($event);
        $this->assertSame('0 20 * * *', $event->expression);
        $this->assertSame('Africa/Lagos', $event->timezone);
    }

    public function test_a_reviewer_still_writing_is_waited_for_then_mailed_once(): void
    {
        Mail::fake();
        $author = $this->author();
        $tunde = User::factory()->reviewer()->create(['name' => 'Tunde Ola']);
        [$chapterOne] = $this->twoChapters($this->novel());
        $this->reviewerNote($tunde, $chapterOne, ['body' => 'Too many adjectives', 'created_at' => now()->subMinutes(30)]);
        $newest = $this->reviewerNote($tunde, $chapterOne, ['body' => 'And here too', 'created_at' => now()->subMinutes(5)]);

        $this->artisan('eyiwetemi:review-mail')->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertNull(SiteSetting::get('mail.reviewer_sent'));

        $this->travel(15)->minutes();
        $this->artisan('eyiwetemi:review-mail')->assertSuccessful();
        $this->artisan('eyiwetemi:review-mail')->assertSuccessful();

        Mail::assertQueued(ReviewerNotes::class, 1);
        Mail::assertQueued(ReviewerNotes::class, fn (ReviewerNotes $mail): bool => $mail->hasTo($author->email)
            && $mail->envelope()->subject === 'Tunde left 2 notes on The Ember Gate');
        $this->assertSame($newest->created_at->toIso8601String(), SiteSetting::get('mail.reviewer_sent')[$tunde->id]);
    }

    public function test_each_note_shows_its_quote_its_suggested_edit_and_its_reply(): void
    {
        Mail::fake();
        $this->author();
        $tunde = User::factory()->reviewer()->create(['name' => 'Tunde Ola']);
        [$chapterOne] = $this->twoChapters($this->novel());
        $note = $this->reviewerNote($tunde, $chapterOne, ['body' => 'Cut this', 'anchor' => ['quote' => 'the gulls came back'], 'created_at' => now()->subHour()]);
        $this->reviewerNote($tunde, $chapterOne, ['kind' => CommentKind::Edit, 'body' => null, 'suggestion' => ['deleted' => 'very', 'inserted' => 'quietly'], 'created_at' => now()->subHour()]);
        Comment::factory()->replyTo($note)->create(['user_id' => $tunde->id, 'body' => 'Agreed', 'created_at' => now()->subHour()]);

        $this->artisan('eyiwetemi:review-mail')->assertSuccessful();

        $html = Mail::queued(ReviewerNotes::class)->first()->render();
        $this->assertStringContainsString('The Ember Gate · Chapter 1', $html);
        $this->assertStringContainsString('“the gulls came back”', $html);
        $this->assertStringContainsString('Cut this', $html);
        $this->assertStringContainsString('Suggested: very → quietly', $html);
        $this->assertStringContainsString('Reply: Agreed', $html);
        $this->assertStringContainsString('who=Tunde%20Ola', $html);

        $text = $this->plainText(Mail::queued(ReviewerNotes::class)->first());
        $this->assertStringContainsString('“the gulls came back”', $text);
        $this->assertStringContainsString('Suggested: very → quietly', $text);
        $this->assertStringContainsString('Reply: Agreed', $text);
    }

    public function test_switching_the_reviewer_mail_off_sends_nothing(): void
    {
        Mail::fake();
        $this->author();
        SiteSetting::put('mail.reviewers_now', false);
        $tunde = User::factory()->reviewer()->create();
        [$chapterOne] = $this->twoChapters($this->novel());
        $this->reviewerNote($tunde, $chapterOne, ['created_at' => now()->subHour()]);

        $this->artisan('eyiwetemi:review-mail')->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertNull(SiteSetting::get('mail.reviewer_sent'));
    }

    public function test_notes_already_seen_are_left_out_of_the_reviewer_mail(): void
    {
        Mail::fake();
        $this->author();
        $tunde = User::factory()->reviewer()->create(['name' => 'Tunde Ola']);
        [$chapterOne] = $this->twoChapters($this->novel());
        $this->reviewerNote($tunde, $chapterOne, ['seen_at' => now(), 'created_at' => now()->subHour()]);
        $this->reviewerNote($tunde, $chapterOne, ['created_at' => now()->subHour()]);

        $this->artisan('eyiwetemi:review-mail')->assertSuccessful();

        Mail::assertQueued(ReviewerNotes::class, fn (ReviewerNotes $mail): bool => $mail->count === 1
            && $mail->envelope()->subject === 'Tunde left a note on The Ember Gate');
    }

    public function test_two_reviewers_are_mailed_separately_each_on_their_own_clock(): void
    {
        Mail::fake();
        $this->author();
        $tunde = User::factory()->reviewer()->create(['name' => 'Tunde Ola']);
        $ife = User::factory()->reviewer()->create(['name' => 'Ife Bello']);
        [$chapterOne] = $this->twoChapters($this->novel());
        $this->reviewerNote($tunde, $chapterOne, ['created_at' => now()->subMinutes(30)]);
        $this->reviewerNote($ife, $chapterOne, ['created_at' => now()->subMinutes(5)]);

        $this->artisan('eyiwetemi:review-mail')->assertSuccessful();

        Mail::assertQueued(ReviewerNotes::class, 1);
        Mail::assertQueued(ReviewerNotes::class, fn (ReviewerNotes $mail): bool => $mail->firstName === 'Tunde');

        $this->travel(15)->minutes();
        $this->artisan('eyiwetemi:review-mail')->assertSuccessful();

        Mail::assertQueued(ReviewerNotes::class, 2);
        Mail::assertQueued(ReviewerNotes::class, fn (ReviewerNotes $mail): bool => $mail->firstName === 'Ife');
        $this->assertEqualsCanonicalizing([$tunde->id, $ife->id], array_keys(SiteSetting::get('mail.reviewer_sent')));
    }
}
