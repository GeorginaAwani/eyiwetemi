<?php

namespace Tests\Feature\Admin;

use App\Models\Character;
use App\Models\SweepName;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use App\Models\WorldEntry;
use App\Models\WorldGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSweepTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->author()->create();
    }

    private function asAdmin(): static
    {
        return $this->actingAs($this->author)->withSession(['admin.verified' => true]);
    }

    private function url(Work $work, string $path): string
    {
        return 'http://'.config('eyiwetemi.admin_domain').'/works/'.$work->slug.$path;
    }

    private function novel(): Work
    {
        return Work::factory()->novel()->create(['title' => 'Plum Rain', 'title_key' => 'plum rain', 'slug' => 'plum-rain']);
    }

    public function test_the_names_list_has_the_plans_characters_and_places_then_names_added_by_hand(): void
    {
        $work = $this->novel();
        Character::factory()->for($work)->create(['name' => 'Xú Wēi', 'typed_as' => 'Xu Wei', 'position' => 1]);
        $group = WorldGroup::factory()->for($work)->create(['name' => 'Places', 'position' => 1]);
        WorldEntry::factory()->for($group, 'group')->create(['name' => 'Píngjiāng', 'typed_as' => null, 'position' => 1]);

        $this->asAdmin()->postJson($this->url($work, '/sweep/names'), ['plain' => 'Li', 'readings' => 'Lǐ|Lí'])
            ->assertCreated()
            ->assertJsonPath('names.0.plain', 'Xu Wei')
            ->assertJsonPath('names.0.readings', ['Xú Wēi'])
            ->assertJsonPath('names.0.from', 'c:'.Character::query()->first()->id)
            ->assertJsonPath('names.1.plain', 'Pingjiang')
            ->assertJsonPath('names.2.plain', 'Li')
            ->assertJsonPath('names.2.readings', ['Lǐ', 'Lí'])
            ->assertJsonPath('names.2.from', null);
    }

    public function test_adding_a_name_that_is_there_changes_its_readings_and_a_name_can_be_removed(): void
    {
        $work = $this->novel();
        $this->asAdmin()->postJson($this->url($work, '/sweep/names'), ['plain' => 'Mei yu', 'readings' => 'Měiyù'])->assertCreated();
        $this->asAdmin()->postJson($this->url($work, '/sweep/names'), ['plain' => 'mei  yu', 'readings' => 'Méiyǔ'])->assertCreated();

        $this->assertSame(1, SweepName::query()->count());
        $name = SweepName::query()->first();
        $this->assertSame(['Méiyǔ'], $name->readings);

        $this->asAdmin()->deleteJson($this->url($work, '/sweep/names/'.$name->id))->assertOk()->assertJsonCount(0, 'names');
    }

    public function test_another_works_name_cannot_be_removed(): void
    {
        $work = $this->novel();
        $other = Work::factory()->novel()->create(['title' => 'Other', 'title_key' => 'other', 'slug' => 'other']);
        $name = SweepName::query()->create(['work_id' => $other->id, 'plain' => 'Ada', 'readings' => ['Ádá'], 'position' => 1]);

        $this->asAdmin()->deleteJson($this->url($work, '/sweep/names/'.$name->id))->assertNotFound();
    }

    public function test_marking_names_while_writing_is_switched_per_work(): void
    {
        $work = $this->novel();

        $this->asAdmin()->patchJson($this->url($work, '/sweep/mark'), ['on' => true])->assertOk()->assertJsonPath('on', true);
        $this->assertTrue($work->fresh()->mark_names);
    }

    public function test_the_whole_book_pages_are_its_chapters_drafts_in_order(): void
    {
        $work = $this->novel();
        WorkItem::factory()->part()->for($work)->create(['position' => 1]);
        $one = WorkItem::factory()->for($work)->create(['position' => 2, 'draft_body' => ['type' => 'doc', 'content' => []]]);
        $two = WorkItem::factory()->for($work)->create(['position' => 3, 'draft_body' => null, 'live_body' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]]);

        $this->asAdmin()->getJson($this->url($work, '/sweep/pages'))
            ->assertOk()
            ->assertJsonPath('pages.0.id', $one->id)
            ->assertJsonPath('pages.0.number', 1)
            ->assertJsonPath('pages.1.id', $two->id)
            ->assertJsonPath('pages.1.body.content.0.type', 'paragraph');
    }

    public function test_the_writing_screen_carries_the_plan_and_the_names(): void
    {
        $work = $this->novel();
        $item = WorkItem::factory()->for($work)->create(['position' => 1]);

        $this->asAdmin()->get($this->url($work, '/write/'.$item->id))
            ->assertInertia(fn ($page) => $page->component('Admin/Write')->has('plan.sections')->has('sweep.names')->where('sweep.mark', false));
    }

    public function test_guests_cannot_reach_sweep(): void
    {
        $work = $this->novel();

        $this->getJson($this->url($work, '/sweep/pages'))->assertUnauthorized();
    }
}
