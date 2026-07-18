<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_provisioning_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('lifecycle_type', 20);
            $table->string('position_role', 100)->nullable();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('employment_type', 50)->nullable();
            $table->integer('selection_priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['tenant_id', 'lifecycle_type', 'is_active'],
                'it_prov_templates_tenant_lifecycle_active_idx',
            );
        });

        Schema::create('it_provisioning_template_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provisioning_template_id')
                ->constrained('it_provisioning_templates')->cascadeOnDelete();
            $table->string('task_key', 100);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 50);
            $table->string('action', 30);
            $table->string('request_type', 30)->default('other');
            $table->foreignId('responsible_team_id')->nullable()->constrained('it_teams')->nullOnDelete();
            $table->unsignedSmallInteger('stage')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('dependency_task_keys')->nullable();
            $table->json('trigger_fields')->nullable();
            $table->boolean('approval_required')->default(false);
            $table->boolean('evidence_required')->default(false);
            $table->smallInteger('due_offset_days')->default(0);
            $table->json('fulfiller_fields')->nullable();
            $table->timestamps();

            $table->unique(
                ['provisioning_template_id', 'task_key'],
                'it_prov_template_tasks_template_key_uq',
            );
            $table->index(
                ['provisioning_template_id', 'stage', 'sort_order'],
                'it_prov_template_tasks_order_idx',
            );
        });

        Schema::create('it_provisioning_workflows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('employee_profile_id')->constrained('hr_employee_profiles')->restrictOnDelete();
            $table->foreignId('provisioning_template_id')->nullable()
                ->constrained('it_provisioning_templates')->nullOnDelete();
            $table->string('lifecycle_type', 20);
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->string('source_event_key', 191);
            $table->string('status', 30)->default('pending');
            $table->timestamp('effective_at')->nullable();
            $table->string('role_snapshot', 100)->nullable();
            $table->foreignId('site_id_snapshot')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('employment_type_snapshot', 50)->nullable();
            $table->json('changes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'source_event_key'], 'it_prov_workflows_tenant_event_uq');
            $table->index(
                ['tenant_id', 'lifecycle_type', 'status'],
                'it_prov_workflows_tenant_lifecycle_status_idx',
            );
            $table->index(
                ['employee_profile_id', 'created_at'],
                'it_prov_workflows_profile_created_idx',
            );
        });

        Schema::table('it_provisioning_requests', function (Blueprint $table): void {
            $table->foreignId('provisioning_workflow_id')->nullable()
                ->after('employee_profile_id')->constrained('it_provisioning_workflows')->cascadeOnDelete();
            $table->foreignId('provisioning_template_task_id')->nullable()
                ->after('provisioning_workflow_id')->constrained('it_provisioning_template_tasks')->nullOnDelete();
            $table->foreignId('offboarding_task_id')->nullable()
                ->after('onboarding_task_id')->constrained('hr_offboarding_tasks')->nullOnDelete();
            $table->string('task_key', 100)->nullable()->after('type');
            $table->string('action', 30)->default('provision')->after('task_key');
            $table->string('category', 50)->nullable()->after('action');
            $table->foreignId('responsible_team_id')->nullable()
                ->after('assigned_to_user_id')->constrained('it_teams')->nullOnDelete();
            $table->unsignedSmallInteger('stage')->default(1)->after('responsible_team_id');
            $table->json('dependency_request_ids')->nullable()->after('stage');
            $table->boolean('approval_required')->default(false)->after('dependency_request_ids');
            $table->string('approval_status', 30)->default('not_required')->after('approval_required');
            $table->foreignId('approved_by_user_id')->nullable()
                ->after('approval_status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');
            $table->boolean('evidence_required')->default(false)->after('approved_at');
            $table->text('evidence_summary')->nullable()->after('evidence_required');
            $table->text('failure_reason')->nullable()->after('evidence_summary');
            $table->timestamp('failed_at')->nullable()->after('failure_reason');
            $table->json('fulfiller_context')->nullable()->after('failed_at');
            $table->string('canonical_target_type', 50)->nullable()->after('fulfiller_context');
            $table->unsignedBigInteger('canonical_target_id')->nullable()->after('canonical_target_type');

            $table->unique(
                ['provisioning_workflow_id', 'task_key'],
                'it_prov_requests_workflow_task_key_uq',
            );
            $table->index(
                ['provisioning_workflow_id', 'stage', 'status'],
                'it_prov_requests_workflow_stage_status_idx',
            );
            $table->index(
                ['canonical_target_type', 'canonical_target_id'],
                'it_prov_requests_canonical_target_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('it_provisioning_requests', function (Blueprint $table): void {
            $table->dropForeign(['provisioning_workflow_id']);
            $table->dropForeign(['provisioning_template_task_id']);
            $table->dropForeign(['offboarding_task_id']);
            $table->dropForeign(['responsible_team_id']);
            $table->dropForeign(['approved_by_user_id']);
            $table->dropUnique('it_prov_requests_workflow_task_key_uq');
            $table->dropIndex('it_prov_requests_workflow_stage_status_idx');
            $table->dropIndex('it_prov_requests_canonical_target_idx');
            $table->dropColumn([
                'provisioning_workflow_id', 'provisioning_template_task_id', 'offboarding_task_id',
                'task_key', 'action', 'category', 'responsible_team_id', 'stage', 'dependency_request_ids',
                'approval_required', 'approval_status', 'approved_by_user_id', 'approved_at',
                'evidence_required', 'evidence_summary', 'failure_reason', 'failed_at',
                'fulfiller_context', 'canonical_target_type', 'canonical_target_id',
            ]);
        });

        Schema::dropIfExists('it_provisioning_workflows');
        Schema::dropIfExists('it_provisioning_template_tasks');
        Schema::dropIfExists('it_provisioning_templates');
    }
};
