<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_inspection_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('inspection_type', 50)->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('frequency', ['weekly', 'monthly', 'quarterly', 'bi_annual', 'annual', 'custom']);
            $table->string('custom_rrule')->nullable();
            $table->date('first_due_date');
            $table->date('next_due_date')->index();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('auto_create_calendar_event')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['site_id', 'next_due_date', 'is_active']);
        });

        Schema::create('site_inspection_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('site_inspection_schedules');
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->date('due_date');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('result', ['pass', 'fail', 'partial', 'na'])->nullable();
            $table->text('findings')->nullable();
            $table->text('corrective_actions')->nullable();
            $table->foreignId('linked_hazard_id')->nullable();
            $table->foreignId('linked_checklist_run_id')->nullable();
            $table->json('evidence_photos')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'due_date', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_inspection_records');
        Schema::dropIfExists('site_inspection_schedules');
    }
};
