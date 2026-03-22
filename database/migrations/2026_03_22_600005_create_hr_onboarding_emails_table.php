<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_onboarding_emails', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('template_name');
            $table->string('subject');
            $table->text('body');
            $table->integer('send_days_before_start')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('hr_onboarding_email_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_email_id')->constrained('hr_onboarding_emails')->cascadeOnDelete();
            $table->foreignId('employee_profile_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->datetime('sent_at')->nullable();
            $table->string('status', 20)->default('pending'); // pending, sent, failed
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['employee_profile_id', 'onboarding_email_id'], 'email_log_emp_email_idx');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_onboarding_email_log');
        Schema::dropIfExists('hr_onboarding_emails');
    }
};
