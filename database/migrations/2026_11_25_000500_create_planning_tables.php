<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('typed_as')->nullable();
            $table->jsonb('aliases')->nullable();
            $table->string('role')->nullable();
            $table->jsonb('notes')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();
        });

        Schema::create('character_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('other_character_id')->constrained('characters')->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->timestampsTz();

            $table->unique(['character_id', 'other_character_id']);
        });

        Schema::create('world_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();
        });

        Schema::create('world_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('world_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('typed_as')->nullable();
            $table->string('description')->nullable();
            $table->jsonb('notes')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();
        });

        Schema::create('plot_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('colour')->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();
        });

        Schema::create('plot_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plot_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->nullable()->constrained()->nullOnDelete();
            $table->text('text');
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();
        });

        Schema::create('research_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('title')->nullable();
            $table->string('url', 2048)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();
        });

        Schema::create('research_ties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_item_id')->constrained()->cascadeOnDelete();
            $table->morphs('tieable');
            $table->timestampsTz();

            $table->unique(['research_item_id', 'tieable_type', 'tieable_id']);
        });

        Schema::create('sweep_names', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained()->cascadeOnDelete();
            $table->string('plain');
            $table->jsonb('readings');
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();
        });

        Schema::table('work_items', function (Blueprint $table) {
            $table->foreign('pov_character_id')->references('id')->on('characters')->nullOnDelete();
            $table->index('pov_character_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropForeign(['pov_character_id']);
            $table->dropIndex(['pov_character_id']);
        });

        Schema::dropIfExists('sweep_names');
        Schema::dropIfExists('research_ties');
        Schema::dropIfExists('research_items');
        Schema::dropIfExists('plot_points');
        Schema::dropIfExists('plot_threads');
        Schema::dropIfExists('world_entries');
        Schema::dropIfExists('world_groups');
        Schema::dropIfExists('character_links');
        Schema::dropIfExists('characters');
    }
};
