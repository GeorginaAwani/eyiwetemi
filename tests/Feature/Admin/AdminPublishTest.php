<?php

namespace Tests\Feature\Admin;

use App\Enums\CollectionKind;
use App\Enums\PublishStatus;
use App\Enums\VersionLabel;
use App\Enums\WorkItemType;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use App\Support\Prose;
use App\Support\WorkPresenter;
use App\Support\WorksAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminPublishTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->author()->create();
    }

    private function adminUrl(string $path): string
    {
        return 'http://'.config('eyiwetemi.admin_domain').$path;
    }

    /**
     * The author, signed in on the admin host after the admin's own sign-in.
     */
    private function asAdmin(): static
    {
        return $this->actingAs($this->author)->withSession(['admin.verified' => true]);
    }

    private function writeUrl(WorkItem $item, ?Work $work = null): string
    {
        $work ??= $item->work;

        return $this->adminUrl('/works/'.$work->slug.'/write/'.$item->id);
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private function doc(array $content): array
    {
        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * @return array<string, mixed>
     */
    private function paragraph(string $text, ?string $id = 'p1'): array
    {
        $node = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];

        if ($id !== null) {
            $node['attrs'] = ['id' => $id];
        }

        return $node;
    }

    private function novel(string $title = 'The Tide House'): Work
    {
        return Work::factory()->novel()->create([
            'title' => $title,
            'title_key' => Str::lower($title),
            'slug' => Str::slug($title),
        ]);
    }

    /**
     * A chapter whose draft is one paragraph of $text.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function draftChapter(Work $work, string $text, array $attributes = []): WorkItem
    {
        return WorkItem::factory()->for($work)->create($attributes + [
            'draft_body' => $this->doc([$this->paragraph($text)]),
            'draft_text' => $text,
            'draft_words' => count(explode(' ', $text)),
        ]);
    }

    public function test_publishing_a_draft_chapter_puts_its_text_out_and_keeps_a_published_version(): void
    {
        $work = $this->novel();
        $item = $this->draftChapter($work, 'The tide came in slowly');
        $body = $item->draft_body;

        $this->asAdmin()->postJson($this->writeUrl($item).'/publish')
            ->assertOk()
            ->assertJsonPath('state', 'pub')
            ->assertJsonCount(1, 'versions')
            ->assertJsonPath('versions.0.label', 'Published')
            ->assertJsonPath('versions.0.words', 5);

        $item->refresh();
        $this->assertSame(PublishStatus::Published, $item->status);
        $this->assertEquals($body, $item->live_body);
        $this->assertSame('The tide came in slowly', $item->live_text);
        $this->assertSame(5, $item->live_words);
        $this->assertNotNull($item->published_at);
        $this->assertNotNull($item->live_updated_at);
        $this->assertNull($item->scheduled_at);
        $this->assertNotNull($work->fresh()->first_published_at);

        $version = $item->versions()->sole();
        $this->assertSame(VersionLabel::Published, $version->label);
        $this->assertEquals($body, $version->body);
        $this->assertSame(5, $version->words);
    }

    public function test_publishing_again_after_an_edit_keeps_an_updated_version_with_the_words_changed_and_the_first_publish_time(): void
    {
        $work = $this->novel();
        $item = $this->draftChapter($work, 'The house stood on the cliff above the sea');
        $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Africa/Lagos'));

        $this->asAdmin()->postJson($this->writeUrl($item).'/publish')->assertOk();

        $item->refresh();
        $firstPublishedAt = $item->published_at;
        $firstLiveUpdatedAt = $item->live_updated_at;

        $this->travel(1)->hours();
        $item->update([
            'draft_body' => $this->doc([$this->paragraph('The house stood on the cliff at dusk')]),
            'draft_text' => 'The house stood on the cliff at dusk',
            'draft_words' => 8,
        ]);

        $this->asAdmin()->get($this->writeUrl($item))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Write')->where('publish.state', 'chg'));

        $this->asAdmin()->postJson($this->writeUrl($item).'/publish')
            ->assertOk()
            ->assertJsonPath('state', 'pub')
            ->assertJsonCount(2, 'versions')
            ->assertJsonPath('versions.0.label', 'Updated')
            ->assertJsonPath('versions.0.words', 8)
            ->assertJsonPath('versions.0.added', 2)
            ->assertJsonPath('versions.0.cut', 3);

        $item->refresh();
        $this->assertTrue($item->published_at->equalTo($firstPublishedAt));
        $this->assertTrue($item->live_updated_at->greaterThan($firstLiveUpdatedAt));
        $this->assertEquals($item->draft_body, $item->live_body);
    }

    public function test_publishing_an_empty_draft_is_refused_and_changes_nothing(): void
    {
        $work = $this->novel();
        $item = WorkItem::factory()->for($work)->create([
            'draft_body' => Prose::empty(),
            'draft_text' => '',
            'draft_words' => 0,
        ]);

        $this->asAdmin()->postJson($this->writeUrl($item).'/publish')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);

        $item->refresh();
        $this->assertSame(PublishStatus::Draft, $item->status);
        $this->assertNull($item->live_body);
        $this->assertNull($item->published_at);
        $this->assertSame(0, $item->versions()->count());
        $this->assertNull($work->fresh()->first_published_at);
    }

    public function test_publishing_a_part_sets_it_published_and_writes_no_version(): void
    {
        $work = $this->novel();
        $part = WorkItem::factory()->part()->for($work)->create(['position' => 1]);

        $this->asAdmin()->postJson($this->writeUrl($part).'/publish')
            ->assertOk()
            ->assertJsonPath('state', 'pub')
            ->assertJsonCount(0, 'versions');

        $part->refresh();
        $this->assertSame(PublishStatus::Published, $part->status);
        $this->assertNotNull($part->published_at);
        $this->assertNull($part->live_body);
        $this->assertSame(0, $part->versions()->count());
    }

    public function test_scheduling_a_chapter_for_a_wat_day_and_time_stores_it_in_utc_and_marks_it_scheduled(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Africa/Lagos'));
        $work = $this->novel();
        $item = $this->draftChapter($work, 'The tide came in slowly');

        $this->asAdmin()->postJson($this->writeUrl($item).'/schedule', ['day' => '2026-11-27', 'time' => '18:00'])
            ->assertOk()
            ->assertJsonPath('state', 'sch')
            ->assertJsonPath('scheduledAt', '2026-11-27T17:00:00+00:00');

        $item->refresh();
        $this->assertSame(PublishStatus::Scheduled, $item->status);
        $this->assertSame('2026-11-27 17:00:00', $item->scheduled_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($item->published_at);
    }

    public function test_a_time_that_has_already_passed_cannot_be_scheduled(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Africa/Lagos'));
        $work = $this->novel();
        $item = $this->draftChapter($work, 'The tide came in slowly');

        $this->asAdmin()->postJson($this->writeUrl($item).'/schedule', ['day' => '2026-11-26', 'time' => '09:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['day']);

        $item->refresh();
        $this->assertSame(PublishStatus::Draft, $item->status);
        $this->assertNull($item->scheduled_at);
    }

    public function test_a_published_chapter_cannot_be_scheduled(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Africa/Lagos'));
        $work = $this->novel();
        $item = $this->draftChapter($work, 'The tide came in slowly');

        $this->asAdmin()->postJson($this->writeUrl($item).'/publish')->assertOk();

        $this->asAdmin()->postJson($this->writeUrl($item).'/schedule', ['day' => '2026-11-27', 'time' => '18:00'])
            ->assertStatus(422);

        $item->refresh();
        $this->assertSame(PublishStatus::Published, $item->status);
        $this->assertNull($item->scheduled_at);
    }

    public function test_unscheduling_returns_a_scheduled_chapter_to_a_draft(): void
    {
        $work = $this->novel();
        $item = $this->draftChapter($work, 'The tide came in slowly', [
            'status' => PublishStatus::Scheduled,
            'scheduled_at' => now()->addDays(3),
        ]);

        $this->asAdmin()->deleteJson($this->writeUrl($item).'/schedule')
            ->assertOk()
            ->assertJsonPath('state', 'draft');

        $item->refresh();
        $this->assertSame(PublishStatus::Draft, $item->status);
        $this->assertNull($item->scheduled_at);
    }

    public function test_the_publish_due_command_publishes_what_is_due_and_leaves_later_items_scheduled(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-26 18:00:00', 'Africa/Lagos'));
        $work = $this->novel();

        $due = $this->draftChapter($work, 'The tide came in slowly', [
            'position' => 1,
            'status' => PublishStatus::Scheduled,
            'scheduled_at' => CarbonImmutable::parse('2026-11-26 17:59:00', 'Africa/Lagos')->utc(),
        ]);
        $later = $this->draftChapter($work, 'The gulls came back', [
            'position' => 2,
            'status' => PublishStatus::Scheduled,
            'scheduled_at' => CarbonImmutable::parse('2026-11-27 18:00:00', 'Africa/Lagos')->utc(),
        ]);

        $this->artisan('eyiwetemi:publish-due')
            ->expectsOutput('Published 1.')
            ->assertSuccessful();

        $due->refresh();
        $this->assertSame(PublishStatus::Published, $due->status);
        $this->assertEquals($due->draft_body, $due->live_body);
        $this->assertSame('The tide came in slowly', $due->live_text);
        $this->assertNotNull($due->published_at);

        $later->refresh();
        $this->assertSame(PublishStatus::Scheduled, $later->status);
        $this->assertNull($later->live_body);
    }

    public function test_unpublishing_a_chapter_takes_it_back_to_a_draft_keeps_an_unpublished_version_and_drops_it_from_the_reader_count(): void
    {
        $work = $this->novel();
        $item = $this->draftChapter($work, 'The tide came in slowly');
        $this->asAdmin()->postJson($this->writeUrl($item).'/publish')->assertOk();

        $this->assertCount(1, WorkPresenter::numbered($work->fresh()));

        $this->asAdmin()->postJson($this->writeUrl($item).'/unpublish')
            ->assertOk()
            ->assertJsonPath('state', 'unpub')
            ->assertJsonPath('versions.0.label', 'Unpublished');

        $item->refresh();
        $this->assertSame(PublishStatus::Unpublished, $item->status);
        $this->assertNull($item->scheduled_at);
        $this->assertEquals($item->live_body, $item->versions()->where('label', VersionLabel::Unpublished->value)->sole()->body);
        $this->assertCount(0, WorkPresenter::numbered($work->fresh()));
    }

    public function test_a_version_opens_with_its_body_and_a_version_of_another_chapter_is_not_found(): void
    {
        $work = $this->novel();
        $item = $this->draftChapter($work, 'The tide came in slowly');
        $body = $item->draft_body;
        $version = $item->versions()->create([
            'label' => VersionLabel::Published,
            'body' => $body,
            'words' => 5,
            'words_added' => 0,
            'words_cut' => 0,
            'created_by' => null,
        ]);

        $this->asAdmin()->getJson($this->writeUrl($item).'/versions/'.$version->id)
            ->assertOk()
            ->assertExactJson(['body' => $body]);

        $other = $this->draftChapter($work, 'Another chapter entirely');
        $otherVersion = $other->versions()->create([
            'label' => VersionLabel::Published,
            'body' => $other->draft_body,
            'words' => 3,
            'words_added' => 0,
            'words_cut' => 0,
            'created_by' => null,
        ]);

        $this->asAdmin()->getJson($this->writeUrl($item).'/versions/'.$otherVersion->id)->assertNotFound();
    }

    public function test_restoring_a_version_makes_it_the_draft_and_keeps_the_draft_it_replaces_as_a_version(): void
    {
        $work = $this->novel();
        $item = $this->draftChapter($work, 'The current draft is here', ['draft_revision' => 4]);
        $currentBody = $item->draft_body;

        $olderBody = $this->doc([$this->paragraph('The older draft from last week')]);
        $version = $item->versions()->create([
            'label' => VersionLabel::Updated,
            'body' => $olderBody,
            'words' => 6,
            'words_added' => 0,
            'words_cut' => 0,
            'created_by' => null,
        ]);

        $response = $this->asAdmin()->postJson($this->writeUrl($item).'/versions/'.$version->id.'/restore')
            ->assertOk();

        $this->assertEquals($olderBody, $response->json('body'));

        $item->refresh();
        $this->assertEquals($olderBody, $item->draft_body);
        $this->assertSame('The older draft from last week', $item->draft_text);
        $this->assertSame(5, $item->draft_revision);

        $kept = $item->versions()->where('label', VersionLabel::DraftBeforeRestore->value)->sole();
        $this->assertEquals($currentBody, $kept->body);
    }

    public function test_a_new_chapter_on_a_novel_goes_at_the_end_and_opens_its_write_page(): void
    {
        $work = $this->novel();
        WorkItem::factory()->for($work)->create(['position' => 1]);
        WorkItem::factory()->for($work)->create(['position' => 2]);
        WorkItem::factory()->for($work)->create(['position' => 3]);

        $response = $this->asAdmin()->post($this->adminUrl('/works/'.$work->slug.'/items'), ['type' => 'chapter']);

        $new = WorkItem::query()->where('work_id', $work->id)->where('position', 4)->sole();
        $response->assertRedirect($this->writeUrl($new));
        $this->assertSame(WorkItemType::Chapter, $new->type);
        $this->assertSame(PublishStatus::Draft, $new->status);
        $this->assertNotNull($new->draft_body);
        $this->assertSame(4, WorkItem::query()->where('work_id', $work->id)->count());
    }

    public function test_a_new_chapter_placed_after_a_chapter_sits_right_after_it_and_later_chapters_move_down_one(): void
    {
        $work = $this->novel();
        $first = WorkItem::factory()->for($work)->create(['position' => 1]);
        $second = WorkItem::factory()->for($work)->create(['position' => 2]);
        $third = WorkItem::factory()->for($work)->create(['position' => 3]);

        $response = $this->asAdmin()->post($this->adminUrl('/works/'.$work->slug.'/items'), ['type' => 'chapter', 'after' => $first->id]);

        $new = WorkItem::query()->where('work_id', $work->id)->where('position', 2)->sole();
        $response->assertRedirect($this->writeUrl($new));
        $this->assertNotSame($first->id, $new->id);

        $this->assertSame(1, $first->fresh()->position);
        $this->assertSame(3, $second->fresh()->position);
        $this->assertSame(4, $third->fresh()->position);
    }

    public function test_a_part_cannot_be_added_to_a_collection(): void
    {
        $collection = Work::factory()->collection(CollectionKind::Poem)->create([
            'title' => 'Night Poems', 'title_key' => 'night poems', 'slug' => 'night-poems',
        ]);

        $this->asAdmin()->post($this->adminUrl('/works/night-poems/items'), ['type' => 'part'])
            ->assertStatus(422);

        $this->assertSame(0, $collection->items()->count());
    }

    public function test_reordering_chapters_rewrites_their_positions_one_to_n(): void
    {
        $work = $this->novel();
        $a = WorkItem::factory()->for($work)->create(['position' => 1]);
        $b = WorkItem::factory()->for($work)->create(['position' => 2]);
        $c = WorkItem::factory()->for($work)->create(['position' => 3]);

        $this->asAdmin()->patchJson($this->adminUrl('/works/'.$work->slug.'/items/order'), ['ids' => [$c->id, $a->id, $b->id]])
            ->assertOk()
            ->assertJsonCount(3, 'rows');

        $this->assertSame(1, $c->fresh()->position);
        $this->assertSame(2, $a->fresh()->position);
        $this->assertSame(3, $b->fresh()->position);
    }

    public function test_a_reorder_list_missing_an_id_is_refused_and_changes_nothing(): void
    {
        $work = $this->novel();
        $a = WorkItem::factory()->for($work)->create(['position' => 1]);
        $b = WorkItem::factory()->for($work)->create(['position' => 2]);
        $c = WorkItem::factory()->for($work)->create(['position' => 3]);

        $this->asAdmin()->patchJson($this->adminUrl('/works/'.$work->slug.'/items/order'), ['ids' => [$c->id, $a->id]])
            ->assertStatus(422);

        $this->assertSame(1, $a->fresh()->position);
        $this->assertSame(2, $b->fresh()->position);
        $this->assertSame(3, $c->fresh()->position);
    }

    public function test_borrowing_a_poem_adds_a_piece_to_a_poem_collection_and_a_story_is_refused(): void
    {
        $collection = Work::factory()->collection(CollectionKind::Poem)->create([
            'title' => 'Night Poems', 'title_key' => 'night poems', 'slug' => 'night-poems',
        ]);
        $poem = Work::factory()->poem()->create(['title' => 'Salt Poem', 'title_key' => 'salt poem', 'slug' => 'salt-poem']);
        $story = Work::factory()->story()->create(['title' => 'The Lighthouse', 'title_key' => 'the lighthouse', 'slug' => 'the-lighthouse']);

        $this->asAdmin()->postJson($this->adminUrl('/works/night-poems/items/borrow'), ['works' => [$poem->id]])
            ->assertOk()
            ->assertJsonCount(1, 'rows');

        $piece = WorkItem::query()->where('work_id', $collection->id)->sole();
        $this->assertSame(WorkItemType::Piece, $piece->type);
        $this->assertSame($poem->id, $piece->piece_work_id);
        $this->assertSame($collection->id, $poem->fresh()->collection_id);

        $this->asAdmin()->postJson($this->adminUrl('/works/night-poems/items/borrow'), ['works' => [$story->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['works.0']);

        $this->assertNull($story->fresh()->collection_id);
        $this->assertSame(1, $collection->items()->count());
    }

    public function test_taking_a_borrowed_piece_out_removes_it_clears_its_collection_and_closes_the_position_gap(): void
    {
        $collection = Work::factory()->collection(CollectionKind::Poem)->create([
            'title' => 'Night Poems', 'title_key' => 'night poems', 'slug' => 'night-poems',
        ]);
        $first = Work::factory()->poem()->create(['title' => 'Salt Poem', 'title_key' => 'salt poem', 'slug' => 'salt-poem']);
        $second = Work::factory()->poem()->create(['title' => 'Tide Poem', 'title_key' => 'tide poem', 'slug' => 'tide-poem']);

        $this->asAdmin()->postJson($this->adminUrl('/works/night-poems/items/borrow'), ['works' => [$first->id]])->assertOk();
        $this->asAdmin()->postJson($this->adminUrl('/works/night-poems/items/borrow'), ['works' => [$second->id]])->assertOk();

        $firstPiece = WorkItem::query()->where('piece_work_id', $first->id)->sole();
        $secondPiece = WorkItem::query()->where('piece_work_id', $second->id)->sole();

        $this->asAdmin()->deleteJson($this->adminUrl('/works/night-poems/items/'.$firstPiece->id))
            ->assertOk()
            ->assertJsonCount(1, 'rows');

        $this->assertModelMissing($firstPiece);
        $this->assertNull($first->fresh()->collection_id);
        $this->assertSame($collection->id, $second->fresh()->collection_id);
        $this->assertSame(1, $secondPiece->fresh()->position);
    }

    public function test_the_opening_quote_page_opens_for_a_novel_and_is_not_found_for_a_poem(): void
    {
        $novel = $this->novel();

        $this->asAdmin()->get($this->adminUrl('/works/the-tide-house/opening'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Opening')
                ->where('work.title', 'The Tide House')
                ->where('quote.q', '')
                ->where('quote.by', ''));

        Work::factory()->poem()->create(['title' => 'Salt Poem', 'title_key' => 'salt poem', 'slug' => 'salt-poem']);

        $this->asAdmin()->get($this->adminUrl('/works/salt-poem/opening'))->assertNotFound();
        $this->asAdmin()->patchJson($this->adminUrl('/works/salt-poem/opening'), ['q' => 'Nope', 'by' => 'Nobody'])->assertNotFound();

        $this->assertNull(Work::query()->where('slug', 'salt-poem')->value('opening_quote'));
        $this->assertNull($novel->fresh()->opening_quote);
    }

    public function test_saving_the_opening_quote_trims_it_and_an_empty_quote_clears_both_fields(): void
    {
        $novel = $this->novel();

        $this->asAdmin()->patchJson($this->adminUrl('/works/the-tide-house/opening'), [
            'q' => '  The tide keeps its own hours.  ',
            'by' => '  Someone Else  ',
        ])
            ->assertOk()
            ->assertJsonPath('saved', true);

        $novel->refresh();
        $this->assertSame('The tide keeps its own hours.', $novel->opening_quote);
        $this->assertSame('Someone Else', $novel->opening_quote_by);

        $this->asAdmin()->patchJson($this->adminUrl('/works/the-tide-house/opening'), ['q' => '   ', 'by' => ''])
            ->assertOk();

        $novel->refresh();
        $this->assertNull($novel->opening_quote);
        $this->assertNull($novel->opening_quote_by);
    }

    public function test_the_chapters_tab_numbers_parts_among_parts_and_chapters_among_chapters_with_their_state_and_pages(): void
    {
        $work = $this->novel();
        WorkItem::factory()->part()->for($work)->create(['position' => 1]);
        $this->draftChapter($work, implode(' ', array_fill(0, 560, 'word')), ['position' => 2]);
        $this->draftChapter($work, 'The gulls came back', ['position' => 3]);
        WorkItem::factory()->part()->for($work)->create(['position' => 4]);
        $this->draftChapter($work, 'The tide went out', ['position' => 5]);

        $rows = $this->asAdmin()->get($this->adminUrl('/works/the-tide-house?tab=chapters'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Work')
                ->where('tab', 'chapters')
                ->has('contents.rows', 5))
            ->inertiaProps('contents')['rows'];

        $this->assertSame(['part', 'chapter', 'chapter', 'part', 'chapter'], array_column($rows, 'type'));
        $this->assertSame([1, 1, 2, 2, 3], array_column($rows, 'number'));
        $this->assertSame(['draft', 'draft', 'draft', 'draft', 'draft'], array_column($rows, 'state'));
        $this->assertSame([WorksAdmin::PART_PAGES, 2, 1, WorksAdmin::PART_PAGES, 1], array_column($rows, 'pages'));
    }

    public function test_guests_are_sent_to_the_sign_in_for_the_publish_post_and_nothing_goes_out(): void
    {
        $work = $this->novel();
        $item = $this->draftChapter($work, 'The tide came in slowly');

        $this->post($this->writeUrl($item).'/publish')->assertRedirect(route('admin.signin'));

        $item->refresh();
        $this->assertSame(PublishStatus::Draft, $item->status);
        $this->assertNull($item->live_body);
        $this->assertSame(0, $item->versions()->count());
    }
}
