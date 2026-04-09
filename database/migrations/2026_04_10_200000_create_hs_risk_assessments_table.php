<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_risk_assessments', function (Blueprint $table) {
            $table->id();

            // ── Tenant isolation ──
            $table->unsignedBigInteger('organization_id')->nullable()->index();

            // ── Reference ──
            $table->string('reference_number', 24)->unique();

            // ── What is being assessed (polymorphic) ──
            $table->string('assessable_type', 100)->nullable();
            $table->unsignedBigInteger('assessable_id')->nullable();

            // ── Optional H&S backbone link ──
            $table->unsignedBigInteger('hs_event_id')->nullable();

            // ── Classification ──
            $table->string('title');
            $table->text('risk_description')->nullable();
            $table->string('status', 20)->default('draft');
            // Values: draft, active, under_review, superseded, archived

            // ── Inherent risk (before controls) ──
            $table->unsignedTinyInteger('likelihood');       // 1–5
            $table->unsignedTinyInteger('consequence');      // 1–5
            $table->unsignedTinyInteger('risk_score');       // likelihood × consequence (1–25)
            $table->string('risk_level', 10);               // low, medium, high, extreme

            // ── Existing controls ──
            $table->text('existing_controls')->nullable();

            // ── Additional controls to be implemented ──
            $table->text('additional_controls')->nullable();

            // ── Residual risk (after controls) ──
            $table->unsignedTinyInteger('residual_likelihood')->nullable();
            $table->unsignedTinyInteger('residual_consequence')->nullable();
            $table->unsignedTinyInteger('residual_risk_score')->nullable();
            $table->string('residual_risk_level', 10)->nullable();

            $table->boolean('risk_acceptable')->nullable();

            // ── Ownership & Review ──
            $table->foreignId('assessed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at')->nullable();

            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->date('review_due_at')->nullable();
            $table->unsignedSmallInteger('review_frequency_days')->nullable();

            // ── Version chain ──
            $table->unsignedBigInteger('superseded_by_id')->nullable();

            // ── Provenance ──
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // ── Indexes ──
            $table->index(['assessable_type', 'assessable_id']);
            $table->index(['status', 'review_due_at']);
            $table->index(['risk_level', 'status']);
            $table->index(['hs_event_id']);

            // ── Foreign keys ──
            $table->foreign('hs_event_id')->references('id')->on('hs_events')->nullOnDelete();
            $table->foreign('superseded_by_id')->references('id')->on('hs_risk_assessments')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_risk_assessments');
    }
};
