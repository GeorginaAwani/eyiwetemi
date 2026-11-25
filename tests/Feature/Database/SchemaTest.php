<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_approved_table_exists(): void
    {
        $tables = [
            'users', 'social_accounts', 'trusted_devices', 'login_links', 'username_holds', 'notifications',
            'media', 'series', 'genres', 'works', 'genre_work', 'slug_history', 'work_items', 'versions',
            'writing_days', 'characters', 'character_links', 'world_groups', 'world_entries', 'plot_threads',
            'plot_points', 'research_items', 'research_ties', 'sweep_names', 'work_reviewer', 'reviewer_invites',
            'reviewer_invite_work', 'comments', 'likes', 'follows', 'reading_progress', 'notes', 'subscribers',
            'newsletter_sends', 'newsletter_send_items', 'site_pages', 'site_settings', 'notices', 'link_checks',
            'daily_stats', 'daily_breakdowns', 'daily_visitors',
        ];

        $missing = array_values(array_filter($tables, fn (string $table): bool => ! Schema::hasTable($table)));

        $this->assertSame([], $missing);
    }

    public function test_key_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'username', 'email', 'role', 'avatar_seed', 'password', 'preferences', 'pending_email',
            'username_changed_at', 'two_factor_secret', 'two_factor_confirmed_at',
        ]));

        $this->assertTrue(Schema::hasColumns('works', [
            'kind', 'collection_kind', 'title', 'title_key', 'slug', 'status', 'visibility', 'series_id',
            'collection_id', 'cover_media_id', 'share_media_id', 'likes_count', 'last_published_at', 'free_chapters', 'search',
        ]));

        $this->assertTrue(Schema::hasColumns('work_items', [
            'work_id', 'type', 'position', 'piece_work_id', 'draft_body', 'live_body', 'draft_text', 'live_text',
            'draft_words', 'live_words', 'draft_revision', 'status', 'scheduled_at', 'pov_character_id', 'search',
        ]));

        $this->assertTrue(Schema::hasColumns('notes', [
            'status', 'draft_body', 'live_body', 'draft_text', 'live_text', 'draft_work_id', 'notify_followers',
            'published_at', 'search',
        ]));

        $this->assertTrue(Schema::hasColumns('comments', [
            'user_id', 'work_id', 'work_item_id', 'parent_id', 'audience', 'kind', 'anchor', 'suggestion', 'body',
            'status', 'hidden_at', 'seen_at', 'deleted_at', 'search',
        ]));

        $this->assertTrue(Schema::hasColumns('reading_progress', ['user_id', 'work_item_id', 'percent', 'finished_at']));
        $this->assertTrue(Schema::hasColumns('site_pages', ['key', 'draft', 'live']));
    }

    public function test_daily_visitors_has_no_timestamps(): void
    {
        $this->assertTrue(Schema::hasColumns('daily_visitors', ['day', 'scope', 'hash']));
        $this->assertFalse(Schema::hasColumn('daily_visitors', 'created_at'));
    }
}
