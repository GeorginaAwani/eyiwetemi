<?php

namespace Tests\Feature\Models;

use App\Enums\CollectionKind;
use App\Enums\CommentAudience;
use App\Enums\CommentKind;
use App\Enums\CommentStatus;
use App\Enums\HeadingStyle;
use App\Enums\PublishStatus;
use App\Enums\ResearchKind;
use App\Enums\SceneBreak;
use App\Enums\SendStatus;
use App\Enums\SitePageKey;
use App\Enums\StatDimension;
use App\Enums\StatSubject;
use App\Enums\UserRole;
use App\Enums\VersionLabel;
use App\Enums\Visibility;
use App\Enums\WorkItemType;
use App\Enums\WorkKind;
use App\Enums\WorkStatus;
use App\Models\Character;
use App\Models\Comment;
use App\Models\DailyBreakdown;
use App\Models\DailyStat;
use App\Models\Follow;
use App\Models\Genre;
use App\Models\Like;
use App\Models\Media;
use App\Models\NewsletterSend;
use App\Models\Note;
use App\Models\ResearchItem;
use App\Models\ResearchTie;
use App\Models\Series;
use App\Models\SitePage;
use App\Models\User;
use App\Models\Version;
use App\Models\Work;
use App\Models\WorkItem;
use App\Models\WorldEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_resolves_its_items_chapters_genres_series_reviewers_and_cover(): void
    {
        $series = Series::factory()->create();
        $cover = Media::factory()->create();
        $reviewer = User::factory()->reviewer()->create();
        $genre = Genre::factory()->create();
        $work = Work::factory()->novel()->create(['series_id' => $series->id, 'cover_media_id' => $cover->id]);
        $work->genres()->attach($genre);
        $work->reviewers()->attach($reviewer);

        $part = WorkItem::factory()->part()->for($work)->create(['position' => 1]);
        $chapter = WorkItem::factory()->for($work)->create(['position' => 2]);

        $work->load(['items', 'chapters', 'genres', 'series', 'reviewers', 'cover']);

        $this->assertSame([$part->id, $chapter->id], $work->items->pluck('id')->all());
        $this->assertSame([$chapter->id], $work->chapters->pluck('id')->all());
        $this->assertSame([$genre->id], $work->genres->pluck('id')->all());
        $this->assertTrue($work->series->is($series));
        $this->assertSame([$reviewer->id], $work->reviewers->pluck('id')->all());
        $this->assertTrue($work->cover->is($cover));
    }

    public function test_work_item_versions_resolve_through_the_morph_alias(): void
    {
        $item = WorkItem::factory()->create();
        $version = Version::factory()->for($item, 'versionable')->create();

        $item->load('versions');
        $version->load('versionable');

        $this->assertSame('work_item', $version->versionable_type);
        $this->assertSame([$version->id], $item->versions->pluck('id')->all());
        $this->assertTrue($version->versionable->is($item));
    }

    public function test_comment_replies_parent_and_likes_resolve(): void
    {
        $parent = Comment::factory()->create();
        $reply = Comment::factory()->replyTo($parent)->create();
        $like = Like::factory()->for($parent, 'likeable')->create();

        $parent->load(['replies', 'likes']);
        $reply->load('parent');

        $this->assertSame([$reply->id], $parent->replies->pluck('id')->all());
        $this->assertTrue($reply->parent->is($parent));
        $this->assertSame([$like->id], $parent->likes->pluck('id')->all());
        $this->assertSame('comment', $like->likeable_type);
    }

    public function test_note_resolves_its_likes_and_work(): void
    {
        $work = Work::factory()->create();
        $note = Note::factory()->published()->create(['work_id' => $work->id]);
        $like = Like::factory()->for($note, 'likeable')->create();

        $note->load(['likes', 'work']);

        $this->assertSame([$like->id], $note->likes->pluck('id')->all());
        $this->assertTrue($note->work->is($work));
        $this->assertSame('note', $like->likeable_type);
    }

    public function test_research_ties_resolve_to_characters_and_world_entries(): void
    {
        $item = ResearchItem::factory()->create();
        $character = Character::factory()->create(['work_id' => $item->work_id]);
        $entry = WorldEntry::factory()->create();
        ResearchTie::factory()->for($character, 'tieable')->create(['research_item_id' => $item->id]);
        ResearchTie::factory()->for($entry, 'tieable')->create(['research_item_id' => $item->id]);

        $item->load('ties.tieable');

        $this->assertCount(2, $item->ties);
        $this->assertTrue($item->ties->firstWhere('tieable_type', 'character')->tieable->is($character));
        $this->assertTrue($item->ties->firstWhere('tieable_type', 'world_entry')->tieable->is($entry));
    }

    public function test_follow_resolves_to_a_series(): void
    {
        $series = Series::factory()->create();
        $follow = Follow::factory()->for($series, 'followable')->create();

        $series->load('follows');
        $follow->load('followable');

        $this->assertSame([$follow->id], $series->follows->pluck('id')->all());
        $this->assertTrue($follow->followable->is($series));
        $this->assertSame('series', $follow->followable_type);
    }

    public function test_site_page_versions_resolve_through_the_morph_alias(): void
    {
        $page = SitePage::factory()->about()->create();
        $version = Version::factory()->for($page, 'versionable')->create();

        $page->load('versions');

        $this->assertSame([$version->id], $page->versions->pluck('id')->all());
        $this->assertSame('site_page', $version->versionable_type);
    }

    public function test_enum_columns_cast_to_enum_instances(): void
    {
        $user = User::factory()->author()->create();
        $work = Work::factory()->collection(CollectionKind::Poem)->public()->create();
        $item = WorkItem::factory()->published()->for($work)->create();
        $comment = Comment::factory()->review()->create(['work_id' => $work->id]);
        $version = Version::factory()->for($item, 'versionable')->create();
        $research = ResearchItem::factory()->create();
        $send = NewsletterSend::factory()->create();
        $page = SitePage::factory()->about()->create();
        $stat = DailyStat::factory()->create();
        $breakdown = DailyBreakdown::factory()->create();

        $this->assertSame(UserRole::Author, $user->fresh()->role);
        $this->assertSame(WorkKind::Collection, $work->fresh()->kind);
        $this->assertSame(CollectionKind::Poem, $work->fresh()->collection_kind);
        $this->assertSame(WorkStatus::InProgress, $work->fresh()->status);
        $this->assertSame(Visibility::Public, $work->fresh()->visibility);
        $this->assertSame(HeadingStyle::ChapterWord, $work->fresh()->heading_style);
        $this->assertSame(SceneBreak::LineDot, $work->fresh()->scene_break);
        $this->assertSame(WorkItemType::Chapter, $item->fresh()->type);
        $this->assertSame(PublishStatus::Published, $item->fresh()->status);
        $this->assertSame(CommentAudience::Review, $comment->fresh()->audience);
        $this->assertSame(CommentKind::Comment, $comment->fresh()->kind);
        $this->assertSame(CommentStatus::Open, $comment->fresh()->status);
        $this->assertSame(VersionLabel::Published, $version->fresh()->label);
        $this->assertSame(ResearchKind::Link, $research->fresh()->kind);
        $this->assertSame(SendStatus::Planned, $send->fresh()->status);
        $this->assertSame(SitePageKey::About, $page->fresh()->key);
        $this->assertSame(StatSubject::Site, $stat->fresh()->subject);
        $this->assertSame(StatDimension::Country, $breakdown->fresh()->dimension);
    }
}
