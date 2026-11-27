<?php

namespace Tests\Feature\Admin;

use App\Enums\VersionLabel;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use App\Support\DeviceKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminOfflineWriteTest extends TestCase
{
    use RefreshDatabase;

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    private const PHONE_BROWSER = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';

    private const IPAD = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    private const TABLET_BROWSER = 'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private const LAPTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

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

    private function writeUrl(WorkItem $item): string
    {
        return $this->adminUrl('/works/'.$item->work->slug.'/write/'.$item->id);
    }

    private function twoDraftsUrl(WorkItem $item): string
    {
        return $this->adminUrl('/works/'.$item->work->slug.'/write/'.$item->id.'/two-drafts');
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
    private function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'attrs' => ['id' => 'p1'], 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * A chapter whose draft was last saved from a laptop at revision 5.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function chapter(array $attributes = []): WorkItem
    {
        $work = Work::factory()->novel()->create(['title' => 'The Tide House', 'title_key' => 'the tide house', 'slug' => 'the-tide-house']);

        return WorkItem::factory()->for($work)->create(array_merge([
            'draft_body' => $this->doc([$this->paragraph('Laptop draft.')]),
            'draft_text' => 'Laptop draft.',
            'draft_words' => 2,
            'draft_revision' => 5,
            'draft_device' => 'laptop',
        ], $attributes));
    }

    public function test_a_save_from_the_revision_it_was_written_from_saves_and_records_the_phone(): void
    {
        $item = $this->chapter();

        $this->asAdmin()->withHeaders(['User-Agent' => self::IPHONE])
            ->patchJson($this->writeUrl($item), ['body' => $this->doc([$this->paragraph('Phone draft.')]), 'base' => 5])
            ->assertOk()
            ->assertJsonPath('revision', 6);

        $item->refresh();
        $this->assertSame(6, $item->draft_revision);
        $this->assertSame('phone', $item->draft_device);
        $this->assertEquals($this->doc([$this->paragraph('Phone draft.')]), $item->draft_body);
    }

    public function test_a_save_from_a_stale_revision_is_refused_with_the_other_draft_and_saves_nothing(): void
    {
        $item = $this->chapter(['draft_revision' => 7]);

        $response = $this->asAdmin()->withHeaders(['User-Agent' => self::IPHONE])
            ->patchJson($this->writeUrl($item), ['body' => $this->doc([$this->paragraph('Phone draft.')]), 'base' => 5]);

        $response->assertConflict();
        $response->assertJsonPath('message', 'Two drafts');
        $response->assertJsonPath('revision', 7);
        $response->assertJsonPath('other.words', 2);
        $response->assertJsonPath('other.device', 'laptop');
        $this->assertEquals($this->doc([$this->paragraph('Laptop draft.')]), $response->json('other.body'));

        $item->refresh();
        $this->assertSame(7, $item->draft_revision);
        $this->assertSame('laptop', $item->draft_device);
        $this->assertEquals($this->doc([$this->paragraph('Laptop draft.')]), $item->draft_body);
    }

    public function test_a_stale_save_whose_page_already_is_the_draft_saves_normally(): void
    {
        $page = $this->doc([$this->paragraph('Already landed.')]);
        $item = $this->chapter(['draft_body' => $page, 'draft_revision' => 6, 'draft_device' => 'laptop']);

        $this->asAdmin()->withHeaders(['User-Agent' => self::IPHONE])
            ->patchJson($this->writeUrl($item), ['body' => $page, 'base' => 5])
            ->assertOk()
            ->assertJsonPath('revision', 7);

        $this->assertSame(7, $item->fresh()->draft_revision);
        $this->assertSame('phone', $item->fresh()->draft_device);
    }

    public function test_a_save_without_a_base_saves_whatever_the_revision_is(): void
    {
        $item = $this->chapter(['draft_revision' => 9]);

        $this->asAdmin()->patchJson($this->writeUrl($item), ['body' => $this->doc([$this->paragraph('Any draft.')])])
            ->assertOk()
            ->assertJsonPath('revision', 10);

        $this->assertEquals($this->doc([$this->paragraph('Any draft.')]), $item->fresh()->draft_body);
    }

    public function test_keeping_this_device_moves_the_other_draft_into_versions_and_replaces_the_draft(): void
    {
        $item = $this->chapter(['draft_revision' => 7]);

        $this->asAdmin()->withHeaders(['User-Agent' => self::IPHONE])
            ->postJson($this->twoDraftsUrl($item), [
                'keep' => 'this',
                'body' => $this->doc([$this->paragraph('Phone draft.')]),
                'revision' => 7,
            ])
            ->assertOk()
            ->assertJsonPath('revision', 8);

        $version = $item->versions()->sole();
        $this->assertSame(VersionLabel::DraftFromDevice, $version->label);
        $this->assertSame('laptop', $version->device);
        $this->assertEquals($this->doc([$this->paragraph('Laptop draft.')]), $version->body);

        $item->refresh();
        $this->assertEquals($this->doc([$this->paragraph('Phone draft.')]), $item->draft_body);
        $this->assertSame(8, $item->draft_revision);
        $this->assertSame('phone', $item->draft_device);
    }

    public function test_keeping_the_other_draft_puts_this_device_page_into_versions_and_leaves_the_draft(): void
    {
        $item = $this->chapter(['draft_revision' => 7]);

        $this->asAdmin()->withHeaders(['User-Agent' => self::IPHONE])
            ->postJson($this->twoDraftsUrl($item), [
                'keep' => 'other',
                'body' => $this->doc([$this->paragraph('Phone draft.')]),
                'revision' => 7,
            ])
            ->assertOk()
            ->assertJsonPath('revision', 8);

        $version = $item->versions()->sole();
        $this->assertSame(VersionLabel::DraftFromDevice, $version->label);
        $this->assertSame('phone', $version->device);
        $this->assertEquals($this->doc([$this->paragraph('Phone draft.')]), $version->body);

        $item->refresh();
        $this->assertEquals($this->doc([$this->paragraph('Laptop draft.')]), $item->draft_body);
        $this->assertSame(8, $item->draft_revision);
        $this->assertSame('laptop', $item->draft_device);
    }

    public function test_a_two_drafts_choice_with_a_stale_revision_is_refused_and_saves_nothing(): void
    {
        $item = $this->chapter(['draft_revision' => 7]);

        $response = $this->asAdmin()->withHeaders(['User-Agent' => self::IPHONE])
            ->postJson($this->twoDraftsUrl($item), [
                'keep' => 'this',
                'body' => $this->doc([$this->paragraph('Phone draft.')]),
                'revision' => 6,
            ]);

        $response->assertConflict();
        $response->assertJsonPath('message', 'Two drafts');
        $response->assertJsonPath('revision', 7);

        $this->assertCount(0, $item->versions);
        $item->refresh();
        $this->assertSame(7, $item->draft_revision);
        $this->assertEquals($this->doc([$this->paragraph('Laptop draft.')]), $item->draft_body);
    }

    public function test_the_version_label_names_this_device_or_the_device_it_came_from(): void
    {
        $item = $this->chapter();
        $item->versions()->create([
            'label' => VersionLabel::DraftFromDevice, 'device' => 'laptop', 'body' => $this->doc([]),
            'words' => 0, 'words_added' => 0, 'words_cut' => 0,
        ]);
        $item->versions()->create([
            'label' => VersionLabel::DraftFromDevice, 'device' => 'phone', 'body' => $this->doc([]),
            'words' => 0, 'words_added' => 0, 'words_cut' => 0,
        ]);

        $this->asAdmin()->withHeaders(['User-Agent' => self::IPHONE])->get($this->writeUrl($item))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Write')
                ->where('publish.versions.0.label', 'Draft from this phone')
                ->where('publish.versions.1.label', 'Draft from Laptop'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function userAgents(): array
    {
        return [
            'iPhone' => [self::IPHONE, 'phone'],
            'Android phone' => [self::PHONE_BROWSER, 'phone'],
            'iPad' => [self::IPAD, 'tablet'],
            'Android tablet' => [self::TABLET_BROWSER, 'tablet'],
            'Windows Chrome' => [self::LAPTOP, 'laptop'],
        ];
    }

    #[DataProvider('userAgents')]
    public function test_a_user_agent_is_classed_as_a_phone_tablet_or_laptop(string $userAgent, string $kind): void
    {
        $this->assertSame($kind, DeviceKind::of($userAgent));
    }
}
