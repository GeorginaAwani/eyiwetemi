<?php

namespace Tests\Feature;

use App\Enums\PublishStatus;
use App\Enums\StatDimension;
use App\Enums\StatSubject;
use App\Enums\UserRole;
use App\Enums\Visibility;
use App\Models\DailyBreakdown;
use App\Models\DailyStat;
use App\Models\DailyVisitor;
use App\Models\Note;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CountTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-27';

    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 12:00:00', 'Africa/Lagos'));
    }

    public function test_a_reader_is_counted_once_per_chapter_per_day(): void
    {
        $work = $this->novel();
        $chapter = $work->items->first();

        $this->hit(['t' => 'item', 'e' => 'read', 'id' => $chapter->id]);
        $this->hit(['t' => 'item', 'e' => 'read', 'id' => $chapter->id]);

        $this->assertSame(['reads' => 1, 'readers' => 1, 'finished' => 0], $this->totals(StatSubject::Chapter, $chapter->id));
        $this->assertSame(['reads' => 1, 'readers' => 1, 'finished' => 0], $this->totals(StatSubject::Work, $work->id));
        $this->assertSame(['reads' => 1, 'readers' => 1, 'finished' => 0], $this->totals(StatSubject::Site));
    }

    public function test_a_second_reader_is_a_new_reader_on_the_chapter_the_work_and_the_site(): void
    {
        $work = $this->novel();
        $chapter = $work->items->first();

        $this->hit(['t' => 'item', 'e' => 'read', 'id' => $chapter->id]);
        $this->hit(['t' => 'item', 'e' => 'read', 'id' => $chapter->id], ip: '203.0.113.11');

        $this->assertSame(['reads' => 2, 'readers' => 2, 'finished' => 0], $this->totals(StatSubject::Chapter, $chapter->id));
        $this->assertSame(['reads' => 2, 'readers' => 2, 'finished' => 0], $this->totals(StatSubject::Site));
    }

    public function test_a_single_story_has_only_its_work_row(): void
    {
        $story = $this->story();

        $this->hit(['t' => 'item', 'e' => 'read', 'id' => $story->id]);

        $this->assertSame(['reads' => 1, 'readers' => 1, 'finished' => 0], $this->totals(StatSubject::Work, $story->work_id));
        $this->assertSame(0, DailyStat::query()->where('subject', StatSubject::Chapter)->count());
    }

    public function test_finishing_counts_once_and_only_for_a_reader_who_was_counted_reading(): void
    {
        $work = $this->novel();
        $chapter = $work->items->first();

        $this->hit(['t' => 'item', 'e' => 'end', 'id' => $chapter->id]);

        $this->assertSame(0, DailyStat::query()->count());

        $this->hit(['t' => 'item', 'e' => 'read', 'id' => $chapter->id]);
        $this->hit(['t' => 'item', 'e' => 'end', 'id' => $chapter->id]);
        $this->hit(['t' => 'item', 'e' => 'end', 'id' => $chapter->id]);

        $this->assertSame(1, $this->totals(StatSubject::Chapter, $chapter->id)['finished']);
        $this->assertSame(1, $this->totals(StatSubject::Work, $work->id)['finished']);
        $this->assertSame(1, $this->totals(StatSubject::Site)['finished']);
    }

    #[DataProvider('hiddenVisibilities')]
    public function test_a_chapter_of_a_work_the_public_cannot_open_is_not_counted(Visibility $visibility): void
    {
        $work = Work::factory()->novel()->state(['visibility' => $visibility])->published()->create();
        $chapter = WorkItem::factory()->published()->for($work)->create();

        $this->hit(['t' => 'item', 'e' => 'read', 'id' => $chapter->id]);

        $this->assertSame(0, DailyStat::query()->count());
    }

    #[DataProvider('unreleasedStatuses')]
    public function test_a_chapter_that_is_not_published_is_not_counted(PublishStatus $status): void
    {
        $work = Work::factory()->novel()->public()->published()->create();
        $chapter = WorkItem::factory()->for($work)->state(['status' => $status, 'published_at' => now()->subWeek()])->create();

        $this->hit(['t' => 'item', 'e' => 'read', 'id' => $chapter->id]);

        $this->assertSame(0, DailyStat::query()->count());
    }

    #[DataProvider('notReaders')]
    public function test_a_bot_or_a_request_without_a_browser_is_not_counted(string $agent): void
    {
        $chapter = $this->novel()->items->first();

        $this->hit(['t' => 'item', 'e' => 'read', 'id' => $chapter->id], agent: $agent);

        $this->assertSame(0, DailyStat::query()->count());
    }

    public function test_an_in_app_browser_that_names_whatsapp_is_counted_as_a_phone(): void
    {
        $chapter = $this->novel()->items->first();

        $this->hit(['t' => 'item', 'e' => 'read', 'id' => $chapter->id], agent: self::PHONE.' WhatsApp');

        $this->assertSame(['reads' => 1, 'readers' => 1, 'finished' => 0], $this->totals(StatSubject::Site));
        $this->assertSame(1, $this->breakdown(StatDimension::Device, 'phone'));
    }

    #[DataProvider('staffRoles')]
    public function test_the_owner_and_reviewers_are_not_counted_while_signed_in(UserRole $role): void
    {
        $chapter = $this->novel()->items->first();
        $this->actingAs(User::factory()->state(['role' => $role])->create());

        $this->hit(['t' => 'item', 'e' => 'read', 'id' => $chapter->id]);

        $this->assertSame(0, DailyStat::query()->count());
    }

    public function test_a_signed_in_reader_is_counted(): void
    {
        $chapter = $this->novel()->items->first();
        $this->actingAs(User::factory()->reader()->create());

        $this->hit(['t' => 'item', 'e' => 'read', 'id' => $chapter->id]);

        $this->assertSame(['reads' => 1, 'readers' => 1, 'finished' => 0], $this->totals(StatSubject::Site));
    }

    public function test_a_prefetch_is_not_counted(): void
    {
        $chapter = $this->novel()->items->first();

        $this->hit(['t' => 'item', 'e' => 'read', 'id' => $chapter->id], headers: ['Sec-Purpose' => 'prefetch;prerender']);

        $this->assertSame(0, DailyStat::query()->count());
    }

    #[DataProvider('sources')]
    public function test_a_read_is_filed_under_where_it_came_from(?string $from, ?string $referrer, string $source): void
    {
        $chapter = $this->novel()->items->first();

        $this->hit(['t' => 'item', 'e' => 'read', 'id' => $chapter->id, 'from' => $from, 'ref' => $referrer]);

        $this->assertSame(1, $this->breakdown(StatDimension::Source, $source));
    }

    public function test_a_referrer_from_the_site_or_the_admin_is_counted_as_direct(): void
    {
        $chapter = $this->novel()->items->first();

        $this->hit(['t' => 'item', 'e' => 'read', 'id' => $chapter->id, 'ref' => config('app.url').'/library']);
        $this->hit(['t' => 'item', 'e' => 'read', 'id' => $chapter->id, 'ref' => 'https://'.config('eyiwetemi.admin_domain').'/'], ip: '203.0.113.11');

        $this->assertSame(2, $this->breakdown(StatDimension::Source, 'Direct'));
    }

    #[DataProvider('countryHeaders')]
    public function test_the_country_header_is_filed_only_when_it_names_a_real_country(string $header, array $expected): void
    {
        $chapter = $this->novel()->items->first();

        $this->hit(['t' => 'item', 'e' => 'read', 'id' => $chapter->id], headers: [config('eyiwetemi.stats.country_header') => $header]);

        $this->assertSame($expected, DailyBreakdown::query()->where('dimension', StatDimension::Country)->pluck('value')->all());
    }

    #[DataProvider('devices')]
    public function test_the_device_is_a_phone_or_a_computer(string $agent, string $device): void
    {
        $chapter = $this->novel()->items->first();

        $this->hit(['t' => 'item', 'e' => 'read', 'id' => $chapter->id], agent: $agent);

        $this->assertSame(1, $this->breakdown(StatDimension::Device, $device));
    }

    public function test_a_published_note_is_counted_once_per_reader(): void
    {
        $note = Note::factory()->published()->create();
        $payload = ['t' => 'note', 'e' => 'read', 'id' => $note->id];

        $this->hit($payload);
        $this->hit($payload);

        $this->assertSame(['reads' => 1, 'readers' => 1, 'finished' => 0], $this->totals(StatSubject::Note, $note->id));

        $this->hit($payload, ip: '203.0.113.11');

        $this->assertSame(['reads' => 2, 'readers' => 2, 'finished' => 0], $this->totals(StatSubject::Note, $note->id));
    }

    public function test_a_note_that_is_not_published_is_not_counted(): void
    {
        $note = Note::factory()->create();

        $this->hit(['t' => 'note', 'e' => 'read', 'id' => $note->id]);

        $this->assertSame(0, DailyStat::query()->count());
    }

    public function test_a_site_page_is_counted_once_per_reader_and_page_with_no_breakdowns(): void
    {
        $this->hit(['t' => 'page', 'e' => 'read', 'page' => 'about']);
        $this->hit(['t' => 'page', 'e' => 'read', 'page' => 'about']);

        $this->assertSame(['reads' => 1, 'readers' => 1, 'finished' => 0], $this->totals(StatSubject::Page));

        $this->hit(['t' => 'page', 'e' => 'read', 'page' => 'privacy']);

        $this->assertSame(['reads' => 2, 'readers' => 1, 'finished' => 0], $this->totals(StatSubject::Page));

        $this->hit(['t' => 'page', 'e' => 'read', 'page' => 'about'], ip: '203.0.113.11');

        $this->assertSame(['reads' => 3, 'readers' => 2, 'finished' => 0], $this->totals(StatSubject::Page));
        $this->assertSame(0, DailyBreakdown::query()->count());
    }

    public function test_a_reader_is_counted_again_on_the_next_day(): void
    {
        $chapter = $this->novel()->items->first();
        $payload = ['t' => 'item', 'e' => 'read', 'id' => $chapter->id];

        $this->hit($payload);
        $this->travelTo(CarbonImmutable::parse('2026-11-28 12:00:00', 'Africa/Lagos'));
        $this->hit($payload);

        $this->assertSame(['reads' => 1, 'readers' => 1, 'finished' => 0], $this->totals(StatSubject::Site, day: self::TODAY));
        $this->assertSame(['reads' => 1, 'readers' => 1, 'finished' => 0], $this->totals(StatSubject::Site, day: '2026-11-28'));
    }

    public function test_a_read_is_filed_under_the_west_africa_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-27 23:30:00', 'UTC'));
        $chapter = $this->novel()->items->first();

        $this->hit(['t' => 'item', 'e' => 'read', 'id' => $chapter->id]);

        $this->assertSame(['reads' => 1, 'readers' => 1, 'finished' => 0], $this->totals(StatSubject::Site, day: '2026-11-28'));
    }

    #[DataProvider('invalidHits')]
    public function test_an_invalid_hit_is_answered_with_no_content_and_counts_nothing(array $overrides): void
    {
        $chapter = $this->novel()->items->first();

        $this->hit(array_merge(['t' => 'item', 'e' => 'read', 'id' => $chapter->id], $overrides))->assertNoContent();

        $this->assertSame(0, DailyStat::query()->count());
        $this->assertSame(0, DailyVisitor::query()->count());
    }

    public function test_a_counted_hit_is_answered_with_no_content_and_sets_no_cookie_of_its_own(): void
    {
        $chapter = $this->novel()->items->first();

        $response = $this->hit(['t' => 'item', 'e' => 'read', 'id' => $chapter->id]);

        $response->assertNoContent();
        $this->assertSame([], array_values(array_diff(
            array_map(fn ($cookie): string => $cookie->getName(), $response->headers->getCookies()),
            [config('session.cookie'), 'XSRF-TOKEN'],
        )));
    }

    /**
     * One hit from the reading site, from one reader: the address and browser say who they are.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function hit(array $payload, string $ip = '203.0.113.10', string $agent = self::BROWSER, array $headers = []): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders(['User-Agent' => $agent] + $headers)
            ->post('/count', $payload);
    }

    /**
     * A public novel with three published chapters.
     */
    private function novel(): Work
    {
        $work = Work::factory()->novel()->public()->published()->create();
        WorkItem::factory()->published()->count(3)->for($work)->create();

        return $work;
    }

    /**
     * A public single story with one published piece.
     */
    private function story(): WorkItem
    {
        $story = Work::factory()->story()->public()->published()->create();

        return WorkItem::factory()->published()->for($story)->create();
    }

    /**
     * @return array{reads: int, readers: int, finished: int}
     */
    private function totals(StatSubject $subject, int $subjectId = 0, string $day = self::TODAY): array
    {
        $stat = DailyStat::query()->where('day', $day)->where('subject', $subject)->where('subject_id', $subjectId)->sole();

        return ['reads' => (int) $stat->reads, 'readers' => (int) $stat->readers, 'finished' => (int) $stat->finished];
    }

    private function breakdown(StatDimension $dimension, string $value): ?int
    {
        $reads = DailyBreakdown::query()->where('day', self::TODAY)->where('dimension', $dimension)->where('value', $value)->value('reads');

        return $reads === null ? null : (int) $reads;
    }

    /**
     * @return array<string, array{Visibility}>
     */
    public static function hiddenVisibilities(): array
    {
        return [
            'private' => [Visibility::Private],
            'reviewers only' => [Visibility::Reviewers],
        ];
    }

    /**
     * @return array<string, array{PublishStatus}>
     */
    public static function unreleasedStatuses(): array
    {
        return [
            'draft' => [PublishStatus::Draft],
            'scheduled' => [PublishStatus::Scheduled],
            'taken down' => [PublishStatus::Unpublished],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notReaders(): array
    {
        return [
            'search crawler' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'whatsapp link preview' => ['WhatsApp/2.23.20.0'],
            'curl' => ['curl/8.4.0'],
            'headless browser' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/130.0.0.0 Safari/537.36'],
            'no browser' => [''],
        ];
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function staffRoles(): array
    {
        return [
            'the owner' => [UserRole::Author],
            'a reviewer' => [UserRole::Reviewer],
        ];
    }

    /**
     * @return array<string, array{?string, ?string, string}>
     */
    public static function sources(): array
    {
        return [
            'the newsletter link' => ['newsletter', 'https://www.instagram.com/', 'Newsletter'],
            'no referrer' => [null, null, 'Direct'],
            'a referrer that is not a web address' => [null, 'not a link', 'Direct'],
            'instagram' => [null, 'https://www.instagram.com/p/abc/', 'Instagram'],
            'instagram in-app browser' => [null, 'https://l.instagram.com/?u=https%3A%2F%2Fexample.com', 'Instagram'],
            'x short link' => [null, 'https://t.co/AbC123', 'X'],
            'google news' => [null, 'https://news.google.com/', 'Google'],
            'google in another country' => [null, 'https://www.google.co.uk/', 'Google'],
            'whatsapp link' => [null, 'https://wa.me/2348000000000', 'WhatsApp'],
            'facebook on mobile' => [null, 'https://m.facebook.com/', 'Facebook'],
            'goodreads' => [null, 'https://www.goodreads.com/book/show/1', 'Goodreads'],
            'tiktok' => [null, 'https://www.tiktok.com/@reader', 'TikTok'],
            'another site' => [null, 'https://lagosreview.ng/post', 'lagosreview.ng'],
        ];
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function countryHeaders(): array
    {
        return [
            'a country' => ['NG', ['NG']],
            'unknown' => ['XX', []],
            'tor' => ['T1', []],
            'missing' => ['', []],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function devices(): array
    {
        return [
            'a phone' => [self::PHONE, 'phone'],
            'a computer' => [self::BROWSER, 'computer'],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidHits(): array
    {
        return [
            'an unknown kind of hit' => [['t' => 'chapter']],
            'an unknown event' => [['e' => 'open']],
            'an id that is not a number' => [['id' => 'abc']],
            'no id for a chapter' => [['id' => null]],
            'a page that is not on the list' => [['t' => 'page', 'page' => 'contact']],
        ];
    }
}
