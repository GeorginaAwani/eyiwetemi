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
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('subject', 16);
            $table->unsignedBigInteger('subject_id')->default(0);
            $table->foreignId('work_id')->nullable()->constrained('works')->cascadeOnDelete();
            $table->unsignedBigInteger('reads')->default(0);
            $table->unsignedBigInteger('readers')->default(0);
            $table->unsignedBigInteger('finished')->default(0);
            $table->timestampsTz();

            $table->unique(['day', 'subject', 'subject_id']);
            $table->index(['work_id', 'day']);
        });

        Schema::create('daily_breakdowns', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('dimension', 16);
            $table->string('value', 255);
            $table->unsignedBigInteger('reads')->default(0);
            $table->timestampsTz();

            $table->unique(['day', 'dimension', 'value']);
        });

        Schema::create('daily_visitors', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('scope', 64);
            $table->string('hash', 64);

            $table->unique(['day', 'scope', 'hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_visitors');
        Schema::dropIfExists('daily_breakdowns');
        Schema::dropIfExists('daily_stats');
    }
};
