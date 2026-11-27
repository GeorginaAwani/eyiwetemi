<?php

namespace Tests\Feature\Admin;

use App\Enums\CollectionKind;
use App\Enums\PublishStatus;
use App\Enums\StatDimension;
use App\Enums\StatSubject;
use App\Enums\Visibility;
use App\Enums\WorkItemType;
use App\Models\DailyBreakdown;
use App\Models\DailyStat;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use App\Support\StatsPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-11-26 10:00', 'Africa/Lagos'));
    }

    private function asAdmin(): static
    {
        return $this->actingAs(User::factory()->author()->create())->withSession(['admin.verified' => true]);
    }

    private function url(string $path): string
    {
        return 'http://'.config('eyiwetemi.admin_domain').$path;
    }

    /**
     * A day's stat row for the site, a work, a chapter, a note or the pages.
     */
    private function stat(string $day, StatSubject $subject, int $reads, int $readers = 0, int $finished = 0, int $subjectId = 0, ?Work $work = null): void
    {
        DailyStat::factory()->create([
            'day' => $day,
            'subject' => $subject,
            'subject_id' => $subjectId,
            'work_id' => $work?->id,
            'reads' => $reads,
            'readers' => $readers,
            'finished' => $finished,
        ]);
    }

    private function breakdown(string $day, StatDimension $dimension, string $value, int $reads): void
    {
        DailyBreakdown::factory()->create(['day' => $day, 'dimension' => $dimension, 'value' => $value, 'reads' => $reads]);
    }

    /**
     * A chapter or piece that went out at a West Africa Time wall clock, and is published unless told otherwise.
     */
    private function item(Work $work, int $position, string $title, string $publishedAt, PublishStatus $status = PublishStatus::Published, WorkItemType $type = WorkItemType::Chapter): WorkItem
    {
        return WorkItem::factory()->for($work)->create([
            'type' => $type,
            'position' => $position,
            'title' => $title,
            'status' => $status,
            'published_at' => Carbon::parse($publishedAt, 'Africa/Lagos')->utc(),
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ranges(): array
    {
        return [
            'seven days' => ['7'],
            'thirty days' => ['30'],
            'twelve months' => ['12'],
            'all time' => ['all'],
        ];
    }

    #[DataProvider('ranges')]
    public function test_the_page_renders_the_stats_props_for_each_range(string $range): void
    {
        $work = Work::factory()->novel()->public()->create(['title' => 'Paper Boats']);
        $this->stat('2026-11-25', StatSubject::Site, 100, 40, 20);
        $this->stat('2026-11-25', StatSubject::Work, 100, 40, 20, $work->id, $work);

        $this->asAdmin()->get($this->url('/stats?r='.$range))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Stats')
                ->where('stats.range', $range)
                ->where('stats.empty', false)
                ->where('metric', 'reads')
                ->where('open', '')
                ->has('stats.figures.reads')
                ->has('stats.figures.readers')
                ->has('stats.figures.finished')
                ->has('stats.chart.buckets')
                ->has('stats.chart.reads')
                ->has('stats.chart.readers')
                ->has('stats.chart.finished')
                ->has('stats.works', 1)
                ->has('stats.notes')
                ->has('stats.pages')
                ->has('stats.sources')
                ->has('stats.countries')
                ->has('stats.phone'));
    }

    public function test_metric_and_open_pass_through_and_an_unknown_metric_falls_back_to_reads(): void
    {
        $this->asAdmin()->get($this->url('/stats?r=7&m=readers&open=paper-boats'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Stats')
                ->where('stats.range', '7')
                ->where('metric', 'readers')
                ->where('open', 'paper-boats'));

        $this->asAdmin()->get($this->url('/stats?m=nope'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.range', '30')
                ->where('metric', 'reads'));
    }

    public function test_the_range_json_returns_the_stats_for_the_range_asked_for(): void
    {
        $this->asAdmin()->getJson($this->url('/stats/range?r=12'))->assertOk()
            ->assertJsonPath('stats.range', '12')
            ->assertJsonCount(12, 'stats.chart.buckets');
    }

    public function test_the_range_json_falls_back_to_thirty_days_for_an_unknown_range(): void
    {
        $this->asAdmin()->getJson($this->url('/stats/range?r=forever'))->assertOk()
            ->assertJsonPath('stats.range', '30')
            ->assertJsonCount(30, 'stats.chart.buckets');
    }

    public function test_a_guest_is_sent_to_sign_in_and_gets_no_stats_json(): void
    {
        $this->get(route('admin.stats'))->assertRedirect(route('admin.signin'));
        $this->getJson(route('admin.stats.range', ['r' => '7']))->assertUnauthorized();
    }

    public function test_a_reader_signed_in_on_the_site_is_not_let_into_the_stats(): void
    {
        $reader = User::factory()->reader()->create();

        $this->actingAs($reader)->get(route('admin.stats'))->assertRedirect(route('admin.signin'));
        $this->actingAs($reader)->getJson(route('admin.stats.range', ['r' => '7']))->assertUnauthorized();
    }

    public function test_figures_sum_the_site_rows_of_the_range_against_the_period_before_it(): void
    {
        $work = Work::factory()->novel()->public()->create();

        // This week (20 to 26 Nov), the week before (13 to 19 Nov), and a day outside both.
        $this->stat('2026-11-26', StatSubject::Site, 100, 40, 20);
        $this->stat('2026-11-20', StatSubject::Site, 50, 30, 10);
        $this->stat('2026-11-19', StatSubject::Site, 70, 20, 5);
        $this->stat('2026-11-13', StatSubject::Site, 30, 10, 3);
        $this->stat('2026-11-12', StatSubject::Site, 999, 999, 999);

        // Work, chapter and page rows are not site totals.
        $this->stat('2026-11-25', StatSubject::Work, 500, 200, 100, $work->id, $work);
        $this->stat('2026-11-25', StatSubject::Page, 54);

        $figures = StatsPage::props('7')['figures'];

        $this->assertSame(['v' => 150, 'before' => 100], $figures['reads']);
        $this->assertSame(['v' => 70, 'before' => 30], $figures['readers']);
        $this->assertSame(['v' => 30, 'before' => 8], $figures['finished']);
    }

    public function test_the_all_time_range_has_no_period_before_and_counts_every_site_row(): void
    {
        $this->stat('2026-11-26', StatSubject::Site, 100, 40, 20);
        $this->stat('2026-11-12', StatSubject::Site, 999, 300, 200);

        $figures = StatsPage::props('all')['figures'];

        $this->assertSame(['v' => 1099, 'before' => null], $figures['reads']);
        $this->assertSame(['v' => 340, 'before' => null], $figures['readers']);
        $this->assertSame(['v' => 220, 'before' => null], $figures['finished']);
    }

    public function test_the_buckets_are_one_per_day_for_seven_and_thirty_days_and_one_per_month_for_twelve(): void
    {
        $seven = StatsPage::props('7')['chart']['buckets'];
        $thirty = StatsPage::props('30')['chart']['buckets'];
        $twelve = StatsPage::props('12')['chart']['buckets'];

        $this->assertCount(7, $seven);
        $this->assertSame(['lab' => '20 Nov', 'tip' => 'Fri 20 Nov', 'ev' => null], $seven[0]);
        $this->assertSame(['lab' => '26 Nov', 'tip' => 'Thu 26 Nov', 'ev' => null], $seven[6]);

        $this->assertCount(30, $thirty);
        $this->assertSame(['lab' => '28 Oct', 'tip' => 'Wed 28 Oct', 'ev' => null], $thirty[0]);

        $this->assertCount(12, $twelve);
        $this->assertSame(['lab' => 'Dec', 'tip' => 'December 2025', 'ev' => null, 'y' => 2025], $twelve[0]);
        $this->assertSame(['lab' => 'Nov', 'tip' => 'November 2026, so far', 'ev' => null, 'y' => 2026], $twelve[11]);
    }

    public function test_the_all_time_buckets_run_monthly_from_the_first_day_with_stats(): void
    {
        $this->stat('2026-09-15', StatSubject::Site, 10);

        $buckets = StatsPage::props('all')['chart']['buckets'];

        $this->assertSame(
            [['lab' => 'Sep', 'tip' => 'September 2026', 'ev' => null, 'y' => 2026], ['lab' => 'Oct', 'tip' => 'October 2026', 'ev' => null, 'y' => 2026], ['lab' => 'Nov', 'tip' => 'November 2026, so far', 'ev' => null, 'y' => 2026]],
            $buckets,
        );
    }

    public function test_a_day_bucket_names_what_went_up_that_day_and_shows_two_at_most(): void
    {
        $novel = Work::factory()->novel()->public()->create(['title' => 'Paper Boats']);
        $poem = Work::factory()->poem()->public()->create(['title' => 'Harmattan']);

        $this->item($novel, 1, 'Opening', '2026-11-10 09:00');
        $this->item($novel, 2, 'Second', '2026-11-20 09:00');
        $this->item($novel, 3, 'Third', '2026-11-20 10:00');
        $this->item($poem, 1, 'Harmattan', '2026-11-20 11:00', type: WorkItemType::Piece);

        $buckets = StatsPage::props('30')['chart']['buckets'];

        $this->assertSame('Paper Boats went up', $buckets[13]['ev']);
        $this->assertSame('10 Nov', $buckets[13]['lab']);
        $this->assertSame('Paper Boats, chapter 2 went up · Paper Boats, chapter 3 went up', $buckets[23]['ev']);
        $this->assertSame('20 Nov', $buckets[23]['lab']);
        $this->assertNull($buckets[24]['ev']);
    }

    public function test_the_works_are_ranked_by_reads_and_leave_out_the_private_and_the_unread(): void
    {
        $novel = Work::factory()->novel()->public()->create(['title' => 'Paper Boats', 'slug' => 'paper-boats']);
        $poem = Work::factory()->poem()->public()->create(['title' => 'Harmattan', 'slug' => 'harmattan']);
        Work::factory()->poem()->public()->create(['title' => 'Quiet']);
        $private = Work::factory()->novel()->create(['title' => 'Secret', 'visibility' => Visibility::Private]);

        $this->stat('2026-11-26', StatSubject::Work, 60, 25, 10, $novel->id, $novel);
        $this->stat('2026-11-19', StatSubject::Work, 40, 20, 5, $novel->id, $novel);
        $this->stat('2026-11-25', StatSubject::Work, 45, 15, 15, $poem->id, $poem);
        $this->stat('2026-11-25', StatSubject::Work, 999, 999, 999, $private->id, $private);

        $works = StatsPage::props('7')['works'];

        $this->assertSame(['paper-boats', 'harmattan'], array_column($works, 'slug'));
        $this->assertSame([60, 45], array_column($works, 'reads'));
        $this->assertSame([40, 0], array_column($works, 'before'));
        $this->assertSame([0, 0, 0, 0, 0, 0, 60], $works[0]['series']['reads']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 25], $works[0]['series']['readers']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 10], $works[0]['series']['finished']);

        $this->assertNull(StatsPage::props('all')['works'][0]['before']);
    }

    public function test_a_multi_chapter_novel_lists_its_chapters_newest_first_with_finished_shares_and_a_keep_curve(): void
    {
        $work = Work::factory()->novel()->public()->create(['title' => 'Paper Boats', 'slug' => 'paper-boats']);
        $this->item($work, 1, 'The first', '2026-10-01 09:00');
        $this->item($work, 2, 'The second', '2026-10-08 09:00');
        $this->item($work, 3, 'The third', '2026-10-15 09:00');
        $this->item($work, 4, 'Still a draft', '2026-10-22 09:00', PublishStatus::Draft);

        $this->stat('2026-11-25', StatSubject::Work, 100, 40, 26, $work->id, $work);
        $this->stat('2026-11-25', StatSubject::Chapter, 80, 30, 21, $work->items()->where('position', 1)->value('id'), $work);
        $this->stat('2026-11-25', StatSubject::Chapter, 20, 10, 5, $work->items()->where('position', 2)->value('id'), $work);

        $row = StatsPage::props('7')['works'][0];

        $this->assertTrue($row['multi']);
        $this->assertSame([
            ['no' => 3, 'title' => 'The third', 'reads' => 0, 'fin' => 0],
            ['no' => 2, 'title' => 'The second', 'reads' => 20, 'fin' => 25],
            ['no' => 1, 'title' => 'The first', 'reads' => 80, 'fin' => 26],
        ], $row['chapters']);
        $this->assertSame([100.0, 33.3, 0.0], $row['keep']);
    }

    public function test_a_collection_lists_its_pieces_but_has_no_keep_curve_and_is_labelled_by_its_kind(): void
    {
        $collection = Work::factory()->collection(CollectionKind::Poem)->public()->create(['title' => 'Midnight poems', 'slug' => 'midnight-poems']);
        $this->item($collection, 1, 'One', '2026-10-01 09:00', type: WorkItemType::Piece);
        $this->item($collection, 2, 'Two', '2026-10-08 09:00', type: WorkItemType::Piece);
        $this->stat('2026-11-25', StatSubject::Work, 30, 20, 10, $collection->id, $collection);

        $row = StatsPage::props('7')['works'][0];

        $this->assertSame('Poetry collection', $row['kind']);
        $this->assertTrue($row['multi']);
        $this->assertNull($row['keep']);
        $this->assertSame([2, 1], array_column($row['chapters'], 'no'));
    }

    public function test_a_single_piece_has_no_chapter_list_or_keep_curve_and_its_cover_and_counts_come_from_the_work(): void
    {
        $poem = Work::factory()->poem()->public()->create([
            'title' => 'Harmattan',
            'slug' => 'harmattan',
            'cover_color' => '#C24E77',
        ]);
        $poem->forceFill(['likes_count' => 12])->save();
        $poem->follows()->forceCreate(['user_id' => User::factory()->reader()->create()->id]);
        $this->stat('2026-11-25', StatSubject::Work, 45, 15, 15, $poem->id, $poem);

        $row = StatsPage::props('7')['works'][0];

        $this->assertSame('Poem', $row['kind']);
        $this->assertFalse($row['multi']);
        $this->assertNull($row['keep']);
        $this->assertNull($row['chapters']);
        $this->assertSame(['src' => null, 'color' => '#C24E77', 'dark' => false], $row['cover']);
        $this->assertSame(12, $row['likes']);
        $this->assertSame(1, $row['follows']);
    }

    public function test_notes_and_pages_sum_their_rows_over_the_range(): void
    {
        $this->stat('2026-11-25', StatSubject::Note, 30, 15, 0, 101);
        $this->stat('2026-11-24', StatSubject::Note, 10, 5, 0, 102);
        $this->stat('2026-11-24', StatSubject::Note, 0, 0, 0, 103);
        $this->stat('2026-11-25', StatSubject::Page, 54);
        $this->stat('2026-11-01', StatSubject::Page, 900);

        $props = StatsPage::props('7');

        $this->assertSame(['reads' => 40, 'count' => 2], $props['notes']);
        $this->assertSame(['visits' => 54], $props['pages']);
    }

    public function test_sources_keep_their_labels_and_the_named_ones_get_icon_keys(): void
    {
        $this->breakdown('2026-11-25', StatDimension::Source, 'Direct', 60);
        $this->breakdown('2026-11-24', StatDimension::Source, 'Instagram', 25);
        $this->breakdown('2026-11-25', StatDimension::Source, 'substack.com', 5);
        $this->breakdown('2026-10-01', StatDimension::Source, 'Google', 500);
        $this->breakdown('2026-11-25', StatDimension::Country, 'NG', 70);

        $this->assertSame([
            ['key' => 'direct', 'label' => 'Direct', 'v' => 60],
            ['key' => 'ig', 'label' => 'Instagram', 'v' => 25],
            ['key' => 'link', 'label' => 'substack.com', 'v' => 5],
        ], StatsPage::props('7')['sources']);
    }

    public function test_countries_are_named_in_english_and_ranked_by_reads(): void
    {
        $this->breakdown('2026-11-25', StatDimension::Country, 'GB', 30);
        $this->breakdown('2026-11-25', StatDimension::Country, 'NG', 70);

        $this->assertSame([
            ['code' => 'ng', 'name' => 'Nigeria', 'v' => 70],
            ['code' => 'gb', 'name' => 'United Kingdom', 'v' => 30],
        ], StatsPage::props('7')['countries']);
    }

    public function test_the_phone_share_is_rounded_and_null_without_device_rows(): void
    {
        $this->assertNull(StatsPage::props('7')['phone']);

        $this->breakdown('2026-11-25', StatDimension::Device, 'phone', 2);
        $this->breakdown('2026-11-25', StatDimension::Device, 'computer', 1);

        $this->assertSame(67, StatsPage::props('7')['phone']);
    }

    public function test_empty_stays_true_until_a_work_has_been_read_and_all_time_shows_only_this_month_with_no_stats(): void
    {
        $this->assertTrue(StatsPage::props('30')['empty']);
        $this->assertSame(
            [['lab' => 'Nov', 'tip' => 'November 2026, so far', 'ev' => null, 'y' => 2026]],
            StatsPage::props('all')['chart']['buckets'],
        );

        $work = Work::factory()->poem()->public()->create();
        $this->stat('2026-11-25', StatSubject::Work, 5, 5, 5, $work->id, $work);

        $this->assertFalse(StatsPage::props('30')['empty']);
    }
}
