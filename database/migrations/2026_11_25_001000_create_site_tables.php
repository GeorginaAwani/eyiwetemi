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
        Schema::create('site_pages', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            $table->jsonb('draft')->nullable();
            $table->jsonb('live')->nullable();
            $table->timestampTz('draft_saved_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->jsonb('value')->nullable();
            $table->timestampsTz();
        });

        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->string('text', 140);
            $table->string('link_target', 64)->nullable();
            $table->string('link_url', 2048)->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->timestampTz('taken_down_at')->nullable();
            $table->unsignedInteger('closed_count')->default(0);
            $table->timestampsTz();
        });

        Schema::create('link_checks', function (Blueprint $table) {
            $table->id();
            $table->string('url_hash', 64)->unique();
            $table->string('url', 2048);
            $table->boolean('ok');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->timestampTz('checked_at');
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('link_checks');
        Schema::dropIfExists('notices');
        Schema::dropIfExists('site_settings');
        Schema::dropIfExists('site_pages');
    }
};
