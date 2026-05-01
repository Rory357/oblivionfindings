<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_performance_improvement_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('employee_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('manager_user_id')->constrained('users');
            $table->string('title');
            $table->text('reason');
            $table->text('expectations');
            $table->text('support_offered')->nullable();
            $table->text('consequences')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->date('review_date')->nullable();
            $table->string('status')->default('active');
            $table->string('outcome')->nullable();
            $table->text('outcome_notes')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('hr_pip_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pip_id')->constrained('hr_performance_improvement_plans')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('due_date');
            $table->string('status')->default('pending');
            $table->string('outcome')->nullable();
            $table->text('evidence')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->datetime('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_pip_milestones');
        Schema::dropIfExists('hr_performance_improvement_plans');
    }
};
