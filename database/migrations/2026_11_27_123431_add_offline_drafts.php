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
        Schema::table('work_items', function (Blueprint $table) {
            $table->string('draft_device', 16)->nullable()->after('draft_saved_at');
        });

        Schema::table('versions', function (Blueprint $table) {
            $table->string('device', 16)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->dropColumn('device');
        });

        Schema::table('work_items', function (Blueprint $table) {
            $table->dropColumn('draft_device');
        });
    }
};
