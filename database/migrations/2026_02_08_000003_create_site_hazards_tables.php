<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_hazards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->index();

            // Hazard identification
            $table->string('reference_number', 50)->unique();
            $table->string('hazard_type', 50)->index();
            $table->string('custom_hazard_type')->nullable();

            // Risk Assessment Matrix
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->index();
            $table->enum('likelihood', ['rare', 'unlikely', 'possible', 'likely', 'almost_certain']);
            $table->enum('risk_rating', ['low', 'medium', 'high', 'extreme'])->index();

            // Details
            $table->text('description');
            $table->json('photo_paths')->nullable();
            $table->text('immediate_action_taken')->nullable();
            $table->boolean('immediate_action_applied')->default(false);

            // Assignment
            $table->foreignId('reported_by_user_id')->constrained('users');
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            // Workflow
            $table->enum('status', ['open', 'in_progress', 'mitigated', 'closed', 'reopened'])->default('open')->index();
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('status_changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Closeout
            $table->text('resolution_summary')->nullable();
            $table->json('resolution_evidence')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Due dates
            $table->date('due_date')->nullable()->index();
            $table->date('review_date')->nullable();

            // Related entities
            $table->foreignId('linked_inspection_id')->nullable();
            $table->foreignId('linked_checklist_run_id')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['site_id', 'status', 'severity']);
            $table->index(['assigned_to_user_id', 'status']);
            $table->index(['due_date', 'status']);
        });

        Schema::create('site_hazard_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hazard_id')->constrained('site_hazards')->cascadeOnDelete();
            $table->text('action_description');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('completion_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_hazard_actions');
        Schema::dropIfExists('site_hazards');
    }
};
