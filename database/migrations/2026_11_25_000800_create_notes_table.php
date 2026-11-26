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
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->string('status', 32)->default('draft');
            $table->jsonb('draft_body')->nullable();
            $table->jsonb('live_body')->nullable();
            $table->text('draft_text')->nullable();
            $table->text('live_text')->nullable();
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('draft_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('work_id')->nullable()->constrained('works')->nullOnDelete();
            $table->foreignId('draft_work_id')->nullable()->constrained('works')->nullOnDelete();
            $table->boolean('notify_followers')->default(true);
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('published_at')->nullable()->index();
            $table->timestampTz('live_updated_at')->nullable();
            $table->unsignedBigInteger('likes_count')->default(0);
            $table->timestampsTz();

            $table->index(['status', 'scheduled_at']);
            $table->index('media_id');
            $table->index('draft_media_id');
            $table->index('work_id');
            $table->index('draft_work_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE notes ADD COLUMN search tsvector GENERATED ALWAYS AS (to_tsvector('simple', public.f_unaccent(coalesce(live_text, '')))) STORED");
            DB::statement('CREATE INDEX notes_search_idx ON notes USING gin (search)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
