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
        Schema::create('work_reviewer', function (Blueprint $table) {
            $table->foreignId('work_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['work_id', 'user_id']);
            $table->index('user_id');
            $table->timestampsTz();
        });

        Schema::create('reviewer_invites', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('name');
            $table->text('note')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->index('invited_by');
            $table->index('accepted_user_id');
        });

        Schema::create('reviewer_invite_work', function (Blueprint $table) {
            $table->foreignId('reviewer_invite_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_id')->constrained()->cascadeOnDelete();
            $table->primary(['reviewer_invite_id', 'work_id']);
            $table->index('work_id');
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviewer_invite_work');
        Schema::dropIfExists('reviewer_invites');
        Schema::dropIfExists('work_reviewer');
    }
};
