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
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('work_id')->constrained('works')->cascadeOnDelete();
            $table->foreignId('work_item_id')->nullable()->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->foreignId('reply_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('audience', 32)->default('public');
            $table->string('kind', 32)->default('comment');
            $table->jsonb('anchor')->nullable();
            $table->jsonb('suggestion')->nullable();
            $table->text('body')->nullable();
            $table->string('status', 32)->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('hidden_at')->nullable();
            $table->timestampTz('seen_at')->nullable();
            $table->timestampTz('edited_at')->nullable();
            $table->softDeletesTz();
            $table->unsignedBigInteger('likes_count')->default(0);
            $table->timestampsTz();

            $table->index(['work_id', 'audience', 'created_at']);
            $table->index(['work_item_id', 'audience']);
            $table->index('user_id');
            $table->index('parent_id');
            $table->index('reply_to_user_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE comments ADD COLUMN search tsvector GENERATED ALWAYS AS (to_tsvector('simple', public.f_unaccent(coalesce(body, '')))) STORED");
            DB::statement('CREATE INDEX comments_search_idx ON comments USING gin (search)');
        }

        Schema::create('likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('likeable');
            $table->timestampsTz();

            $table->unique(['user_id', 'likeable_type', 'likeable_id']);
        });

        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('followable');
            $table->timestampsTz();

            $table->unique(['user_id', 'followable_type', 'followable_id']);
        });

        Schema::create('reading_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->string('block_id', 64)->nullable();
            $table->unsignedTinyInteger('percent')->default(0);
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'work_item_id']);
            $table->index(['user_id', 'updated_at']);
            $table->index('work_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reading_progress');
        Schema::dropIfExists('follows');
        Schema::dropIfExists('likes');
        Schema::dropIfExists('comments');
    }
};
