<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable talent-pool membership (D5 / handover item 22). Replaces the fragile
 * tags-based pool that ArchiveCandidateDataJob silently wiped. One membership
 * per candidate; survives candidate anonymisation because the archive job now
 * excludes pooled candidates.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_talent_pool')) {
            return;
        }

        Schema::create('hr_talent_pool', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('candidate_id')->constrained('hr_candidates')->cascadeOnDelete();
            $table->foreignId('requisition_id')->nullable()->constrained('hr_job_requisitions')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->foreignId('pooled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('tags')->nullable();
            $table->timestamps();
            $table->unique('candidate_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_talent_pool');
    }
};
