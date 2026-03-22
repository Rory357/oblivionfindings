<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_approval_chains', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('process_type'); // leave, expense, timesheet, document
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'process_type']);
        });

        Schema::create('hr_approval_chain_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_chain_id')->constrained('hr_approval_chains')->cascadeOnDelete();
            $table->integer('step_order');
            $table->string('approver_type'); // manager, role, user
            $table->foreignId('approver_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('auto_approve_after_days')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('hr_approval_instances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('approval_chain_id')->constrained('hr_approval_chains')->cascadeOnDelete();
            $table->string('approvable_type');
            $table->unsignedBigInteger('approvable_id');
            $table->integer('current_step')->default(1);
            $table->string('status')->default('pending'); // pending, approved, rejected, escalated
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('initiated_at');
            $table->datetime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['approvable_type', 'approvable_id']);
        });

        Schema::create('hr_approval_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_instance_id')->constrained('hr_approval_instances')->cascadeOnDelete();
            $table->integer('step_order');
            $table->string('action'); // approved, rejected, escalated
            $table->foreignId('actioned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->datetime('actioned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_approval_actions');
        Schema::dropIfExists('hr_approval_instances');
        Schema::dropIfExists('hr_approval_chain_steps');
        Schema::dropIfExists('hr_approval_chains');
    }
};
