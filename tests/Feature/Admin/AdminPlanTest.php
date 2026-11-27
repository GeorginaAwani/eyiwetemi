<?php

namespace Tests\Feature\Admin;

use App\Models\Character;
use App\Models\CharacterLink;
use App\Models\Media;
use App\Models\PlotPoint;
use App\Models\PlotThread;
use App\Models\ResearchItem;
use App\Models\ResearchTie;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use App\Models\WorldEntry;
use App\Models\WorldGroup;
use App\Support\PlanPage;
use App\Support\WorkPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminPlanTest extends TestCase
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

    private function planUrl(Work $work, string $path = ''): string
    {
        return $this->adminUrl('/works/'.$work->slug.'/plan'.$path);
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
     * @param  array<string, mixed>  $attributes
     */
    private function chapter(Work $work, string $text, array $attributes = []): WorkItem
    {
        return WorkItem::factory()->for($work)->create(array_merge([
            'title' => 'Chapter',
            'draft_text' => $text,
            'live_text' => null,
            'draft_words' => count(explode(' ', $text)),
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function character(Work $work, string $name, array $attributes = []): Character
    {
        return Character::factory()->for($work)->create(array_merge([
            'name' => $name,
            'typed_as' => $name,
            'role' => null,
            'aliases' => null,
            'notes' => null,
        ], $attributes));
    }

    private function group(Work $work, string $name, int $position): WorldGroup
    {
        return WorldGroup::factory()->for($work)->create(['name' => $name, 'position' => $position]);
    }

    public function test_a_novels_plan_has_its_sections_and_its_characters_know_their_pov_and_where_they_appear(): void
    {
        $work = $this->novel();
        WorkItem::factory()->part()->for($work)->create(['position' => 1, 'title' => 'Part One']);
        $first = $this->chapter($work, 'Ifẹ́oma counted the lanterns from the bridge.', ['position' => 2, 'title' => 'The Lanterns']);
        $second = $this->chapter($work, 'The river was low and nobody came.', ['position' => 3, 'title' => 'Still Water']);
        $ifeoma = $this->character($work, 'Ifẹ́oma', ['typed_as' => 'Ifeoma', 'position' => 1]);
        $first->update(['pov_character_id' => $ifeoma->id]);

        $plan = PlanPage::props($work);

        $this->assertSame(['ch', 'plot', 'world', 'res'], $plan['sections']);
        $this->assertSame('Ifẹ́oma', $plan['chars'][0]['name']);
        $this->assertSame('Ifeoma', $plan['chars'][0]['typedAs']);
        $this->assertSame([$first->id], $plan['chars'][0]['pov']);
        $this->assertSame([$first->id], $plan['chars'][0]['in']);
        $this->assertSame([
            ['id' => $first->id, 'number' => 1, 'title' => 'The Lanterns', 'part' => 'Part One', 'partNumber' => 1, 'written' => true],
            ['id' => $second->id, 'number' => 2, 'title' => 'Still Water', 'part' => 'Part One', 'partNumber' => 1, 'written' => true],
        ], $plan['chapters']);
    }

    public function test_a_character_is_only_found_where_its_whole_name_is_said(): void
    {
        $work = $this->novel();
        $this->chapter($work, 'Adaeze waited at the gate.', ['position' => 1]);
        $ada = $this->chapter($work, 'Ada waited at the gate.', ['position' => 2]);
        $this->character($work, 'Ada', ['typed_as' => 'Ada']);

        $this->assertSame([$ada->id], PlanPage::props($work)['chars'][0]['in']);
    }

    public function test_an_essays_plan_has_only_notes_and_research(): void
    {
        $essay = Work::factory()->essay()->create([
            'title' => 'On Rain',
            'title_key' => 'on rain',
            'slug' => 'on-rain',
        ]);

        $plan = PlanPage::props($essay);

        $this->assertSame(['notes', 'res'], $plan['sections']);
        $this->assertSame('', $plan['notes']);
        $this->assertSame([], $plan['chars']);
    }

    public function test_a_poem_has_no_plan_and_the_work_page_says_so(): void
    {
        $poem = Work::factory()->poem()->create([
            'title' => 'Harmattan',
            'title_key' => 'harmattan',
            'slug' => 'harmattan',
        ]);

        $this->assertSame([], PlanPage::props($poem)['sections']);

        $this->asAdmin()->get($this->adminUrl('/works/harmattan?tab=plan'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('plan', null));
    }

    public function test_the_work_page_carries_the_plan_for_a_novel(): void
    {
        $work = $this->novel();

        $this->asAdmin()->get($this->adminUrl('/works/'.$work->slug.'?tab=plan'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('plan.sections', ['ch', 'plot', 'world', 'res']));
    }

    public function test_a_character_is_added_with_its_typed_form_folded_from_its_name(): void
    {
        $work = $this->novel();

        $this->asAdmin()->postJson($this->planUrl($work, '/characters'), ['name' => 'Xú Wēi'])
            ->assertCreated()
            ->assertJsonPath('plan.chars.0.name', 'Xú Wēi')
            ->assertJsonPath('plan.chars.0.typedAs', 'Xu Wei');

        $this->assertDatabaseHas('characters', ['work_id' => $work->id, 'name' => 'Xú Wēi', 'typed_as' => 'Xu Wei', 'position' => 1]);
    }

    public function test_a_character_can_be_updated_and_its_notes_are_cleaned_of_scripts_and_attributes(): void
    {
        $work = $this->novel();
        $character = $this->character($work, 'Dayo');

        $this->asAdmin()->patchJson($this->planUrl($work, '/characters/'.$character->id), [
            'name' => 'Dayo Ajayi',
            'typedAs' => 'Dayo',
            'aliases' => ['Day', ' ', 'Day'],
            'role' => 'Narrator',
            'notes' => '<p onclick="steal()">Quiet, <b>careful</b></p><script>alert(1)</script><ul><li>one</li></ul>',
        ])
            ->assertOk()
            ->assertJsonPath('plan.chars.0.name', 'Dayo Ajayi')
            ->assertJsonPath('plan.chars.0.aliases', ['Day'])
            ->assertJsonPath('plan.chars.0.role', 'Narrator')
            ->assertJsonPath('plan.chars.0.notes', '<p>Quiet, <b>careful</b></p><ul><li>one</li></ul>');

        $this->assertSame(['html' => '<p>Quiet, <b>careful</b></p><ul><li>one</li></ul>'], $character->fresh()->notes);
    }

    public function test_deleting_a_character_clears_its_points_of_view_and_its_research_ties(): void
    {
        $work = $this->novel();
        $character = $this->character($work, 'Dayo');
        $chapter = $this->chapter($work, 'Dayo rowed.', ['pov_character_id' => $character->id]);
        $research = ResearchItem::factory()->for($work)->create(['title' => 'Rowing']);
        $research->ties()->create(['tieable_type' => PlanPage::tieTypes()['c'], 'tieable_id' => $character->id]);

        $this->asAdmin()->deleteJson($this->planUrl($work, '/characters/'.$character->id))
            ->assertOk()
            ->assertJsonCount(0, 'plan.chars');

        $this->assertNull($chapter->fresh()->pov_character_id);
        $this->assertSame(0, ResearchTie::query()->count());
    }

    public function test_a_link_is_kept_both_ways_and_a_repeat_is_ignored(): void
    {
        $work = $this->novel();
        $dayo = $this->character($work, 'Dayo', ['position' => 1]);
        $ifeoma = $this->character($work, 'Ifeoma', ['position' => 2]);

        $this->asAdmin()->postJson($this->planUrl($work, '/links'), [
            'character' => $dayo->id,
            'to' => $ifeoma->id,
            'label' => 'sister',
        ])
            ->assertCreated()
            ->assertJsonPath('plan.chars.0.links.0.to', $ifeoma->id)
            ->assertJsonPath('plan.chars.0.links.0.label', 'sister')
            ->assertJsonPath('plan.chars.1.links.0.to', $dayo->id)
            ->assertJsonPath('plan.chars.1.links.0.label', 'sister');

        $this->asAdmin()->postJson($this->planUrl($work, '/links'), [
            'character' => $dayo->id,
            'to' => $ifeoma->id,
            'label' => 'friend',
        ])->assertCreated();

        $this->assertSame(2, CharacterLink::query()->count());
    }

    public function test_a_link_can_be_relabelled_and_removed_in_both_directions(): void
    {
        $work = $this->novel();
        $dayo = $this->character($work, 'Dayo', ['position' => 1]);
        $ifeoma = $this->character($work, 'Ifeoma', ['position' => 2]);

        $this->asAdmin()->postJson($this->planUrl($work, '/links'), [
            'character' => $dayo->id,
            'to' => $ifeoma->id,
            'label' => 'sister',
        ])->assertCreated();

        $link = CharacterLink::query()->where('character_id', $dayo->id)->sole();

        $this->asAdmin()->patchJson($this->planUrl($work, '/links/'.$link->id), ['label' => 'twin'])
            ->assertOk()
            ->assertJsonPath('plan.chars.0.links.0.label', 'twin')
            ->assertJsonPath('plan.chars.1.links.0.label', 'twin');

        $this->asAdmin()->deleteJson($this->planUrl($work, '/links/'.$link->id))
            ->assertOk()
            ->assertJsonCount(0, 'plan.chars.0.links')
            ->assertJsonCount(0, 'plan.chars.1.links');

        $this->assertSame(0, CharacterLink::query()->count());
    }

    public function test_a_character_cannot_be_linked_to_itself_or_to_someone_in_another_work(): void
    {
        $work = $this->novel();
        $dayo = $this->character($work, 'Dayo');
        $stranger = $this->character($this->novel('Other Tide'), 'Stranger');

        $this->asAdmin()->postJson($this->planUrl($work, '/links'), ['character' => $dayo->id, 'to' => $dayo->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);

        $this->asAdmin()->postJson($this->planUrl($work, '/links'), ['character' => $dayo->id, 'to' => $stranger->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);

        $this->assertSame(0, CharacterLink::query()->count());
    }

    public function test_a_world_group_is_added_renamed_and_its_entries_move_to_the_chosen_group_when_it_is_deleted(): void
    {
        $work = $this->novel();
        $places = $this->group($work, 'Places', 1);
        $people = $this->group($work, 'Peoples', 2);
        $river = WorldEntry::factory()->create(['world_group_id' => $places->id, 'name' => 'River', 'position' => 1]);

        $this->asAdmin()->postJson($this->planUrl($work, '/groups'), ['name' => 'Things'])
            ->assertCreated()
            ->assertJsonPath('plan.groups.2.name', 'Things');

        $this->asAdmin()->patchJson($this->planUrl($work, '/groups/'.$people->id), ['name' => 'People'])
            ->assertOk()
            ->assertJsonPath('plan.groups.1.name', 'People');

        $this->asAdmin()->deleteJson($this->planUrl($work, '/groups/'.$places->id), ['move_to' => $people->id])
            ->assertOk()
            ->assertJsonPath('plan.groups.0.name', 'People')
            ->assertJsonPath('plan.groups.0.entries.0.name', 'River');

        $this->assertSame($people->id, $river->fresh()->world_group_id);
    }

    public function test_deleting_a_world_group_without_a_choice_sends_its_entries_to_the_first_group_left(): void
    {
        $work = $this->novel();
        $places = $this->group($work, 'Places', 1);
        $people = $this->group($work, 'People', 2);
        WorldEntry::factory()->create(['world_group_id' => $places->id, 'name' => 'Ferry', 'position' => 1]);

        $this->asAdmin()->deleteJson($this->planUrl($work, '/groups/'.$places->id))
            ->assertOk()
            ->assertJsonPath('plan.groups.0.id', $people->id)
            ->assertJsonPath('plan.groups.0.entries.0.name', 'Ferry');
    }

    public function test_the_last_world_group_cannot_be_deleted(): void
    {
        $work = $this->novel();
        $group = $this->group($work, 'Places', 1);

        $this->asAdmin()->deleteJson($this->planUrl($work, '/groups/'.$group->id))
            ->assertUnprocessable()
            ->assertJsonPath('errors.group.0', 'Keep at least one group.');

        $this->assertDatabaseHas('world_groups', ['id' => $group->id]);
    }

    public function test_world_entries_are_added_edited_moved_and_removed(): void
    {
        $work = $this->novel();
        $places = $this->group($work, 'Places', 1);
        $people = $this->group($work, 'People', 2);

        $this->asAdmin()->postJson($this->planUrl($work, '/entries'), ['group' => $places->id, 'name' => 'Píngjiāng'])
            ->assertCreated()
            ->assertJsonPath('plan.groups.0.entries.0.name', 'Píngjiāng')
            ->assertJsonPath('plan.groups.0.entries.0.typedAs', 'Pingjiang');

        $entry = WorldEntry::query()->sole();

        $this->asAdmin()->patchJson($this->planUrl($work, '/entries/'.$entry->id), [
            'description' => 'River town, the ferry',
            'notes' => '<p>Ferry at dawn</p>',
            'group' => $people->id,
        ])
            ->assertOk()
            ->assertJsonPath('plan.groups.1.entries.0.description', 'River town, the ferry')
            ->assertJsonPath('plan.groups.1.entries.0.notes', '<p>Ferry at dawn</p>')
            ->assertJsonPath('plan.groups.0.entries', []);

        $this->asAdmin()->deleteJson($this->planUrl($work, '/entries/'.$entry->id))
            ->assertOk()
            ->assertJsonPath('plan.groups.1.entries', []);

        $this->assertDatabaseMissing('world_entries', ['id' => $entry->id]);
    }

    public function test_plot_threads_take_colours_in_turn_and_can_be_renamed_and_recoloured(): void
    {
        $work = $this->novel();

        $this->asAdmin()->postJson($this->planUrl($work, '/threads'), ['name' => 'Main'])
            ->assertCreated()
            ->assertJsonPath('plan.threads.0.colour', 0);

        $this->asAdmin()->postJson($this->planUrl($work, '/threads'), ['name' => 'Romance'])
            ->assertCreated()
            ->assertJsonPath('plan.threads.1.colour', 1);

        $romance = PlotThread::query()->where('name', 'Romance')->sole();

        $this->asAdmin()->patchJson($this->planUrl($work, '/threads/'.$romance->id), ['name' => 'Love', 'colour' => 4])
            ->assertOk()
            ->assertJsonPath('plan.threads.1.name', 'Love')
            ->assertJsonPath('plan.threads.1.colour', 4);
    }

    public function test_deleting_a_plot_thread_moves_its_points_and_the_last_thread_is_kept(): void
    {
        $work = $this->novel();
        $main = PlotThread::factory()->for($work)->create(['name' => 'Main', 'colour' => 0, 'position' => 1]);
        $side = PlotThread::factory()->for($work)->create(['name' => 'Side', 'colour' => 1, 'position' => 2]);
        $point = PlotPoint::query()->create(['plot_thread_id' => $side->id, 'text' => 'The ferry sinks', 'position' => 1]);

        $this->asAdmin()->deleteJson($this->planUrl($work, '/threads/'.$side->id))
            ->assertOk()
            ->assertJsonCount(1, 'plan.threads')
            ->assertJsonPath('plan.points.0.thread', $main->id);

        $this->assertSame($main->id, $point->fresh()->plot_thread_id);

        $this->asAdmin()->deleteJson($this->planUrl($work, '/threads/'.$main->id))
            ->assertUnprocessable()
            ->assertJsonPath('errors.thread.0', 'Keep at least one thread.');

        $this->assertDatabaseHas('plot_threads', ['id' => $main->id]);
    }

    public function test_plot_points_are_added_edited_and_removed_and_must_belong_to_the_work(): void
    {
        $work = $this->novel();
        $thread = PlotThread::factory()->for($work)->create(['name' => 'Main', 'position' => 1]);
        $chapter = $this->chapter($work, 'Dayo finds the ferry.');
        $otherThread = PlotThread::factory()->for($this->novel('Other Tide'))->create(['name' => 'Elsewhere']);

        $this->asAdmin()->postJson($this->planUrl($work, '/points'), [
            'thread' => $thread->id,
            'item' => $chapter->id,
            'text' => 'Dayo finds the ferry',
        ])
            ->assertCreated()
            ->assertJsonPath('plan.points.0.thread', $thread->id)
            ->assertJsonPath('plan.points.0.item', $chapter->id)
            ->assertJsonPath('plan.points.0.text', 'Dayo finds the ferry');

        $point = PlotPoint::query()->sole();

        $this->asAdmin()->patchJson($this->planUrl($work, '/points/'.$point->id), ['item' => null, 'text' => 'Dayo loses the ferry'])
            ->assertOk()
            ->assertJsonPath('plan.points.0.item', null)
            ->assertJsonPath('plan.points.0.text', 'Dayo loses the ferry');

        $this->asAdmin()->postJson($this->planUrl($work, '/points'), [
            'thread' => $otherThread->id,
            'text' => 'Not ours',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['thread']);

        $this->asAdmin()->deleteJson($this->planUrl($work, '/points/'.$point->id))
            ->assertOk()
            ->assertJsonCount(0, 'plan.points');
    }

    public function test_a_link_without_a_title_takes_the_page_title(): void
    {
        Http::fake(['https://example.org/*' => Http::response("<html><head><title> The River &amp;\n the Ferry </title></head></html>", 200)]);
        $work = $this->novel();

        $this->asAdmin()->postJson($this->planUrl($work, '/research'), ['kind' => 'link', 'url' => 'https://example.org/river'])
            ->assertCreated()
            ->assertJsonPath('plan.research.0.kind', 'link')
            ->assertJsonPath('plan.research.0.title', 'The River & the Ferry')
            ->assertJsonPath('plan.research.0.url', 'https://example.org/river');

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://example.org/river');
    }

    public function test_a_link_to_this_machine_or_a_private_network_is_never_fetched(): void
    {
        Http::fake();
        $work = $this->novel();

        $this->asAdmin()->postJson($this->planUrl($work, '/research'), ['kind' => 'link', 'url' => 'http://localhost/admin'])
            ->assertCreated()
            ->assertJsonPath('plan.research.0.title', 'localhost');
        $this->asAdmin()->postJson($this->planUrl($work, '/research'), ['kind' => 'link', 'url' => 'http://192.168.1.1/'])
            ->assertCreated();

        Http::assertNothingSent();
    }

    public function test_a_link_whose_page_cannot_be_read_is_titled_with_its_host(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));
        $work = $this->novel();

        $this->asAdmin()->postJson($this->planUrl($work, '/research'), ['kind' => 'link', 'url' => 'https://example.org/river'])
            ->assertCreated()
            ->assertJsonPath('plan.research.0.title', 'example.org');
    }

    public function test_a_picture_is_kept_as_research_with_its_media(): void
    {
        Storage::fake(config('eyiwetemi.media_disk'));
        $work = $this->novel();

        $this->asAdmin()->postJson($this->planUrl($work, '/research'), [
            'kind' => 'img',
            'file' => UploadedFile::fake()->image('harbour.jpg', 3000, 2000),
        ])
            ->assertCreated()
            ->assertJsonPath('plan.research.0.kind', 'img')
            ->assertJsonPath('plan.research.0.title', 'harbour');

        $media = Media::query()->sole();

        $this->assertStringStartsWith('research/', $media->path);
        $this->assertStringEndsWith('.webp', $media->path);
        $this->assertSame(2000, $media->width);
        Storage::disk($media->disk)->assertExists($media->path);
        $this->assertSame(WorkPresenter::mediaUrl($media->disk, $media->path), PlanPage::props($work)['research'][0]['src']);
        $this->assertSame($media->size, PlanPage::props($work)['research'][0]['size']);
    }

    public function test_a_pdf_is_kept_as_it_is_under_research(): void
    {
        Storage::fake(config('eyiwetemi.media_disk'));
        $work = $this->novel();

        $this->asAdmin()->postJson($this->planUrl($work, '/research'), [
            'kind' => 'file',
            'file' => UploadedFile::fake()->create('ferry-timetable.pdf', 120, 'application/pdf'),
        ])
            ->assertCreated()
            ->assertJsonPath('plan.research.0.kind', 'file')
            ->assertJsonPath('plan.research.0.title', 'ferry-timetable');

        $media = Media::query()->sole();

        $this->assertStringStartsWith('research/', $media->path);
        $this->assertStringEndsWith('.pdf', $media->path);
        $this->assertSame('application/pdf', $media->mime);
        Storage::disk($media->disk)->assertExists($media->path);
        $this->asAdmin()->postJson($this->planUrl($work, '/research'), ['kind' => 'file'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_research_ties_follow_this_works_characters_entries_threads_and_items_and_refuse_another_works(): void
    {
        $work = $this->novel();
        $character = $this->character($work, 'Dayo');
        $group = $this->group($work, 'Places', 1);
        $entry = WorldEntry::factory()->create(['world_group_id' => $group->id, 'name' => 'Ferry']);
        $thread = PlotThread::factory()->for($work)->create(['name' => 'Main']);
        $chapter = $this->chapter($work, 'Dayo crossed on the ferry.');
        $research = ResearchItem::factory()->for($work)->note()->create(['title' => 'Ferry notes']);
        $stranger = $this->character($this->novel('Other Tide'), 'Stranger');

        $ties = ['c:'.$character->id, 'e:'.$entry->id, 't:'.$thread->id, 'i:'.$chapter->id];

        $this->asAdmin()->patchJson($this->planUrl($work, '/research/'.$research->id), ['ties' => $ties])
            ->assertOk()
            ->assertJsonPath('plan.research.0.ties', $ties);

        $this->asAdmin()->patchJson($this->planUrl($work, '/research/'.$research->id), ['ties' => ['c:'.$stranger->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ties']);

        $this->asAdmin()->patchJson($this->planUrl($work, '/research/'.$research->id), ['ties' => ['x:1']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ties.0']);

        $this->assertSame(4, ResearchTie::query()->count());
    }

    public function test_research_can_be_edited_and_deleted_with_its_uploaded_file(): void
    {
        Storage::fake(config('eyiwetemi.media_disk'));
        $work = $this->novel();
        Storage::disk('public')->put('research/harbour.webp', 'picture');
        $media = Media::factory()->create(['disk' => 'public', 'path' => 'research/harbour.webp', 'mime' => 'image/webp']);
        $research = ResearchItem::factory()->for($work)->image()->create(['title' => 'Harbour', 'media_id' => $media->id]);

        $this->asAdmin()->patchJson($this->planUrl($work, '/research/'.$research->id), ['title' => 'Harbour at dusk', 'note' => 'Taken in 1970'])
            ->assertOk()
            ->assertJsonPath('plan.research.0.title', 'Harbour at dusk')
            ->assertJsonPath('plan.research.0.note', 'Taken in 1970');

        $this->asAdmin()->deleteJson($this->planUrl($work, '/research/'.$research->id))
            ->assertOk()
            ->assertJsonCount(0, 'plan.research');

        Storage::disk('public')->assertMissing('research/harbour.webp');
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        $this->assertDatabaseMissing('research_items', ['id' => $research->id]);
    }

    public function test_deleting_the_work_deletes_its_research_uploads(): void
    {
        Storage::fake(config('eyiwetemi.media_disk'));
        $work = $this->novel();
        Storage::disk('public')->put('research/notes.pdf', 'pdf');
        $media = Media::factory()->create(['disk' => 'public', 'path' => 'research/notes.pdf', 'mime' => 'application/pdf']);
        ResearchItem::factory()->for($work)->image()->create(['title' => 'Notes', 'media_id' => $media->id]);

        $this->asAdmin()->delete($this->adminUrl('/works/'.$work->slug))->assertRedirect();

        Storage::disk('public')->assertMissing('research/notes.pdf');
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_the_plans_notes_are_saved_cleaned(): void
    {
        $work = $this->novel();

        $this->asAdmin()->patchJson($this->planUrl($work, '/notes'), [
            'html' => '<p>Keep <a href="x">this</a> <script>alert(1)</script><span onclick="go()">open</span></p>',
        ])
            ->assertOk()
            ->assertJsonPath('plan.notes', '<p>Keep this open</p>');

        $this->assertSame(['html' => '<p>Keep this open</p>'], $work->fresh()->plan_notes);
    }

    public function test_another_works_character_is_not_found(): void
    {
        $work = $this->novel();
        $stranger = $this->character($this->novel('Other Tide'), 'Stranger');

        $this->asAdmin()->patchJson($this->planUrl($work, '/characters/'.$stranger->id), ['name' => 'Changed'])
            ->assertNotFound();

        $this->asAdmin()->deleteJson($this->planUrl($work, '/characters/'.$stranger->id))
            ->assertNotFound();

        $this->assertDatabaseHas('characters', ['id' => $stranger->id, 'name' => 'Stranger']);
    }

    public function test_guests_are_sent_to_the_sign_in_and_nothing_is_saved(): void
    {
        $work = $this->novel();

        $this->post($this->planUrl($work, '/characters'), ['name' => 'Nobody'])
            ->assertRedirect(route('admin.signin'));

        $this->assertDatabaseMissing('characters', ['name' => 'Nobody']);
    }
}
