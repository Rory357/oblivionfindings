<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_eligibility_rechecks', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 100);
            $table->unsignedBigInteger('source_id');
            $table->char('source_fingerprint', 64);
            $table->unsignedBigInteger('source_version')->default(1);
            $table->json('user_ids');
            $table->json('site_ids');
            $table->json('client_ids');
            $table->json('shift_ids');
            $table->boolean('all_assigned')->default(false);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->unsignedInteger('scanned_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamps();
            $table->unique(['source_type', 'source_id'], 'wf_recheck_source_unique');
            $table->index(['status', 'available_at'], 'wf_recheck_recovery');
        });

        Schema::create('workforce_eligibility_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shift_id')->unique()->constrained('shifts')->cascadeOnDelete();
            // Observation provenance only. Assignment commands always read current source evidence.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->char('shift_fingerprint', 64);
            $table->string('posture', 20)->default('unverified');
            $table->unsignedInteger('block_count')->nullable();
            $table->unsignedInteger('warning_count')->nullable();
            $table->json('failed_rules')->nullable();
            $table->unsignedBigInteger('condition_version')->default(0);
            $table->char('condition_fingerprint', 64)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('last_successful_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_eligibility_observations');
        Schema::dropIfExists('workforce_eligibility_rechecks');
    }
};
