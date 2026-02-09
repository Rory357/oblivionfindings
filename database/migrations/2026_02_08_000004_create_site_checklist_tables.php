<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('applicable_to_type', ['head_office', 'house', 'facility', 'all'])->default('all');
            $table->enum('frequency', ['once', 'daily', 'weekly', 'fortnightly', 'monthly', 'quarterly', 'custom'])->default('monthly');
            $table->string('custom_rrule')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('site_checklist_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('site_checklist_templates')->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->string('question');
            $table->enum('response_type', ['yes_no', 'yes_no_na', 'pass_fail', 'numeric', 'text', 'photo'])->default('yes_no');
            $table->json('response_config')->nullable();
            $table->boolean('is_required')->default(true);
            $table->text('guidance')->nullable();
            $table->string('failure_creates_hazard')->nullable();
            $table->timestamps();
        });

        Schema::create('site_checklist_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('site_checklist_templates');
            $table->enum('frequency', ['once', 'daily', 'weekly', 'fortnightly', 'monthly', 'quarterly', 'custom']);
            $table->string('custom_rrule')->nullable();
            $table->foreignId('assigned_to_role_id')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['site_id', 'template_id']);
        });

        Schema::create('site_checklist_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('site_checklist_assignments');
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('site_checklist_templates');
            $table->date('scheduled_date');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'overdue', 'skipped'])->default('scheduled');
            $table->decimal('completion_percentage', 5, 2)->default(0);
            $table->integer('items_passed')->default(0);
            $table->integer('items_failed')->default(0);
            $table->text('overall_notes')->nullable();
            $table->json('photos')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status', 'scheduled_date']);
            $table->index(['scheduled_date', 'status']);
        });

        Schema::create('site_checklist_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('site_checklist_runs')->cascadeOnDelete();
            $table->foreignId('template_item_id')->constrained('site_checklist_template_items');
            $table->string('response_value', 50)->nullable();
            $table->text('notes')->nullable();
            $table->string('photo_path')->nullable();
            $table->boolean('is_failed')->default(false);
            $table->foreignId('created_hazard_id')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'template_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_checklist_responses');
        Schema::dropIfExists('site_checklist_runs');
        Schema::dropIfExists('site_checklist_assignments');
        Schema::dropIfExists('site_checklist_template_items');
        Schema::dropIfExists('site_checklist_templates');
    }
};
