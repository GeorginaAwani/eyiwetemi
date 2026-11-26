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
        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->jsonb('kinds');
            $table->string('manage_token', 64)->unique();
            $table->string('confirm_token_hash', 64)->nullable()->unique();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('newsletter_sends', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('scheduled_for')->index();
            $table->string('status', 32)->default('planned');
            $table->text('intro_lines')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('bounced_count')->default(0);
            $table->unsignedInteger('stopped_count')->default(0);
            $table->timestampsTz();
        });

        Schema::create('newsletter_send_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('newsletter_send_id')->constrained('newsletter_sends')->cascadeOnDelete();
            $table->morphs('item');
            $table->boolean('left_out')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();

            $table->unique(['newsletter_send_id', 'item_type', 'item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('newsletter_send_items');
        Schema::dropIfExists('newsletter_sends');
        Schema::dropIfExists('subscribers');
    }
};
