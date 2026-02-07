<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_treatments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_register_entry_id')->constrained()->onDelete('cascade');
            
            // Treatment action
            $table->text('action_description');
            $table->foreignId('assigned_to')->constrained('users');
            $table->date('due_date');
            
            // Status: planned, in_progress, complete, overdue, cancelled
            $table->string('status')->default('planned');
            $table->datetime('started_at')->nullable();
            $table->datetime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            
            // Evidence
            $table->boolean('evidence_required')->default(false);
            $table->json('evidence_attachments')->nullable();
            $table->text('completion_evidence')->nullable();
            
            // Expected impact on risk score
            $table->tinyInteger('expected_score_reduction')->nullable();
            $table->boolean('score_reduced')->default(false);
            
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['risk_register_entry_id', 'status']);
            $table->index(['assigned_to', 'status']);
            $table->index(['due_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_treatments');
    }
};
