<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_key')->unique();
            $table->string('slug')->unique();
            $table->timestampsTz();
        });

        Schema::create('genres', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();
        });

        Schema::create('works', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32)->index();
            $table->string('collection_kind', 32)->nullable();
            $table->string('title');
            $table->string('title_key')->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('status', 32)->default('in_progress');
            $table->string('visibility', 32)->default('private')->index();
            $table->foreignId('series_id')->nullable()->constrained('series')->nullOnDelete();
            $table->unsignedSmallInteger('series_position')->nullable();
            $table->foreignId('collection_id')->nullable()->constrained('works')->nullOnDelete();
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('cover_color', 7)->nullable();
            $table->string('heading_style', 32)->nullable();
            $table->boolean('show_chapter_titles')->default(true);
            $table->boolean('show_pov')->default(false);
            $table->string('part_word', 32)->nullable();
            $table->string('part_style', 32)->nullable();
            $table->boolean('show_part_numbers')->default(true);
            $table->string('scene_break', 32)->default('line_dot');
            $table->text('opening_quote')->nullable();
            $table->string('opening_quote_by')->nullable();
            $table->text('content_notes')->nullable();
            $table->unsignedSmallInteger('free_chapters')->nullable(); // print-only works: chapters still free to read
            $table->jsonb('plan_notes')->nullable();
            $table->boolean('mark_names')->default(false);
            $table->string('share_line')->nullable();
            $table->foreignId('share_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->boolean('share_uses_own')->default(false);
            $table->unsignedBigInteger('likes_count')->default(0);
            $table->unsignedBigInteger('comments_count')->default(0);
            $table->timestampTz('first_published_at')->nullable();
            $table->timestampTz('last_published_at')->nullable()->index();
            $table->timestampsTz();
        });

        $this->addSearchColumn('works', "coalesce(title, '') || ' ' || coalesce(description, '')");

        Schema::create('genre_work', function (Blueprint $table) {
            $table->foreignId('genre_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_id')->constrained()->cascadeOnDelete();
            $table->primary(['genre_id', 'work_id']);
            $table->timestampsTz();
        });

        Schema::create('slug_history', function (Blueprint $table) {
            $table->id();
            $table->morphs('sluggable');
            $table->string('slug');
            $table->timestampsTz();

            $table->unique(['sluggable_type', 'slug']);
        });

        Schema::create('work_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->unsignedInteger('position');
            $table->foreignId('piece_work_id')->nullable()->constrained('works')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->jsonb('draft_body')->nullable();
            $table->jsonb('live_body')->nullable();
            $table->text('draft_text')->nullable();
            $table->text('live_text')->nullable();
            $table->unsignedInteger('draft_words')->default(0);
            $table->unsignedInteger('live_words')->default(0);
            $table->unsignedInteger('draft_revision')->default(0);
            $table->timestampTz('draft_saved_at')->nullable();
            $table->string('status', 32)->default('draft');
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('live_updated_at')->nullable();
            $table->unsignedBigInteger('pov_character_id')->nullable();
            $table->text('epigraph')->nullable();
            $table->string('epigraph_by')->nullable();
            $table->string('soundtrack_url')->nullable();
            $table->string('soundtrack_label')->nullable();
            $table->unsignedBigInteger('likes_count')->default(0);
            $table->unsignedBigInteger('comments_count')->default(0);
            $table->timestampsTz();

            $table->index(['work_id', 'position']);
            $table->unique('piece_work_id');
            $table->index(['status', 'scheduled_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Deferred so a drag-and-drop reorder can shuffle positions inside one transaction.
            DB::statement('ALTER TABLE work_items ADD CONSTRAINT work_items_work_id_position_unique UNIQUE (work_id, position) DEFERRABLE INITIALLY DEFERRED');
        }

        $this->addSearchColumn('work_items', "coalesce(title, '') || ' ' || coalesce(live_text, '')");

        Schema::create('versions', function (Blueprint $table) {
            $table->id();
            $table->morphs('versionable');
            $table->string('label', 32);
            $table->jsonb('body');
            $table->unsignedInteger('words')->default(0);
            $table->unsignedInteger('words_added')->default(0);
            $table->unsignedInteger('words_cut')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('writing_days', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->foreignId('work_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('words_added')->default(0);
            $table->unsignedInteger('words_cut')->default(0);
            $table->timestampsTz();

            $table->unique(['day', 'work_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('writing_days');
        Schema::dropIfExists('versions');
        Schema::dropIfExists('work_items');
        Schema::dropIfExists('slug_history');
        Schema::dropIfExists('genre_work');
        Schema::dropIfExists('works');
        Schema::dropIfExists('genres');
        Schema::dropIfExists('series');
    }

    /**
     * Add the generated full-text search column (PostgreSQL only).
     */
    private function addSearchColumn(string $table, string $expression): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE {$table} ADD COLUMN search tsvector GENERATED ALWAYS AS (to_tsvector('simple', public.f_unaccent({$expression}))) STORED");
        DB::statement("CREATE INDEX {$table}_search_idx ON {$table} USING gin (search)");
    }
};
