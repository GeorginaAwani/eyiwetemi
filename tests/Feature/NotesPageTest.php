<?php

namespace Tests\Feature;

use App\Models\Like;
use App\Models\Note;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotesPageTest extends TestCase
{
    use RefreshDatabase;

    private function note(string $at, array $attributes = []): Note
    {
        return Note::factory()->published()->create(['published_at' => Carbon::parse($at, 'Africa/Lagos'), ...$attributes]);
    }

    public function test_notes_are_grouped_by_month_newest_first(): void
    {
        $this->travelTo(Carbon::parse('2026-11-26 12:00', 'Africa/Lagos'));
        $work = Work::factory()->novel()->public()->published()->create(['title' => 'Salt Season']);
        $hidden = Work::factory()->novel()->create(['title' => 'Secret', 'visibility' => 'private']);
        $salt = $this->note('2026-11-21 09:00', [
            'work_id' => $work->id,
            'live_body' => ['type' => 'doc', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'In print.'], ['type' => 'text', 'text' => ' Finally.', 'marks' => [['type' => 'italic']]]]],
                ['type' => 'paragraph'],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Thank you.']]],
            ]],
        ]);
        Like::factory()->count(2)->create(['likeable_type' => 'note', 'likeable_id' => $salt->id]);
        $this->note('2026-11-01 00:30', ['work_id' => $hidden->id]);
        $this->note('2026-10-31 23:30');
        Note::factory()->create();
        Note::factory()->scheduled()->create();

        $this->get('/notes')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Notes')
            ->count('months', 2)
            ->where('months.0.name', 'November')
            ->where('months.0.year', 2026)
            ->count('months.0.notes', 2)
            ->where('months.0.notes.0.id', $salt->id)
            ->where('months.0.notes.0.day', '21 Nov')
            ->where('months.0.notes.0.paragraphs', [[['text' => 'In print.'], ['text' => ' Finally.', 'em' => true]], [['text' => 'Thank you.']]])
            ->where('months.0.notes.0.work', ['title' => 'Salt Season', 'href' => '/library/'.$work->slug])
            ->where('months.0.notes.0.likes', 2)
            ->where('months.0.notes.1.work', null)
            ->where('months.1.name', 'October')
            ->where('olderAfter', null)
        );
    }

    public function test_the_page_opens_with_a_dozen_in_whole_months_and_older_notes_follow(): void
    {
        $this->travelTo(Carbon::parse('2026-11-26 12:00', 'Africa/Lagos'));
        foreach (range(1, 10) as $day) {
            $this->note("2026-11-{$day} 10:00");
        }
        foreach (range(1, 5) as $day) {
            $this->note("2026-10-{$day} 10:00");
        }
        $september = $this->note('2026-09-20 10:00');

        $props = $this->get('/notes')->assertInertia(fn (Assert $page) => $page
            ->count('months', 2)
            ->count('months.1.notes', 5)
        )->viewData('page')['props'];
        $last = $props['olderAfter'];
        $this->assertSame(end($props['months'])['notes'][4]['id'], $last);

        $this->getJson('/notes-older?after='.$last)->assertOk()
            ->assertJsonPath('months.0.name', 'September')
            ->assertJsonPath('months.0.notes.0.id', $september->id)
            ->assertJsonPath('olderAfter', null);
    }

    public function test_older_notes_need_a_note_to_follow(): void
    {
        $this->getJson('/notes-older')->assertUnprocessable();
        $this->getJson('/notes-older?after=999')->assertOk()->assertExactJson(['months' => [], 'olderAfter' => null]);
    }
}
