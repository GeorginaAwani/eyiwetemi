<?php

namespace Tests\Feature\Admin;

use App\Enums\CollectionKind;
use App\Enums\WorkItemType;
use App\Models\Character;
use App\Models\Media;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use App\Models\WritingDay;
use App\Support\WorkPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminWriteTest extends TestCase
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

    public function test_the_write_page_numbers_a_chapter_among_the_chapters_and_a_part_among_the_parts(): void
    {
        $work = Work::factory()->novel()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);
        WorkItem::factory()->part()->for($work)->create(['position' => 1]);
        WorkItem::factory()->for($work)->create(['position' => 2]);
        $chapter = WorkItem::factory()->for($work)->create([
            'position' => 3,
            'draft_body' => $this->doc([$this->paragraph('The house stood on the cliff.')]),
        ]);
        WorkItem::factory()->part()->for($work)->create(['position' => 4]);

        $this->asAdmin()->get($this->writeUrl($chapter))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Write')
                ->where('work.kind', 'novel')
                ->where('item.number', 2)
                ->where('item.body', $this->doc([$this->paragraph('The house stood on the cliff.')])));

        $secondPart = WorkItem::query()->where('work_id', $work->id)->where('position', 4)->sole();

        $this->asAdmin()->get($this->writeUrl($secondPart))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Write')
                ->where('item.type', WorkItemType::Part->value)
                ->where('item.number', 2));
    }

    public function test_a_collection_of_poems_opens_its_pieces_as_poems(): void
    {
        $collection = Work::factory()->collection(CollectionKind::Poem)->create([
            'title' => 'Night Poems', 'title_key' => 'night poems', 'slug' => 'night-poems',
        ]);
        $piece = WorkItem::factory()->for($collection)->create(['type' => WorkItemType::Piece, 'piece_work_id' => null]);

        $this->asAdmin()->get($this->writeUrl($piece))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Write')
                ->where('work.kind', 'poem')
                ->where('work.collection', true));
    }

    public function test_a_page_of_another_work_is_not_found_under_this_work(): void
    {
        $work = Work::factory()->novel()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);
        $otherWork = Work::factory()->novel()->create(['title' => 'The Ember Gate', 'title_key' => 'the ember gate', 'slug' => 'the-ember-gate']);
        $otherItem = WorkItem::factory()->for($otherWork)->create();

        $this->asAdmin()->get($this->writeUrl($otherItem, $work))->assertNotFound();
        $this->asAdmin()->patchJson($this->writeUrl($otherItem, $work), ['title' => 'Stolen'])->assertNotFound();

        $this->assertNotSame('Stolen', $otherItem->fresh()->title);
    }

    public function test_a_piece_a_collection_borrows_opens_on_its_own_first_page(): void
    {
        $collection = Work::factory()->collection(CollectionKind::Story)->create([
            'title' => 'Short Tides', 'title_key' => 'short tides', 'slug' => 'short-tides',
        ]);
        $story = Work::factory()->story()->create(['title' => 'The Lighthouse', 'title_key' => 'the lighthouse', 'slug' => 'the-lighthouse']);
        $ownPage = WorkItem::factory()->for($story)->create(['position' => 1]);
        $borrowed = WorkItem::factory()->for($collection)->create([
            'type' => WorkItemType::Piece,
            'piece_work_id' => $story->id,
        ]);

        $this->asAdmin()->get($this->writeUrl($borrowed))
            ->assertRedirect($this->adminUrl('/works/the-lighthouse/write/'.$ownPage->id));
    }

    public function test_a_save_stores_the_draft_with_its_words_including_footnotes_and_leaves_the_live_text_alone(): void
    {
        $work = Work::factory()->novel()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);
        $liveBody = $this->doc([$this->paragraph('Already printed.')]);
        $item = WorkItem::factory()->for($work)->create([
            'draft_body' => $this->doc([$this->paragraph('Old draft.')]),
            'live_body' => $liveBody,
            'live_text' => 'Already printed.',
            'draft_words' => 0,
            'draft_revision' => 4,
            'draft_saved_at' => now()->subDay(),
        ]);
        $page = $this->doc([[
            'type' => 'paragraph',
            'attrs' => ['id' => 'p1'],
            'content' => [
                ['type' => 'text', 'text' => 'One two three'],
                ['type' => 'footnote', 'attrs' => ['id' => 'f1', 'text' => 'four five']],
            ],
        ]]);

        $this->asAdmin()->patchJson($this->writeUrl($item), ['body' => $page])
            ->assertOk()
            ->assertJsonPath('words', 5);

        $item->refresh();
        $this->assertEquals($page, $item->draft_body);
        $this->assertSame('One two three', $item->draft_text);
        $this->assertSame(5, $item->draft_words);
        $this->assertSame(5, $item->draft_revision);
        $this->assertTrue($item->draft_saved_at->isAfter(now()->subMinute()));

        $this->assertEquals($liveBody, $item->live_body);
        $this->assertSame('Already printed.', $item->live_text);
    }

    public function test_a_save_strips_unknown_nodes_and_marks_drops_javascript_links_and_gives_blocks_an_id(): void
    {
        $work = Work::factory()->novel()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);
        $item = WorkItem::factory()->for($work)->create(['draft_words' => 0]);

        $this->asAdmin()->patchJson($this->writeUrl($item), ['body' => $this->doc([
            [
                'type' => 'paragraph',
                'attrs' => ['id' => 'p1'],
                'content' => [
                    ['type' => 'text', 'text' => 'Hello', 'marks' => [['type' => 'italic'], ['type' => 'underline']]],
                    ['type' => 'text', 'text' => 'bad', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]],
                    ['type' => 'text', 'text' => 'good', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/x']]]],
                ],
            ],
            ['type' => 'iframe', 'attrs' => ['src' => 'https://evil.example']],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'No id yet']]],
        ])])->assertOk();

        $content = $item->fresh()->draft_body['content'];

        $this->assertCount(2, $content);
        $this->assertSame('p1', $content[0]['attrs']['id']);
        $this->assertSame([['type' => 'italic']], $content[0]['content'][0]['marks']);
        $this->assertArrayNotHasKey('marks', $content[0]['content'][1]);
        $this->assertSame([['type' => 'link', 'attrs' => ['href' => 'https://example.com/x']]], $content[0]['content'][2]['marks']);

        $this->assertMatchesRegularExpression('/^b[0-9a-f]{10}$/', $content[1]['attrs']['id']);
        $this->assertSame('No id yet', $content[1]['content'][0]['text']);
    }

    public function test_the_spaces_at_the_edges_of_text_next_to_a_mark_are_kept_when_saved(): void
    {
        $work = Work::factory()->novel()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);
        $item = WorkItem::factory()->for($work)->create(['draft_words' => 0]);

        $this->asAdmin()->patchJson($this->writeUrl($item), ['body' => $this->doc([[
            'type' => 'paragraph',
            'attrs' => ['id' => 'p1'],
            'content' => [
                ['type' => 'text', 'text' => 'Hello', 'marks' => [['type' => 'italic']]],
                ['type' => 'text', 'text' => ' world'],
            ],
        ]])])->assertOk();

        $this->assertSame('Hello world', $item->fresh()->draft_text);
    }

    public function test_words_a_save_adds_go_to_todays_writing_day_and_words_it_cuts_go_to_words_cut(): void
    {
        $work = Work::factory()->novel()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);
        $item = WorkItem::factory()->for($work)->create(['draft_words' => 0]);
        $today = now((string) config('eyiwetemi.timezone'))->toDateString();

        $this->asAdmin()->patchJson($this->writeUrl($item), ['body' => $this->doc([[
            'type' => 'paragraph',
            'attrs' => ['id' => 'p1'],
            'content' => [
                ['type' => 'text', 'text' => 'One two three'],
                ['type' => 'footnote', 'attrs' => ['id' => 'f1', 'text' => 'four five']],
            ],
        ]])])->assertOk();

        $this->assertDatabaseHas('writing_days', ['work_id' => $work->id, 'day' => $today, 'words_added' => 5, 'words_cut' => 0]);

        $this->asAdmin()->patchJson($this->writeUrl($item), ['body' => $this->doc([$this->paragraph('One two')])])
            ->assertOk()
            ->assertJsonPath('words', 2);

        $day = WritingDay::query()->where('work_id', $work->id)->sole();
        $this->assertSame($today, $day->day->toDateString());
        $this->assertSame(5, $day->words_added);
        $this->assertSame(3, $day->words_cut);
    }

    public function test_a_title_and_description_are_trimmed_with_spaces_collapsed_and_an_empty_one_is_cleared(): void
    {
        $work = Work::factory()->novel()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);
        $item = WorkItem::factory()->for($work)->create();

        $this->asAdmin()->patchJson($this->writeUrl($item), [
            'title' => "  The   Tide \n House  ",
            'description' => '  Some   words  here  ',
        ])->assertOk();

        $item->refresh();
        $this->assertSame('The Tide House', $item->title);
        $this->assertSame('Some words here', $item->description);

        $this->asAdmin()->patchJson($this->writeUrl($item), ['title' => '   ', 'description' => ''])->assertOk();

        $item->refresh();
        $this->assertNull($item->title);
        $this->assertNull($item->description);
    }

    public function test_a_point_of_view_character_of_this_work_is_saved_and_one_from_another_work_is_refused(): void
    {
        $work = Work::factory()->novel()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);
        $otherWork = Work::factory()->novel()->create(['title' => 'The Ember Gate', 'title_key' => 'the ember gate', 'slug' => 'the-ember-gate']);
        $item = WorkItem::factory()->for($work)->create();
        $ownCharacter = Character::factory()->for($work)->create();
        $foreignCharacter = Character::factory()->for($otherWork)->create();

        $this->asAdmin()->patchJson($this->writeUrl($item), ['pov' => $ownCharacter->id])->assertOk();

        $this->assertSame($ownCharacter->id, $item->fresh()->pov_character_id);

        $this->asAdmin()->patchJson($this->writeUrl($item), ['pov' => $foreignCharacter->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pov']);

        $this->assertSame($ownCharacter->id, $item->fresh()->pov_character_id);
    }

    public function test_a_heading_quote_is_saved_trimmed_and_a_null_quote_clears_both_fields(): void
    {
        $work = Work::factory()->novel()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);
        $item = WorkItem::factory()->for($work)->create();

        $this->asAdmin()->patchJson($this->writeUrl($item), ['quote' => ['q' => '  Once upon a tide  ', 'by' => ' Someone ']])->assertOk();

        $item->refresh();
        $this->assertSame('Once upon a tide', $item->epigraph);
        $this->assertSame('Someone', $item->epigraph_by);

        $this->asAdmin()->patchJson($this->writeUrl($item), ['quote' => null])->assertOk();

        $item->refresh();
        $this->assertNull($item->epigraph);
        $this->assertNull($item->epigraph_by);
    }

    public function test_a_youtube_soundtrack_is_saved_with_its_title_and_artist_from_oembed(): void
    {
        Http::fake([
            'www.youtube.com/oembed*' => Http::response(['title' => 'Video Games', 'author_name' => 'Lana Del Rey'], 200),
        ]);
        $work = Work::factory()->novel()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);
        $item = WorkItem::factory()->for($work)->create();

        $this->asAdmin()->patchJson($this->writeUrl($item), ['soundtrack' => 'https://www.youtube.com/watch?v=abc123'])
            ->assertOk()
            ->assertJsonPath('soundtrack.url', 'https://www.youtube.com/watch?v=abc123')
            ->assertJsonPath('soundtrack.label', 'Video Games · Lana Del Rey');

        $item->refresh();
        $this->assertSame('https://www.youtube.com/watch?v=abc123', $item->soundtrack_url);
        $this->assertSame('Video Games · Lana Del Rey', $item->soundtrack_label);
    }

    public function test_a_soundtrack_whose_oembed_lookup_fails_is_labelled_youtube(): void
    {
        Http::fake([
            'www.youtube.com/oembed*' => Http::response('', 404),
        ]);
        $work = Work::factory()->novel()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);
        $item = WorkItem::factory()->for($work)->create();

        $this->asAdmin()->patchJson($this->writeUrl($item), ['soundtrack' => 'https://youtu.be/abc123'])->assertOk();

        $this->assertSame('YouTube', $item->fresh()->soundtrack_label);
    }

    public function test_a_link_that_is_not_youtube_or_spotify_is_refused_with_a_plain_message(): void
    {
        $work = Work::factory()->novel()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);
        $item = WorkItem::factory()->for($work)->create();

        $this->asAdmin()->patchJson($this->writeUrl($item), ['soundtrack' => 'https://example.com/song'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['soundtrack' => 'Paste a YouTube or Spotify link.']);

        $this->assertNull($item->fresh()->soundtrack_url);
    }

    public function test_a_null_soundtrack_clears_its_link_and_label(): void
    {
        Http::fake([
            'www.youtube.com/oembed*' => Http::response(['title' => 'Video Games', 'author_name' => 'Lana Del Rey'], 200),
        ]);
        $work = Work::factory()->novel()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);
        $item = WorkItem::factory()->for($work)->create();

        $this->asAdmin()->patchJson($this->writeUrl($item), ['soundtrack' => 'https://www.youtube.com/watch?v=abc123'])->assertOk();
        $this->asAdmin()->patchJson($this->writeUrl($item), ['soundtrack' => null])
            ->assertOk()
            ->assertJsonPath('soundtrack', null);

        $item->refresh();
        $this->assertNull($item->soundtrack_url);
        $this->assertNull($item->soundtrack_label);
    }

    public function test_a_picture_is_stored_as_a_webp_no_wider_than_1600_and_its_media_id_and_address_are_returned(): void
    {
        Storage::fake(config('eyiwetemi.media_disk'));
        $work = Work::factory()->novel()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);
        $item = WorkItem::factory()->for($work)->create();

        $response = $this->asAdmin()->postJson($this->writeUrl($item).'/image', [
            'image' => UploadedFile::fake()->image('p.jpg', 3000, 2000),
        ])->assertCreated()
            ->assertJsonStructure(['mediaId', 'src']);

        $media = Media::query()->sole();
        $response->assertJsonPath('mediaId', $media->id)
            ->assertJsonPath('src', WorkPresenter::mediaUrl($media->disk, $media->path));

        $this->assertStringStartsWith('images/', $media->path);
        $this->assertStringEndsWith('.webp', $media->path);
        $this->assertSame(1600, $media->width);

        Storage::disk($media->disk)->assertExists($media->path);
        $stored = getimagesizefromstring((string) Storage::disk($media->disk)->get($media->path));

        $this->assertSame('image/webp', $stored['mime']);
        $this->assertSame(1600, $stored[0]);
        $this->assertLessThanOrEqual(1600, $stored[1]);
    }

    public function test_guests_are_sent_to_the_sign_in_for_the_write_page_and_for_a_save(): void
    {
        $work = Work::factory()->novel()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);
        $item = WorkItem::factory()->for($work)->create(['draft_words' => 0]);

        $this->get($this->writeUrl($item))->assertRedirect(route('admin.signin'));

        $this->patch($this->writeUrl($item), ['body' => $this->doc([$this->paragraph('Sneaky words')])])
            ->assertRedirect(route('admin.signin'));

        $this->assertSame(0, $item->fresh()->draft_revision);
        $this->assertSame(0, $item->fresh()->draft_words);
    }
}
