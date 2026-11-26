<?php

namespace Tests\Feature\Admin;

use App\Enums\CollectionKind;
use App\Enums\PublishStatus;
use App\Enums\Visibility;
use App\Enums\WorkItemType;
use App\Enums\WorkKind;
use App\Enums\WorkStatus;
use App\Models\Genre;
use App\Models\Media;
use App\Models\Series;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminWorksTest extends TestCase
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

    public function test_guests_are_sent_to_the_sign_in_for_the_works_list_and_for_a_work_update(): void
    {
        Work::factory()->create(['slug' => 'the-tide-house', 'title' => 'The Tide House', 'title_key' => 'the tide house']);

        $this->get($this->adminUrl('/works'))->assertRedirect(route('admin.signin'));

        $this->patch($this->adminUrl('/works/the-tide-house'), ['title' => 'Something Else'])->assertRedirect(route('admin.signin'));

        $this->assertDatabaseHas('works', ['slug' => 'the-tide-house', 'title' => 'The Tide House']);
    }

    public function test_an_author_without_the_admin_sign_in_is_sent_to_the_sign_in_for_the_works_list(): void
    {
        $this->actingAs($this->author)->get($this->adminUrl('/works'))->assertRedirect(route('admin.signin'));
    }

    public function test_the_works_list_has_one_row_per_work_with_its_facts_and_the_most_recently_edited_first(): void
    {
        $older = Work::factory()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);
        WorkItem::factory()->for($older)->create(['draft_words' => 560, 'draft_saved_at' => now()->subDays(3)]);

        $newer = Work::factory()->create(['title' => 'The Ember Gate', 'title_key' => 'the ember gate', 'slug' => 'the-ember-gate']);
        WorkItem::factory()->for($newer)->create(['draft_words' => 560, 'draft_saved_at' => now()->subHour()]);

        $response = $this->asAdmin()->get($this->adminUrl('/works'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Works')->has('works', 2));

        $rows = $response->inertiaProps('works');

        $this->assertSame(['The Ember Gate', 'The Tide House'], array_column($rows, 'title'));
        $this->assertSame('Novel', $rows[0]['sub']);
        $this->assertSame('Draft', $rows[0]['status']['text']);
        $this->assertSame('Private', $rows[0]['visibility']['text']);
        $this->assertSame('1 chapter · 2 pages', $rows[0]['length']);
        $this->assertIsString($rows[0]['edited']);
        $this->assertSame('/works/the-ember-gate', $rows[0]['href']);
    }

    public function test_a_work_reads_as_draft_coming_soon_published_or_finished(): void
    {
        Work::factory()->create(['title' => 'Unwritten Tide', 'title_key' => 'unwritten tide', 'slug' => 'unwritten-tide']);
        Work::factory()->comingSoon()->create(['title' => 'Coming Tide', 'title_key' => 'coming tide', 'slug' => 'coming-tide']);
        Work::factory()->poem()->published()->create(['title' => 'Salt Poem', 'title_key' => 'salt poem', 'slug' => 'salt-poem']);
        Work::factory()->novel()->published()->create([
            'title' => 'Finished Tide', 'title_key' => 'finished tide', 'slug' => 'finished-tide', 'status' => WorkStatus::Finished,
        ]);

        $rows = collect($this->asAdmin()->get($this->adminUrl('/works'))->assertOk()->inertiaProps('works'))->keyBy('slug');

        $this->assertSame('Draft', $rows['unwritten-tide']['status']['text']);
        $this->assertSame('Coming soon', $rows['coming-tide']['status']['text']);
        $this->assertSame('Published', $rows['salt-poem']['status']['text']);
        $this->assertSame('Finished', $rows['finished-tide']['status']['text']);
    }

    public function test_a_work_page_opens_on_settings_with_its_series_choices(): void
    {
        Work::factory()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);
        Series::factory()->create(['name' => 'The Harbour Books', 'name_key' => 'the harbour books']);

        $this->asAdmin()->get($this->adminUrl('/works/the-tide-house'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Work')
                ->where('tab', 'settings')
                ->where('work.title', 'The Tide House')
                ->has('choices.series', 1));
    }

    public function test_a_work_page_opens_on_the_tab_it_asks_for_and_an_unknown_work_is_not_found(): void
    {
        Work::factory()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);

        $this->asAdmin()->get($this->adminUrl('/works/the-tide-house?tab=plan'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Work')->where('tab', 'plan'));

        $this->asAdmin()->get($this->adminUrl('/works/no-such-work'))->assertNotFound();
    }

    public function test_the_title_check_ignores_case_and_spacing_and_skips_the_work_being_renamed(): void
    {
        Work::factory()->create(['title' => 'The Ember Gate', 'title_key' => 'the ember gate', 'slug' => 'the-ember-gate']);

        $this->asAdmin()->getJson($this->adminUrl('/works/title-taken?title='.rawurlencode('  the   EMBER gate ')))
            ->assertOk()
            ->assertExactJson(['taken' => ['title' => 'The Ember Gate', 'href' => '/works/the-ember-gate?tab=settings']]);

        $this->asAdmin()->getJson($this->adminUrl('/works/title-taken?title='.rawurlencode('  the   EMBER gate ').'&except=the-ember-gate'))
            ->assertOk()
            ->assertExactJson(['taken' => null]);
    }

    public function test_new_work_makes_a_private_in_progress_novel_with_one_draft_chapter_and_opens_its_first_chapter(): void
    {
        $this->asAdmin()->post($this->adminUrl('/works'), ['title' => '  The   Ember Gate ', 'kind' => 'novel'])
            ->assertRedirect($this->adminUrl('/works/the-ember-gate/write/'.WorkItem::query()->sole()->id));

        $work = Work::query()->where('slug', 'the-ember-gate')->sole();
        $this->assertSame('The Ember Gate', $work->title);
        $this->assertSame('the ember gate', $work->title_key);
        $this->assertSame(WorkKind::Novel, $work->kind);
        $this->assertSame(WorkStatus::InProgress, $work->status);
        $this->assertSame(Visibility::Private, $work->visibility);
        $this->assertNull($work->series_id);

        $item = $work->items()->sole();
        $this->assertSame(WorkItemType::Chapter, $item->type);
        $this->assertSame(PublishStatus::Draft, $item->status);
        $this->assertSame(1, $item->position);
    }

    public function test_a_new_novel_in_a_series_takes_the_next_place_in_it(): void
    {
        $series = Series::factory()->create(['name' => 'The Harbour Books', 'name_key' => 'the harbour books', 'slug' => 'the-harbour-books']);
        Work::factory()->novel()->create(['series_id' => $series->id, 'series_position' => 1]);
        Work::factory()->novel()->create(['series_id' => $series->id, 'series_position' => 2]);

        $this->asAdmin()->post($this->adminUrl('/works'), ['title' => 'Book Three', 'kind' => 'novel', 'series_id' => $series->id]);

        $work = Work::query()->where('slug', 'book-three')->sole();
        $this->assertSame($series->id, $work->series_id);
        $this->assertSame(3, $work->series_position);
    }

    public function test_new_work_with_a_taken_title_fails_on_the_title_and_creates_nothing(): void
    {
        Work::factory()->create(['title' => 'The Ember Gate', 'title_key' => 'the ember gate', 'slug' => 'the-ember-gate']);

        $this->asAdmin()->postJson($this->adminUrl('/works'), ['title' => 'the EMBER gate', 'kind' => 'novel'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title' => 'You already have a work called The Ember Gate.']);

        $this->assertDatabaseCount('works', 1);
        $this->assertDatabaseCount('work_items', 0);
    }

    public function test_new_poem_with_a_new_collection_joins_it_in_a_collection_of_poems(): void
    {
        $this->asAdmin()->post($this->adminUrl('/works'), ['title' => 'Salt Hours', 'kind' => 'poem', 'new_collection' => 'Night Poems'])
            ->assertRedirect();

        $poem = Work::query()->where('slug', 'salt-hours')->sole();
        $collection = Work::query()->where('slug', 'night-poems')->sole();

        $this->assertSame(WorkKind::Collection, $collection->kind);
        $this->assertSame(CollectionKind::Poem, $collection->collection_kind);
        $this->assertSame($collection->id, $poem->collection_id);

        $piece = $collection->items()->sole();
        $this->assertSame(WorkItemType::Piece, $piece->type);
        $this->assertSame($poem->id, $piece->piece_work_id);
    }

    public function test_renaming_a_published_work_moves_its_address_and_keeps_the_old_one_in_slug_history(): void
    {
        $work = Work::factory()->published()->create([
            'title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house',
        ]);

        $this->asAdmin()->patchJson($this->adminUrl('/works/the-tide-house'), ['title' => 'The Ember Gate'])
            ->assertOk()
            ->assertJsonPath('slug', 'the-ember-gate')
            ->assertJsonPath('href', '/works/the-ember-gate');

        $work->refresh();
        $this->assertSame('The Ember Gate', $work->title);
        $this->assertSame('the ember gate', $work->title_key);
        $this->assertSame('the-ember-gate', $work->slug);

        $this->assertDatabaseHas('slug_history', [
            'sluggable_type' => (new Work)->getMorphClass(),
            'sluggable_id' => $work->id,
            'slug' => 'the-tide-house',
        ]);
    }

    public function test_renaming_a_work_that_was_never_published_leaves_no_slug_history(): void
    {
        $work = Work::factory()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);

        $this->asAdmin()->patchJson($this->adminUrl('/works/the-tide-house'), ['title' => 'The Ember Gate'])
            ->assertOk()
            ->assertJsonPath('slug', 'the-ember-gate');

        $this->assertSame('the-ember-gate', $work->fresh()->slug);
        $this->assertDatabaseCount('slug_history', 0);
    }

    public function test_renaming_to_another_works_title_is_refused_on_the_title(): void
    {
        Work::factory()->create(['title' => 'The Ember Gate', 'title_key' => 'the ember gate', 'slug' => 'the-ember-gate']);
        $work = Work::factory()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);

        $this->asAdmin()->patchJson($this->adminUrl('/works/the-tide-house'), ['title' => 'the EMBER gate'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title' => 'You already have a work called The Ember Gate.']);

        $this->assertSame('The Tide House', $work->fresh()->title);
    }

    public function test_genres_sync_to_the_two_she_names_and_a_third_is_refused(): void
    {
        Genre::query()->create(['name' => 'Fantasy', 'position' => 1]);
        $work = Work::factory()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);

        $this->asAdmin()->patchJson($this->adminUrl('/works/the-tide-house'), ['genres' => ['Fantasy', 'Gothic']])->assertOk();

        $this->assertSame(['Fantasy', 'Gothic'], $work->genres()->orderBy('name')->pluck('name')->all());
        $this->assertDatabaseCount('genres', 2);

        $this->asAdmin()->patchJson($this->adminUrl('/works/the-tide-house'), ['genres' => ['Fantasy', 'Gothic', 'Horror']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['genres' => 'Two genres at most.']);
    }

    public function test_going_private_takes_the_work_away_from_its_reviewers(): void
    {
        $reviewer = User::factory()->reviewer()->create();
        $work = Work::factory()->reviewers()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);
        $work->reviewers()->attach($reviewer->id);

        $this->asAdmin()->patchJson($this->adminUrl('/works/the-tide-house'), ['visibility' => 'private'])->assertOk();

        $this->assertSame(Visibility::Private, $work->fresh()->visibility);
        $this->assertDatabaseMissing('work_reviewer', ['work_id' => $work->id]);
    }

    public function test_a_novel_joins_a_series_and_leaves_it_again_with_no_place(): void
    {
        $series = Series::factory()->create(['name' => 'The Harbour Books', 'name_key' => 'the harbour books']);
        $work = Work::factory()->novel()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);

        $this->asAdmin()->patchJson($this->adminUrl('/works/the-tide-house'), ['series_id' => $series->id])->assertOk();

        $work->refresh();
        $this->assertSame($series->id, $work->series_id);
        $this->assertSame(1, $work->series_position);

        $this->asAdmin()->patchJson($this->adminUrl('/works/the-tide-house'), ['series_id' => null])->assertOk();

        $work->refresh();
        $this->assertNull($work->series_id);
        $this->assertNull($work->series_position);
    }

    public function test_changing_a_novel_to_a_poem_takes_it_out_of_its_series(): void
    {
        $series = Series::factory()->create(['name' => 'The Harbour Books', 'name_key' => 'the harbour books']);
        $work = Work::factory()->novel()->create([
            'title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house',
            'series_id' => $series->id, 'series_position' => 2,
        ]);

        $this->asAdmin()->patchJson($this->adminUrl('/works/the-tide-house'), ['kind' => 'poem'])->assertOk();

        $work->refresh();
        $this->assertSame(WorkKind::Poem, $work->kind);
        $this->assertNull($work->series_id);
        $this->assertNull($work->series_position);
    }

    public function test_a_reviewer_is_given_the_work_and_a_private_work_opens_to_reviewers_then_the_reviewer_is_taken_off(): void
    {
        $reviewer = User::factory()->reviewer()->create();
        $work = Work::factory()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);

        $this->asAdmin()->postJson($this->adminUrl('/works/the-tide-house/reviewers'), ['user_id' => $reviewer->id])
            ->assertOk()
            ->assertJsonPath('visibility', 'reviewers')
            ->assertJsonCount(1, 'reviewers');

        $this->assertDatabaseHas('work_reviewer', ['work_id' => $work->id, 'user_id' => $reviewer->id]);
        $this->assertSame(Visibility::Reviewers, $work->fresh()->visibility);

        $this->asAdmin()->deleteJson($this->adminUrl('/works/the-tide-house/reviewers/user/'.$reviewer->id))
            ->assertOk()
            ->assertJsonCount(0, 'reviewers');

        $this->assertDatabaseMissing('work_reviewer', ['work_id' => $work->id, 'user_id' => $reviewer->id]);
    }

    public function test_someone_who_is_not_a_reviewer_cannot_be_given_a_work(): void
    {
        $author = User::factory()->author()->create();
        $work = Work::factory()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);

        $this->asAdmin()->postJson($this->adminUrl('/works/the-tide-house/reviewers'), ['user_id' => $author->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);

        $this->assertDatabaseMissing('work_reviewer', ['work_id' => $work->id]);
        $this->assertSame(Visibility::Private, $work->fresh()->visibility);
    }

    public function test_a_cover_is_stored_as_a_webp_no_taller_than_1800_and_removing_it_deletes_the_file(): void
    {
        Storage::fake(config('eyiwetemi.media_disk'));
        $work = Work::factory()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);

        $this->asAdmin()->postJson($this->adminUrl('/works/the-tide-house/cover'), [
            'cover' => UploadedFile::fake()->image('c.jpg', 2400, 3600),
        ])->assertOk();

        $media = Media::query()->sole();
        $this->assertStringStartsWith('covers/', $media->path);
        $this->assertStringEndsWith('.webp', $media->path);
        $this->assertSame('image/webp', $media->mime);
        $this->assertLessThanOrEqual(1800, $media->height);
        $this->assertSame(1200, $media->width);
        $this->assertSame(1800, $media->height);
        Storage::disk($media->disk)->assertExists($media->path);

        $this->assertSame($media->id, $work->fresh()->cover_media_id);

        $this->asAdmin()->deleteJson($this->adminUrl('/works/the-tide-house/cover'))->assertOk();

        $this->assertNull($work->fresh()->cover_media_id);
        Storage::disk($media->disk)->assertMissing($media->path);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_a_series_is_made_on_the_spot_and_a_duplicate_name_in_other_case_is_refused(): void
    {
        $this->asAdmin()->postJson($this->adminUrl('/series'), ['name' => 'The Tide House'])
            ->assertCreated()
            ->assertJsonPath('series.name', 'The Tide House')
            ->assertJsonPath('series.books', 0);

        $this->assertDatabaseHas('series', ['name' => 'The Tide House', 'name_key' => 'the tide house', 'slug' => 'the-tide-house']);

        $this->asAdmin()->postJson($this->adminUrl('/series'), ['name' => 'the TIDE house'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'You already have a series called The Tide House.']);

        $this->assertDatabaseCount('series', 1);
    }

    public function test_renaming_a_series_moves_its_address_and_keeps_the_old_one_in_slug_history(): void
    {
        $series = Series::factory()->create(['name' => 'The Tide House', 'name_key' => 'the tide house', 'slug' => 'the-tide-house']);

        $this->asAdmin()->patchJson($this->adminUrl('/series/'.$series->id), ['name' => 'The Harbour Books'])
            ->assertOk()
            ->assertJsonPath('series.name', 'The Harbour Books');

        $this->assertDatabaseHas('series', ['id' => $series->id, 'slug' => 'the-harbour-books', 'name_key' => 'the harbour books']);
        $this->assertDatabaseHas('slug_history', [
            'sluggable_type' => (new Series)->getMorphClass(),
            'sluggable_id' => $series->id,
            'slug' => 'the-tide-house',
        ]);
    }

    public function test_deleting_a_work_removes_it_with_its_chapters_and_goes_back_to_the_list(): void
    {
        $work = Work::factory()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);
        $item = WorkItem::factory()->for($work)->create();

        $this->asAdmin()->delete($this->adminUrl('/works/the-tide-house'))->assertRedirect($this->adminUrl('/works'));

        $this->assertDatabaseMissing('works', ['id' => $work->id]);
        $this->assertDatabaseMissing('work_items', ['id' => $item->id]);
    }
}
