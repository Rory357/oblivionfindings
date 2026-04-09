<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_investigations', function (Blueprint $table) {
            $table->id();

            // ── Parent event ──
            $table->foreignId('hs_event_id')->constrained('hs_events')->cascadeOnDelete();

            // ── Tenant isolation ──
            $table->unsignedBigInteger('organization_id')->nullable()->index();

            // ── Reference ──
            $table->string('reference_number', 24)->unique();

            // ── Classification ──
            $table->string('investigation_type', 30)->default('standard');
            // Values: standard, full, worksafe_directed

            // ── Lifecycle ──
            $table->string('status', 30)->default('draft');
            // Values: draft → in_progress → findings_recorded → under_review → completed

            // ── Methodology ──
            $table->string('methodology', 30)->nullable();
            // Values: null (not yet selected), 5_whys, fishbone, bow_tie, icam, taproot, other

            // ── Team ──
            $table->foreignId('lead_investigator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('team_member_ids')->nullable();

            // ── Timeline ──
            $table->timestamp('started_at')->nullable();
            $table->date('target_completion_date')->nullable();
            $table->timestamp('completed_at')->nullable();

            // ── Structured Findings ──
            $table->json('immediate_causes')->nullable();
            // Array of: [{description: string, category?: string}]

            $table->json('root_causes')->nullable();
            // Array of: [{description: string, category?: string}]

            $table->json('contributing_factors')->nullable();
            // Array of: [{description: string, factor_type?: string}]
            // factor_type: human, environmental, procedural, organizational, equipment

            $table->text('findings_summary')->nullable();
            // Narrative synthesis of all findings

            $table->json('recommendations')->nullable();
            // Array of: [{description: string, priority?: string, target_area?: string}]
            // priority: low, medium, high, critical
            // target_area: training, procedure, equipment, environment, supervision

            $table->text('lessons_learned')->nullable();

            // ── Review / Approval ──
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // ── Provenance ──
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // ── Indexes ──
            $table->index(['hs_event_id', 'status']);
            $table->index(['lead_investigator_id', 'status']);
            $table->index(['status', 'created_at']);

            // ── Foreign keys ──
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_investigations');
    }
};
