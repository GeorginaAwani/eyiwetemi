<?php

namespace Tests\Feature\Admin;

use App\Enums\CollectionKind;
use App\Enums\CommentAudience;
use App\Enums\CommentKind;
use App\Enums\CommentStatus;
use App\Enums\PublishStatus;
use App\Enums\StatSubject;
use App\Enums\WorkItemType;
use App\Models\Comment;
use App\Models\DailyStat;
use App\Models\Like;
use App\Models\LinkCheck;
use App\Models\Note;
use App\Models\Notice;
use App\Models\SitePage;
use App\Models\SiteSetting;
use App\Models\Subscriber;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use App\Models\WritingDay;
use App\Support\DeskPage;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeskPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo($this->wat('2026-11-26 10:00'));
    }

    /**
     * A West Africa Time wall clock as the instant the database stores (UTC). Eloquent writes a zoned Carbon's own clock,
     * so a value written in WAT must be converted first.
     */
    private function wat(string $local): Carbon
    {
        return Carbon::parse($local, 'Africa/Lagos')->utc();
    }

    /**
     * A chapter (or piece) that went out a while ago, with its draft saved before it went live.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function publishedItem(Work $work, int $position, array $attributes = []): WorkItem
    {
        return WorkItem::factory()->for($work)->create([
            'position' => $position,
            'status' => PublishStatus::Published,
            'live_words' => 1200,
            'published_at' => now()->subWeeks(2),
            'live_updated_at' => now()->subWeek(),
            'draft_saved_at' => now()->subWeeks(3),
            ...$attributes,
        ]);
    }

    /**
     * A draft that has not gone out, last saved two hours ago unless told otherwise.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function draftItem(Work $work, int $position, array $attributes = []): WorkItem
    {
        return WorkItem::factory()->for($work)->create([
            'position' => $position,
            'status' => PublishStatus::Draft,
            'draft_words' => 1840,
            'draft_saved_at' => now()->subHours(2),
            ...$attributes,
        ]);
    }

    private function stat(string $day, StatSubject $subject, int $reads, ?Work $work = null): void
    {
        DailyStat::factory()->create([
            'day' => $day,
            'subject' => $subject,
            'subject_id' => $work?->id ?? 0,
            'work_id' => $work?->id,
            'reads' => $reads,
            'readers' => 0,
            'finished' => 0,
        ]);
    }

    private function like(Note $note, CarbonInterface $at): void
    {
        Like::factory()->create(['likeable_type' => 'note', 'likeable_id' => $note->id, 'created_at' => $at]);
    }

    public function test_an_empty_desk_has_no_lead_and_zero_counts(): void
    {
        $props = DeskPage::props();

        $this->assertNull($props['lead']);
        $this->assertSame(['count' => 0, 'rows' => []], $props['comments']);
        $this->assertSame(['count' => 0, 'rows' => []], $props['reviewers']);
        $this->assertSame(['count' => 0, 'rows' => []], $props['scheduled']);
        $this->assertSame(['rows' => [], 'hearts' => 0], $props['notes']);
        $this->assertSame([], $props['site']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $props['week']['spark']);
        $this->assertNull($props['week']['top']);
    }

    public function test_the_lead_is_the_draft_saved_last_and_skips_published_chapters_with_no_newer_edits(): void
    {
        $older = Work::factory()->novel()->public()->create(['title' => 'Salt Season']);
        $this->draftItem($older, 1, ['title' => 'Old draft', 'draft_saved_at' => now()->subHours(3)]);

        $novel = Work::factory()->novel()->public()->create(['title' => 'The Ember Gate']);
        $draft = $this->draftItem($novel, 1, ['title' => 'The gate in the mud', 'draft_saved_at' => now()->subHour()]);

        // Went out after its last save, so nothing is waiting, even though it was saved most recently.
        $this->publishedItem($novel, 2, [
            'title' => 'Already out',
            'draft_saved_at' => now()->subMinutes(5),
            'live_updated_at' => now()->subMinute(),
        ]);

        // Never saved as a draft: left out.
        WorkItem::factory()->for($novel)->create(['position' => 3, 'status' => PublishStatus::Draft, 'draft_saved_at' => null]);

        $lead = DeskPage::props()['lead'];

        $this->assertSame('The gate in the mud', $lead['title']);
        $this->assertSame('/works/'.$novel->slug.'/write/'.$draft->id, $lead['href']);
    }

    public function test_a_published_chapter_with_newer_edits_reads_as_edits_not_out(): void
    {
        $novel = Work::factory()->novel()->public()->create(['title' => 'The Ember Gate']);
        $this->draftItem($novel, 1, [
            'title' => 'The gate in the mud',
            'status' => PublishStatus::Published,
            'live_words' => 1200,
            'published_at' => now()->subWeek(),
            'live_updated_at' => now()->subDay(),
            'draft_saved_at' => now()->subHours(2),
        ]);

        $this->assertSame('Edits not out · 1,840 words · 2 h ago', DeskPage::props()['lead']['meta']);
    }

    public function test_a_chapter_reads_as_its_number_in_words_and_parts_are_not_counted(): void
    {
        $novel = Work::factory()->novel()->public()->create(['title' => 'The Ember Gate']);
        WorkItem::factory()->part()->for($novel)->create(['position' => 1, 'draft_saved_at' => null]);

        for ($position = 2; $position <= 8; $position++) {
            $this->publishedItem($novel, $position);
        }

        $this->draftItem($novel, 9, ['title' => 'The gate in the mud']);

        $this->assertSame('The Ember Gate, chapter eight', DeskPage::props()['lead']['kick']);
    }

    public function test_a_part_reads_as_part_and_its_number_in_words(): void
    {
        $novel = Work::factory()->novel()->public()->create(['title' => 'The Ember Gate']);
        WorkItem::factory()->part()->for($novel)->create([
            'position' => 1,
            'title' => 'Part one',
            'status' => PublishStatus::Draft,
            'draft_saved_at' => now()->subHour(),
        ]);

        $this->assertSame('The Ember Gate, part one', DeskPage::props()['lead']['kick']);
    }

    public function test_a_collection_piece_reads_as_its_collection_and_number_and_takes_the_piece_title(): void
    {
        $collection = Work::factory()->collection(CollectionKind::Poem)->public()->create(['title' => 'Midnight Poems']);
        $poem = Work::factory()->poem()->public()->create(['title' => 'Harmattan']);
        $this->publishedItem($collection, 1, ['type' => WorkItemType::Piece, 'piece_work_id' => null]);
        $this->draftItem($collection, 2, ['type' => WorkItemType::Piece, 'piece_work_id' => $poem->id, 'title' => null]);

        $lead = DeskPage::props()['lead'];

        $this->assertSame('Midnight Poems, poem 2', $lead['kick']);
        $this->assertSame('Harmattan', $lead['title']);
        $this->assertSame($collection->cover_color, $lead['cover']['color']);
    }

    public function test_a_single_poem_reads_as_its_kind_with_its_own_title_and_minutes_ago(): void
    {
        $poem = Work::factory()->poem()->public()->create(['title' => 'Harmattan']);
        $this->draftItem($poem, 1, ['title' => null, 'draft_words' => 120, 'draft_saved_at' => now()->subMinutes(20)]);

        $lead = DeskPage::props()['lead'];

        $this->assertSame('A poem', $lead['kick']);
        $this->assertSame('Harmattan', $lead['title']);
        $this->assertSame('Draft · 120 words · 20 min ago', $lead['meta']);
    }

    public function test_writing_now_has_one_row_per_work_newest_first_and_names_the_chapter_or_poem(): void
    {
        $novel = Work::factory()->novel()->public()->create(['title' => 'Salt Season']);
        for ($position = 1; $position <= 7; $position++) {
            $this->publishedItem($novel, $position);
        }
        $this->draftItem($novel, 8, ['draft_saved_at' => now()->subHours(2)]);
        $latest = $this->draftItem($novel, 9, ['draft_saved_at' => now()->subMinutes(10)]);

        $poem = Work::factory()->poem()->public()->create(['title' => 'Harmattan']);
        $this->draftItem($poem, 1, ['title' => null, 'draft_saved_at' => now()->subMinutes(30)]);

        $collection = Work::factory()->collection(CollectionKind::Poem)->public()->create(['title' => 'Midnight Poems']);
        $this->publishedItem($collection, 1, ['type' => WorkItemType::Piece, 'piece_work_id' => null]);
        $this->draftItem($collection, 2, [
            'type' => WorkItemType::Piece,
            'piece_work_id' => null,
            'title' => 'Dust',
            'draft_saved_at' => $this->wat('2026-11-23 12:00'),
        ]);

        $rows = DeskPage::writingNow();

        $this->assertSame(['Salt Season', 'Harmattan', 'Midnight Poems'], array_column($rows, 'title'));
        $this->assertSame(['Chapter 9 · 10 min ago', '30 min ago', 'Poem 2 · Mon'], array_column($rows, 'meta'));
        $this->assertSame('/works/'.$novel->slug.'/write/'.$latest->id, $rows[0]['href']);
        $this->assertCount(2, DeskPage::writingNow(2));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function writingNowTimes(): array
    {
        return [
            'just now' => ['2026-11-26 09:59:30', 'Just now'],
            'minutes' => ['2026-11-26 09:35:00', '25 min ago'],
            'hours the same day' => ['2026-11-26 08:00:00', '2 h ago'],
            'yesterday' => ['2026-11-25 23:30:00', 'Yesterday'],
            'a weekday this week' => ['2026-11-23 12:00:00', 'Mon'],
            'an older date this year' => ['2026-11-12 12:00:00', '12 Nov'],
            'a date last year' => ['2025-10-22 09:00:00', '22 Oct 2025'],
        ];
    }

    #[DataProvider('writingNowTimes')]
    public function test_writing_now_says_when_a_draft_was_saved_as_the_desk_writes_it(string $savedAt, string $expected): void
    {
        $poem = Work::factory()->poem()->public()->create(['title' => 'Harmattan']);
        $this->draftItem($poem, 1, ['title' => null, 'draft_saved_at' => $this->wat($savedAt)]);

        $this->assertSame($expected, DeskPage::writingNow()[0]['meta']);
    }

    public function test_comments_count_only_unseen_reader_comments_and_show_the_newest_three_with_their_place(): void
    {
        $author = User::factory()->author()->create();
        $reader = User::factory()->create();

        $novel = Work::factory()->novel()->public()->create(['title' => 'The Ember Gate']);
        $this->publishedItem($novel, 1);
        $chapterTwo = $this->publishedItem($novel, 2);
        $poem = Work::factory()->poem()->public()->create(['title' => 'Harmattan']);

        $newest = Comment::factory()->create([
            'user_id' => $reader->id,
            'work_id' => $novel->id,
            'work_item_id' => $chapterTwo->id,
            'body' => 'Chapter two is wild',
            'created_at' => now()->subMinutes(5),
        ]);
        Comment::factory()->replyTo($newest)->create(['user_id' => $reader->id, 'body' => 'Agreed', 'created_at' => now()->subHour()]);
        Comment::factory()->create(['user_id' => $reader->id, 'work_id' => $poem->id, 'body' => 'Lovely', 'created_at' => now()->subHours(2)]);

        // Left out: seen, hidden, the writer's own, a review mark, and deleted.
        Comment::factory()->create([
            'user_id' => $reader->id,
            'work_id' => $novel->id,
            'seen_at' => now()->subHour(),
            'created_at' => now()->subMinutes(30),
        ]);
        Comment::factory()->hidden()->create(['user_id' => $reader->id, 'work_id' => $novel->id, 'created_at' => now()->subMinutes(20)]);
        Comment::factory()->create(['user_id' => $author->id, 'work_id' => $novel->id, 'created_at' => now()->subMinutes(15)]);
        Comment::factory()->review()->create(['user_id' => $reader->id, 'work_id' => $novel->id, 'created_at' => now()->subMinutes(10)]);
        Comment::factory()->deleted()->create(['user_id' => $reader->id, 'work_id' => $novel->id, 'created_at' => now()->subMinutes(8)]);

        $comments = DeskPage::props()['comments'];

        $this->assertSame(3, $comments['count']);
        $this->assertSame(['Chapter two is wild', 'Agreed', 'Lovely'], array_column($comments['rows'], 'text'));
        $this->assertSame(['The Ember Gate · Chapter 2', 'The Ember Gate · Chapter 2', 'Harmattan'], array_column($comments['rows'], 'on'));
        $this->assertSame($reader->username, $comments['rows'][0]['who']);
        $this->assertSame('/comments?w='.$novel->slug, $comments['rows'][0]['href']);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function commentAges(): array
    {
        return [
            'just now' => [30, 'Now'],
            'minutes' => [5 * 60, '5 min'],
            'hours' => [2 * 3600, '2 h'],
            'yesterday' => [30 * 3600, 'Yesterday'],
            'days' => [3 * 86400, '3 d'],
            'weeks, rounded' => [20 * 86400, '3 w'],
        ];
    }

    #[DataProvider('commentAges')]
    public function test_a_comment_is_aged_as_the_comment_list_writes_it(int $secondsAgo, string $expected): void
    {
        $poem = Work::factory()->poem()->public()->create(['title' => 'Harmattan']);
        Comment::factory()->create(['work_id' => $poem->id, 'created_at' => now()->subSeconds($secondsAgo)]);

        $this->assertSame($expected, DeskPage::props()['comments']['rows'][0]['ago']);
    }

    public function test_reviewers_show_the_two_newest_with_their_marks_on_the_newest_place_and_the_unseen_count(): void
    {
        $author = User::factory()->author()->create();
        $ada = User::factory()->reviewer()->create(['name' => 'Ada Okafor']);
        $tunde = User::factory()->reviewer()->create(['name' => 'Tunde Bello']);
        $chidi = User::factory()->reviewer()->create(['name' => 'Chidi Eze']);

        $novel = Work::factory()->novel()->public()->create(['title' => 'The Ember Gate']);
        $chapters = [];
        for ($position = 1; $position <= 7; $position++) {
            $chapters[$position] = $this->publishedItem($novel, $position);
        }
        $poem = Work::factory()->poem()->public()->create(['title' => 'Harmattan']);

        $onChapterSeven = fn (User $user, array $attributes): Comment => Comment::factory()->review()->create([
            'user_id' => $user->id,
            'work_id' => $novel->id,
            'work_item_id' => $chapters[7]->id,
            ...$attributes,
        ]);

        $newestMark = $onChapterSeven($ada, ['kind' => CommentKind::Comment, 'created_at' => now()->subHour()]);
        $onChapterSeven($ada, ['kind' => CommentKind::Line, 'seen_at' => now(), 'created_at' => now()->subHours(2)]);
        $onChapterSeven($ada, ['kind' => CommentKind::Book, 'created_at' => now()->subHours(3)]);
        $onChapterSeven($ada, ['kind' => CommentKind::Edit, 'created_at' => now()->subHours(4)]);
        Comment::factory()->review()->create([
            'user_id' => $ada->id,
            'work_id' => $novel->id,
            'work_item_id' => $chapters[2]->id,
            'kind' => CommentKind::Comment,
            'created_at' => now()->subDay(),
        ]);

        Comment::factory()->review()->create([
            'user_id' => $tunde->id,
            'work_id' => $poem->id,
            'kind' => CommentKind::Line,
            'created_at' => now()->subHours(3),
        ]);
        Comment::factory()->review()->create([
            'user_id' => $chidi->id,
            'work_id' => $novel->id,
            'work_item_id' => $chapters[3]->id,
            'created_at' => now()->subDays(5),
        ]);

        // Left out: resolved, a reply, deleted, the writer's own, and a public comment.
        Comment::factory()->review()->create([
            'user_id' => $tunde->id,
            'work_id' => $novel->id,
            'status' => CommentStatus::Resolved,
            'created_at' => now()->subMinutes(10),
        ]);
        Comment::factory()->review()->replyTo($newestMark)->create(['user_id' => $tunde->id, 'created_at' => now()->subMinutes(9)]);
        Comment::factory()->review()->deleted()->create(['user_id' => $tunde->id, 'work_id' => $poem->id, 'created_at' => now()->subMinutes(8)]);
        $onChapterSeven($author, ['kind' => CommentKind::Comment, 'created_at' => now()->subMinutes(7)]);
        Comment::factory()->create([
            'user_id' => $tunde->id,
            'work_id' => $poem->id,
            'audience' => CommentAudience::Public,
            'status' => null,
            'created_at' => now()->subMinutes(6),
        ]);

        $reviewers = DeskPage::props()['reviewers'];

        $this->assertSame(6, $reviewers['count']);
        $this->assertSame(['Ada Okafor', 'Tunde Bello'], array_column($reviewers['rows'], 'who'));
        $this->assertSame([
            'Left 3 notes and 1 edit on The Ember Gate, chapter 7',
            'Left 1 note on Harmattan',
        ], array_column($reviewers['rows'], 'text'));
        $this->assertSame(['1 h', '3 h'], array_column($reviewers['rows'], 'ago'));
        $this->assertSame('/comments?tab=rev&who=Ada%20Okafor', $reviewers['rows'][0]['href']);
    }

    public function test_the_week_sums_the_site_reads_and_words_against_the_week_before_and_names_the_top_work(): void
    {
        $poem = Work::factory()->poem()->public()->create(['title' => 'Harmattan']);
        $novel = Work::factory()->novel()->public()->create(['title' => 'The Ember Gate']);

        // Site totals: this week (20 to 26 Nov), the week before (13 to 19 Nov), and a day outside both.
        $this->stat('2026-11-26', StatSubject::Site, 100);
        $this->stat('2026-11-25', StatSubject::Site, 50);
        $this->stat('2026-11-20', StatSubject::Site, 10);
        $this->stat('2026-11-19', StatSubject::Site, 70);
        $this->stat('2026-11-13', StatSubject::Site, 30);
        $this->stat('2026-11-12', StatSubject::Site, 999);

        // Per-work reads count for the top work only, not the site total.
        $this->stat('2026-11-26', StatSubject::Work, 40, $poem);
        $this->stat('2026-11-20', StatSubject::Work, 30, $poem);
        $this->stat('2026-11-25', StatSubject::Work, 60, $novel);
        $this->stat('2026-11-19', StatSubject::Work, 900, $novel);

        WritingDay::factory()->create(['day' => '2026-11-26', 'work_id' => $poem->id, 'words_added' => 300]);
        WritingDay::factory()->create(['day' => '2026-11-20', 'work_id' => $novel->id, 'words_added' => 200]);
        WritingDay::factory()->create(['day' => '2026-11-19', 'work_id' => $novel->id, 'words_added' => 999]);

        Subscriber::factory()->confirmed()->create(['confirmed_at' => now()->subDay()]);
        Subscriber::factory()->confirmed()->create(['confirmed_at' => now()->subDays(2)]);
        Subscriber::factory()->confirmed()->create(['confirmed_at' => now()->subDays(10)]);
        Subscriber::factory()->create();

        $week = DeskPage::props()['week'];

        $this->assertSame(160, $week['reads']);
        $this->assertSame(100, $week['before']);
        $this->assertSame([10, 0, 0, 0, 0, 50, 100], $week['spark']);
        $this->assertSame(500, $week['words']);
        $this->assertSame(3, $week['subscribers']);
        $this->assertSame(2, $week['newSubscribers']);
        $this->assertSame('Harmattan', $week['top']['title']);
        $this->assertSame(70, $week['top']['reads']);
        $this->assertSame('/stats?r=7&open='.$poem->slug, $week['top']['href']);
    }

    public function test_scheduled_lists_the_soonest_three_with_the_count_of_all_future_ones(): void
    {
        $novel = Work::factory()->novel()->public()->create(['title' => 'Paper Boats']);
        for ($position = 1; $position <= 31; $position++) {
            $this->publishedItem($novel, $position);
        }

        $chapterThirtyTwo = WorkItem::factory()->for($novel)->create([
            'position' => 32,
            'status' => PublishStatus::Scheduled,
            'scheduled_at' => $this->wat('2026-11-27 20:00'),
            'draft_saved_at' => null,
        ]);
        $poem = Work::factory()->poem()->public()->create(['title' => 'Harmattan']);
        WorkItem::factory()->for($poem)->create([
            'position' => 1,
            'status' => PublishStatus::Scheduled,
            'scheduled_at' => $this->wat('2026-11-28 09:00'),
            'draft_saved_at' => null,
        ]);
        WorkItem::factory()->for($novel)->create([
            'position' => 33,
            'status' => PublishStatus::Scheduled,
            'scheduled_at' => $this->wat('2026-12-05 20:00'),
            'draft_saved_at' => null,
        ]);
        WorkItem::factory()->for($poem)->create([
            'position' => 2,
            'status' => PublishStatus::Scheduled,
            'scheduled_at' => $this->wat('2026-12-06 08:00'),
            'draft_saved_at' => null,
        ]);

        // Left out: already past, and a draft with a future date.
        WorkItem::factory()->for($poem)->create([
            'position' => 3,
            'status' => PublishStatus::Scheduled,
            'scheduled_at' => $this->wat('2026-11-20 09:00'),
            'draft_saved_at' => null,
        ]);
        WorkItem::factory()->for($poem)->create([
            'position' => 4,
            'status' => PublishStatus::Draft,
            'scheduled_at' => $this->wat('2026-12-01 09:00'),
            'draft_saved_at' => null,
        ]);

        $scheduled = DeskPage::props()['scheduled'];

        $this->assertSame(4, $scheduled['count']);
        $this->assertSame([
            ['href' => '/works/'.$novel->slug.'/write/'.$chapterThirtyTwo->id, 'label' => 'Paper Boats, chapter 32', 'at' => 'Fri 27 Nov, 20:00'],
            ['href' => '/works/'.$poem->slug.'/write/'.$poem->items()->where('position', 1)->value('id'), 'label' => 'Harmattan', 'at' => 'Sat 28 Nov, 09:00'],
            ['href' => '/works/'.$novel->slug.'/write/'.$novel->items()->where('position', 33)->value('id'), 'label' => 'Paper Boats, chapter 33', 'at' => 'Sat 5 Dec, 20:00'],
        ], $scheduled['rows']);
    }

    public function test_notes_show_the_next_scheduled_and_the_last_edited_draft(): void
    {
        $soonest = Note::factory()->scheduled()->create([
            'draft_text' => 'Thinking about the rain.',
            'scheduled_at' => $this->wat('2026-11-27 18:00'),
        ]);
        Note::factory()->scheduled()->create(['draft_text' => 'Later', 'scheduled_at' => $this->wat('2026-11-28 09:00')]);
        Note::factory()->scheduled()->create(['draft_text' => 'Gone already', 'scheduled_at' => $this->wat('2026-11-20 09:00')]);
        Note::factory()->create(['draft_text' => 'Old draft', 'updated_at' => now()->subDays(3)]);
        $draft = Note::factory()->create(['draft_text' => 'Newest draft', 'updated_at' => now()->subHour()]);

        $this->assertSame([
            ['href' => '/notes?n='.$soonest->id, 'text' => 'Thinking about the rain.', 'meta' => 'Fri 27 Nov, 6:00 pm', 'scheduled' => true],
            ['href' => '/notes?n='.$draft->id, 'text' => 'Newest draft', 'meta' => 'Draft', 'scheduled' => false],
        ], DeskPage::props()['notes']['rows']);
    }

    public function test_hearts_count_likes_on_notes_since_the_desk_last_looked(): void
    {
        SiteSetting::put('desk.hearts_seen_at', $this->wat('2026-11-25 10:00')->toIso8601String());

        $note = Note::factory()->published()->create();
        $this->like($note, $this->wat('2026-11-25 11:00'));
        $this->like($note, $this->wat('2026-11-26 09:00'));
        $this->like($note, $this->wat('2026-11-24 23:00'));

        $item = $this->publishedItem(Work::factory()->novel()->public()->create(), 1);
        Like::factory()->create([
            'likeable_type' => 'work_item',
            'likeable_id' => $item->id,
            'created_at' => $this->wat('2026-11-26 09:00'),
        ]);

        $this->assertSame(2, DeskPage::props()['notes']['hearts']);
    }

    public function test_hearts_fall_back_to_the_last_seven_days_when_the_desk_has_not_looked(): void
    {
        $note = Note::factory()->published()->create();
        $this->like($note, now()->subDays(6));
        $this->like($note, now()->subDays(8));

        $this->assertSame(1, DeskPage::props()['notes']['hearts']);
    }

    public function test_a_store_link_that_did_not_open_on_the_published_in_print_page_is_listed_to_fix(): void
    {
        $amazon = 'https://amazon.example/the-ember-gate';
        $bookshop = 'https://bookshop.example/the-ember-gate';

        SitePage::factory()->inPrint()->create(['live' => ['books' => [[
            'slug' => 'the-ember-gate',
            'title' => 'The Ember Gate',
            'formats' => [[
                'format' => 'Paperback',
                'stores' => [
                    ['store' => 'Amazon', 'price' => ['amount' => 850000, 'currency' => 'NGN'], 'url' => $amazon],
                    ['store' => 'Bookshop', 'price' => null, 'url' => $bookshop],
                ],
            ]],
        ]]]]);
        LinkCheck::factory()->broken()->create(['url' => $amazon, 'url_hash' => hash('sha256', $amazon)]);
        LinkCheck::factory()->create(['url' => $bookshop, 'url_hash' => hash('sha256', $bookshop), 'ok' => true]);

        $this->assertSame([[
            'kind' => 'broken',
            'before' => 'The Amazon link for ',
            'bold' => 'The Ember Gate',
            'after' => ' didn’t open',
            'href' => '/site?tab=print&open=the-ember-gate',
            'action' => 'Fix it',
        ]], DeskPage::props()['site']);
    }

    public function test_a_broken_link_only_on_an_unpublished_in_print_draft_is_not_listed(): void
    {
        $url = 'https://amazon.example/draft-only';

        SitePage::factory()->inPrint()->create([
            'draft' => ['books' => [[
                'slug' => 'the-ember-gate',
                'title' => 'The Ember Gate',
                'formats' => [['format' => 'Paperback', 'stores' => [['store' => 'Amazon', 'price' => null, 'url' => $url]]]],
            ]]],
            'live' => null,
        ]);
        LinkCheck::factory()->broken()->create(['url' => $url, 'url_hash' => hash('sha256', $url)]);

        $this->assertSame([], DeskPage::props()['site']);
    }

    public function test_a_notice_that_is_up_shows_its_end_or_says_up_when_it_has_none(): void
    {
        Notice::factory()->create([
            'text' => 'Signed copies this weekend',
            'starts_at' => now()->subHour(),
            'ends_at' => $this->wat('2026-11-27 10:00'),
        ]);

        $this->assertSame([[
            'kind' => 'notice',
            'before' => 'Notice up until Fri 27 Nov, 10:00 am',
            'bold' => null,
            'after' => '',
            'href' => '/site?tab=notice',
            'action' => 'Edit',
        ]], DeskPage::props()['site']);
    }

    public function test_a_notice_with_no_end_says_notice_up(): void
    {
        Notice::factory()->create(['starts_at' => now()->subHour(), 'ends_at' => null]);

        $this->assertSame('Notice up', DeskPage::props()['site'][0]['before']);
    }

    public function test_a_notice_not_yet_started_ended_or_taken_down_is_not_listed(): void
    {
        Notice::factory()->scheduled()->create();
        Notice::factory()->expired()->create();
        Notice::factory()->takenDown()->create();

        $this->assertSame([], DeskPage::props()['site']);
    }

    public function test_a_home_pin_with_a_date_ahead_shows_until_that_day(): void
    {
        $novel = Work::factory()->novel()->public()->published()->create(['title' => 'The Ember Gate']);
        SiteSetting::put('home_pin', ['slug' => $novel->slug, 'until' => now()->addDays(2)->toIso8601String()]);

        $this->assertSame([[
            'kind' => 'pin',
            'before' => '',
            'bold' => 'The Ember Gate',
            'after' => ' leads Home until Sat 28 Nov',
            'href' => '/site?tab=home',
            'action' => 'Change',
        ]], DeskPage::props()['site']);
    }

    public function test_a_home_pin_that_has_passed_or_is_not_listed_shows_nothing(): void
    {
        $novel = Work::factory()->novel()->public()->published()->create(['title' => 'The Ember Gate']);
        SiteSetting::put('home_pin', ['slug' => $novel->slug, 'until' => now()->subMinute()->toIso8601String()]);

        $this->assertSame([], DeskPage::props()['site']);

        $private = Work::factory()->novel()->create(['title' => 'Private']);
        SiteSetting::put('home_pin', ['slug' => $private->slug, 'until' => now()->addDay()->toIso8601String()]);

        $this->assertSame([], DeskPage::props()['site']);
    }

    public function test_site_items_come_as_broken_links_then_the_notice_then_the_pin(): void
    {
        $url = 'https://amazon.example/the-ember-gate';
        $novel = Work::factory()->novel()->public()->published()->create(['title' => 'The Ember Gate']);

        SiteSetting::put('home_pin', ['slug' => $novel->slug, 'until' => now()->addDays(2)->toIso8601String()]);
        Notice::factory()->create(['starts_at' => now()->subHour(), 'ends_at' => null]);
        SitePage::factory()->inPrint()->create(['live' => ['books' => [[
            'slug' => 'the-ember-gate',
            'title' => 'The Ember Gate',
            'formats' => [['format' => 'Paperback', 'stores' => [['store' => 'Amazon', 'price' => null, 'url' => $url]]]],
        ]]]]);
        LinkCheck::factory()->broken()->create(['url' => $url, 'url_hash' => hash('sha256', $url)]);

        $this->assertSame(['broken', 'notice', 'pin'], array_column(DeskPage::props()['site'], 'kind'));
    }
}
