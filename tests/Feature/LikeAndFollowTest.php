<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\Series;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LikeAndFollowTest extends TestCase
{
    use RefreshDatabase;

    public function test_liking_counts_once_and_unliking_takes_it_back(): void
    {
        $user = User::factory()->create();
        $note = Note::factory()->published()->create(['likes_count' => 4]);

        $this->actingAs($user)->putJson('/likes/note/'.$note->id)->assertOk()->assertExactJson(['liked' => true, 'count' => 5]);
        $this->actingAs($user)->putJson('/likes/note/'.$note->id)->assertOk()->assertJson(['count' => 5]);
        $this->assertDatabaseHas('likes', ['user_id' => $user->id, 'likeable_type' => 'note', 'likeable_id' => $note->id]);

        $this->actingAs($user)->deleteJson('/likes/note/'.$note->id)->assertOk()->assertExactJson(['liked' => false, 'count' => 4]);
        $this->actingAs($user)->deleteJson('/likes/note/'.$note->id)->assertOk()->assertJson(['count' => 4]);
    }

    public function test_only_what_readers_can_see_can_be_liked(): void
    {
        $user = User::factory()->create();
        $draft = Work::factory()->novel()->create();
        $chapter = WorkItem::factory()->chapter()->for($draft)->create();
        $scheduled = Note::factory()->scheduled()->create();

        $this->actingAs($user)->putJson('/likes/work_item/'.$chapter->id)->assertNotFound();
        $this->actingAs($user)->putJson('/likes/note/'.$scheduled->id)->assertNotFound();
        $this->actingAs($user)->putJson('/likes/user/1')->assertNotFound();
        $this->assertDatabaseCount('likes', 0);
    }

    public function test_following_a_work_or_series(): void
    {
        $user = User::factory()->create();
        $work = Work::factory()->novel()->public()->published()->create();
        $series = Series::factory()->create();

        $this->actingAs($user)->putJson('/follows/work/'.$work->id)->assertOk()->assertExactJson(['following' => true]);
        $this->actingAs($user)->putJson('/follows/series/'.$series->id)->assertOk();
        $this->assertSame(2, $user->follows()->count());

        $this->actingAs($user)->deleteJson('/follows/work/'.$work->id)->assertOk()->assertExactJson(['following' => false]);
        $this->assertSame(1, $user->follows()->count());

        $this->actingAs($user)->putJson('/follows/work/'.Work::factory()->novel()->create()->id)->assertNotFound();
    }

    public function test_guests_cannot_like_or_follow(): void
    {
        $note = Note::factory()->published()->create();

        $this->putJson('/likes/note/'.$note->id)->assertUnauthorized();
        $this->putJson('/follows/series/1')->assertUnauthorized();
    }
}
