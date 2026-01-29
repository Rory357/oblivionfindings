<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procedure_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('domain')->index();
            $table->unsignedInteger('version')->default(1);
            $table->string('trigger_event')->nullable();
            $table->text('description')->nullable();
            $table->json('steps_json');
            $table->json('required_roles')->nullable();
            $table->boolean('active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['name', 'domain', 'version']);
            $table->index(['domain', 'active']);
        });

        Schema::create('procedure_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_template_id')->constrained('procedure_templates')->cascadeOnDelete();
            $table->morphs('subject', 'procedure_run_subject_idx');
            $table->enum('status', [
                'not_started',
                'in_progress',
                'blocked',
                'completed',
                'failed',
                'aborted',
            ])->default('not_started');
            $table->json('context')->nullable();
            $table->json('version_snapshot')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('blocked_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['procedure_template_id', 'status']);
        });

        Schema::create('procedure_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_run_id')->constrained('procedure_runs')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', [
                'open',
                'in_progress',
                'done',
                'blocked',
                'overdue',
                'cancelled',
            ])->default('open');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->unsignedInteger('sla_minutes')->nullable();
            $table->json('required_evidence')->nullable();
            $table->json('checklist')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['procedure_run_id', 'status']);
            $table->index(['assignee_id', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procedure_tasks');
        Schema::dropIfExists('procedure_runs');
        Schema::dropIfExists('procedure_templates');
    }
};
