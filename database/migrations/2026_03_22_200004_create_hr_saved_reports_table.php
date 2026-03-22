<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_saved_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('report_type'); // employee, leave, compliance, payroll, training, time, custom
            $table->json('fields'); // selected columns
            $table->json('filters')->nullable(); // applied filters
            $table->string('group_by')->nullable();
            $table->string('sort_by')->nullable();
            $table->string('sort_direction')->default('asc');
            $table->boolean('is_scheduled')->default(false);
            $table->string('schedule_frequency')->nullable(); // weekly, monthly
            $table->json('schedule_recipients')->nullable(); // user IDs
            $table->dateTime('last_run_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'report_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_saved_reports');
    }
};
