<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_teams', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'it_teams_tenant_name_uq');
        });

        Schema::create('it_team_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained('it_teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role')->default('member');
            $table->timestamps();

            $table->unique(['team_id', 'user_id'], 'it_team_members_team_user_uq');
        });

        Schema::create('it_queues', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('team_id')->nullable()->constrained('it_teams')->nullOnDelete();
            $table->string('key');
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('filter_rules')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'key'], 'it_queues_tenant_key_uq');
            $table->index(['tenant_id', 'team_id', 'is_active'], 'it_queues_tenant_team_active_idx');
        });

        Schema::create('it_services', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('key');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('operational');
            $table->string('criticality')->default('medium');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'key'], 'it_services_tenant_key_uq');
            $table->index(['tenant_id', 'status', 'is_active'], 'it_services_tenant_status_active_idx');
        });

        Schema::table('it_tickets', function (Blueprint $table): void {
            $table->foreignId('requested_for_user_id')->nullable()->after('requester_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->after('assigned_to_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->after('asset_id')->constrained('sites')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->after('site_id')->constrained('it_teams')->nullOnDelete();
            $table->foreignId('queue_id')->nullable()->after('team_id')->constrained('it_queues')->nullOnDelete();
            $table->foreignId('it_service_id')->nullable()->after('queue_id')->constrained('it_services')->nullOnDelete();
            $table->string('workflow_state')->default('submitted')->after('work_type');
            $table->boolean('is_sensitive')->default(false)->after('urgency');
            $table->string('waiting_party')->nullable()->after('waiting_reason');
            $table->text('next_action')->nullable()->after('waiting_party');
            $table->timestamp('due_at')->nullable()->after('resolution_due_at');

            $table->index(['tenant_id', 'queue_id', 'status'], 'it_tickets_tenant_queue_status_idx');
            $table->index(['tenant_id', 'team_id', 'status'], 'it_tickets_tenant_team_status_idx');
            $table->index(['tenant_id', 'it_service_id', 'status'], 'it_tickets_tenant_service_status_idx');
            $table->index(['tenant_id', 'work_type', 'workflow_state'], 'it_tickets_tenant_type_workflow_idx');
        });

        DB::table('it_tickets')
            ->where('status', 'in_progress')
            ->update(['workflow_state' => 'in_progress']);
        DB::table('it_tickets')
            ->where('status', 'waiting')
            ->update(['workflow_state' => 'waiting']);
        DB::table('it_tickets')
            ->where('status', 'resolved')
            ->update(['workflow_state' => 'resolved']);
        DB::table('it_tickets')
            ->where('status', 'closed')
            ->update(['workflow_state' => 'closed']);

        Schema::create('it_work_tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('ticket_id')->constrained('it_tickets')->cascadeOnDelete();
            $table->foreignId('parent_task_id')->nullable()->constrained('it_work_tasks')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('it_teams')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('due_at')->nullable();
            $table->boolean('is_required')->default(true);
            $table->boolean('evidence_required')->default(false);
            $table->json('evidence')->nullable();
            $table->text('completion_note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'ticket_id', 'status'], 'it_work_tasks_tenant_ticket_status_idx');
            $table->index(['tenant_id', 'team_id', 'status'], 'it_work_tasks_tenant_team_status_idx');
            $table->index(['tenant_id', 'assigned_to_user_id', 'status'], 'it_work_tasks_tenant_assignee_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_work_tasks');

        Schema::table('it_tickets', function (Blueprint $table): void {
            $table->dropIndex('it_tickets_tenant_queue_status_idx');
            $table->dropIndex('it_tickets_tenant_team_status_idx');
            $table->dropIndex('it_tickets_tenant_service_status_idx');
            $table->dropIndex('it_tickets_tenant_type_workflow_idx');

            $table->dropConstrainedForeignId('requested_for_user_id');
            $table->dropConstrainedForeignId('owner_user_id');
            $table->dropConstrainedForeignId('site_id');
            $table->dropConstrainedForeignId('team_id');
            $table->dropConstrainedForeignId('queue_id');
            $table->dropConstrainedForeignId('it_service_id');
            $table->dropColumn([
                'workflow_state',
                'is_sensitive',
                'waiting_party',
                'next_action',
                'due_at',
            ]);
        });

        Schema::dropIfExists('it_queues');
        Schema::dropIfExists('it_team_members');
        Schema::dropIfExists('it_services');
        Schema::dropIfExists('it_teams');
    }
};
