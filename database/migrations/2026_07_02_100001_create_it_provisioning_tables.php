<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IT & Provisioning subsystem (/it) — replaces the design-preview wireframe.
 *
 * Two queues per docs/IT_PROVISIONING_WIREFRAME.md:
 *  - it_provisioning_requests: onboarding-driven account/access/equipment work
 *    for a new hire, optionally linked to the source HrOnboardingTask so
 *    fulfilment can auto-complete the checklist task.
 *  - it_tickets: general helpdesk queue (not onboarding-driven).
 *
 * Long composite indexes are named explicitly (MySQL 64-char limit house rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_provisioning_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('employee_profile_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->foreignId('onboarding_task_id')->nullable()->constrained('hr_onboarding_tasks')->nullOnDelete();
            $table->string('type')->default('account'); // account | access | equipment | other
            $table->string('item');
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending'); // pending | in_progress | done | cancelled
            $table->string('external_ref')->nullable();
            $table->datetime('fulfilled_at')->nullable();
            $table->foreignId('fulfilled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'it_prov_requests_tenant_status_idx');
            $table->index(['employee_profile_id', 'status'], 'it_prov_requests_profile_status_idx');
        });

        Schema::create('it_tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category')->default('other'); // hardware | account | network | other
            $table->string('priority')->default('normal'); // low | normal | high | urgent
            $table->string('status')->default('open'); // open | in_progress | resolved | closed
            $table->datetime('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'it_tickets_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_tickets');
        Schema::dropIfExists('it_provisioning_requests');
    }
};
