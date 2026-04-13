<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_protocol_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinical_protocol_id')->constrained()->cascadeOnDelete();

            $table->timestamp('due_at');
            $table->string('status')->default('pending'); // pending, completed, missed, skipped
            $table->string('skip_reason')->nullable();

            // Completion tracking
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('clinical_observation_id')->nullable();
            $table->foreignId('shift_task_id')->nullable();

            $table->timestamps();

            $table->index(['clinical_protocol_id', 'status', 'due_at'], 'clin_sched_protocol_status_due');
            $table->index(['due_at', 'status'], 'clin_sched_due_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_protocol_schedules');
    }
};
