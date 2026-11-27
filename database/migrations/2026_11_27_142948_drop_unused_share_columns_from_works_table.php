<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Link previews live in the 'sharing' site setting, so these per-work columns are unused.
     */
    public function up(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->dropForeign(['share_media_id']);
            $table->dropColumn(['share_line', 'share_media_id', 'share_uses_own']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->string('share_line')->nullable()->after('mark_names');
            $table->foreignId('share_media_id')->nullable()->after('share_line')->constrained('media')->nullOnDelete();
            $table->boolean('share_uses_own')->default(false)->after('share_media_id');
        });
    }
};
