<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Leave requests
        Schema::create('hr_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('leave_type');
            $table->datetime('starts_at');
            $table->datetime('ends_at');
            $table->decimal('hours_requested', 8, 2);
            $table->text('reason')->nullable();
            $table->string('supporting_doc_path')->nullable();
            $table->string('status')->default('draft');
            $table->datetime('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('escalated_to')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('time_off_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['tenant_id', 'status', 'starts_at']);
            $table->index(['user_id', 'leave_type']);
        });

        // Leave balances
        Schema::create('hr_leave_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('leave_type');
            $table->decimal('balance_hours', 10, 2)->default(0);
            $table->decimal('accrued_hours', 10, 2)->default(0);
            $table->decimal('used_hours', 10, 2)->default(0);
            $table->decimal('pending_hours', 10, 2)->default(0);
            $table->integer('year');
            $table->string('source')->default('system');
            $table->datetime('last_synced_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'leave_type', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_balances');
        Schema::dropIfExists('hr_leave_requests');
    }
};
