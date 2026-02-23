<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_automation_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('event_type');
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->boolean('is_active')->default(true);
            $table->boolean('stop_on_match')->default(false);
            $table->dateTime('last_ran_at')->nullable();
            $table->string('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'event_type', 'is_active'], 'hr_automation_rule_tenant_event_active_idx');
            $table->unique(['tenant_id', 'name'], 'hr_automation_rule_tenant_name_unique');
        });

        Schema::create('hr_automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('hr_automation_rules')->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('event_type');
            $table->json('event_payload')->nullable();
            $table->string('status');
            $table->text('message')->nullable();
            $table->dateTime('executed_at');
            $table->timestamps();

            $table->index(['tenant_id', 'event_type', 'executed_at'], 'hr_automation_run_tenant_event_executed_idx');
            $table->index(['rule_id', 'status'], 'hr_automation_run_rule_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_automation_runs');
        Schema::dropIfExists('hr_automation_rules');
    }
};
