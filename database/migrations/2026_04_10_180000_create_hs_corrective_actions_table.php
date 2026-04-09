<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_corrective_actions', function (Blueprint $table) {
            $table->id();

            // ── Parent references ──
            $table->foreignId('hs_event_id')->constrained('hs_events')->cascadeOnDelete();
            $table->foreignId('hs_investigation_id')->nullable()->constrained('hs_investigations')->nullOnDelete();

            // ── Tenant isolation ──
            $table->unsignedBigInteger('organization_id')->nullable()->index();

            // ── Reference ──
            $table->string('reference_number', 24)->unique();

            // ── Recommendation source tracking ──
            // Index into the HsInvestigation.recommendations JSON array.
            // Prevents duplicate actions for the same recommendation.
            $table->unsignedSmallInteger('recommendation_index')->nullable();

            // ── Classification ──
            $table->string('action_type', 20)->default('corrective');
            // Values: corrective, preventive, improvement

            $table->string('priority', 20)->default('medium');
            // Values: low, medium, high, critical

            // ── Content ──
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('root_cause_link')->nullable();

            // ── Lifecycle ──
            $table->string('status', 30)->default('open');
            // Values: open → in_progress → completed → verified → closed

            // ── Assignment ──
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            // ── Due date ──
            $table->date('due_date')->nullable();

            // ── Completion ──
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('completion_notes')->nullable();
            $table->json('completion_evidence_paths')->nullable();

            // ── Verification ──
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('verification_notes')->nullable();
            $table->boolean('effectiveness_confirmed')->nullable();

            // ── Closure ──
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // ── Provenance ──
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // ── Indexes ──
            $table->index(['hs_event_id', 'status']);
            $table->index(['hs_investigation_id', 'status']);
            $table->index(['assigned_to_user_id', 'status']);
            $table->index(['status', 'due_date']);
            $table->index(['status', 'created_at']);

            // ── Duplicate prevention: one action per recommendation per investigation ──
            $table->unique(
                ['hs_investigation_id', 'recommendation_index'],
                'hs_ca_investigation_recommendation_unique'
            );

            // ── Foreign keys ──
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_corrective_actions');
    }
};
