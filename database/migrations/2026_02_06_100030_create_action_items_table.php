<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_items', function (Blueprint $table) {
            $table->id();
            $table->string('action_reference')->unique(); // ACT-2026-001
            
            // Source tracking (polymorphic)
            $table->string('source_type'); // meeting, resolution, risk_review, compliance_review
            $table->unsignedBigInteger('source_id');
            
            // Action details
            $table->text('description');
            $table->foreignId('assigned_to')->constrained('users');
            $table->date('due_date');
            
            // Status: open, in_progress, complete, overdue, cancelled
            $table->string('status')->default('open');
            $table->datetime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('completion_notes')->nullable();
            
            // Evidence
            $table->boolean('evidence_required')->default(false);
            $table->json('evidence_attachments')->nullable();
            
            // Escalation
            $table->datetime('escalated_at')->nullable();
            $table->foreignId('escalated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('escalation_reason')->nullable();
            
            // Priority
            $table->string('priority')->default('medium'); // low, medium, high, critical
            
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['source_type', 'source_id']);
            $table->index(['assigned_to', 'status']);
            $table->index(['due_date', 'status']);
            $table->index(['status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_items');
    }
};
