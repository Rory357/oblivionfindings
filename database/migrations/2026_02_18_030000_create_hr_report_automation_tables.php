<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_report_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('report_type');
            $table->string('cadence')->default('weekly');
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->time('run_at')->default('08:00:00');
            $table->string('timezone')->default('Pacific/Auckland');
            $table->json('filters')->nullable();
            $table->json('recipient_user_ids')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_run_at')->nullable();
            $table->dateTime('next_run_at')->nullable()->index();
            $table->string('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'next_run_at'], 'hr_report_sub_active_next_idx');
            $table->index(['tenant_id', 'report_type'], 'hr_report_sub_tenant_type_idx');
        });

        Schema::create('hr_report_exports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('subscription_id')->nullable()->constrained('hr_report_subscriptions')->nullOnDelete();
            $table->string('report_type');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->json('filters')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->string('storage_path');
            $table->string('export_format')->default('csv');
            $table->dateTime('generated_at')->index();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'generated_at'], 'hr_report_export_tenant_generated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_report_exports');
        Schema::dropIfExists('hr_report_subscriptions');
    }
};

