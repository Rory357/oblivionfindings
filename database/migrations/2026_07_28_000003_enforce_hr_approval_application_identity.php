<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $chainCollision = DB::table('hr_approval_chains')
            ->select('process_type', 'name', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('process_type', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($chainCollision) {
            throw new RuntimeException('Cannot enforce application approval-chain identity while duplicate process/name rows exist.');
        }

        $stepCollision = DB::table('hr_approval_chain_steps')
            ->select('approval_chain_id', 'step_order', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('approval_chain_id', 'step_order')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($stepCollision) {
            throw new RuntimeException('Cannot enforce application approval-step identity while duplicate chain/order rows exist.');
        }

        $leaveRouteCollision = DB::table('hr_leave_approval_chains')
            ->select('user_id', 'approval_level', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('user_id', 'approval_level')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($leaveRouteCollision) {
            throw new RuntimeException('Cannot enforce application leave-route identity while duplicate user/level rows exist.');
        }

        Schema::table('hr_approval_chains', function (Blueprint $table): void {
            $table->dropIndex('hr_approval_chains_tenant_id_index');
            $table->dropIndex('hr_approval_chains_tenant_id_process_type_index');

            $table->unique(['process_type', 'name'], 'hr_approval_chain_process_name_uq');
            $table->index(['process_type', 'is_active'], 'hr_approval_chain_process_active_idx');
        });

        Schema::table('hr_approval_chain_steps', function (Blueprint $table): void {
            $table->unique(['approval_chain_id', 'step_order'], 'hr_approval_step_chain_order_uq');
        });

        Schema::table('hr_approval_instances', function (Blueprint $table): void {
            $table->dropIndex('hr_approval_instances_tenant_id_index');
            $table->dropIndex('hr_approval_instances_tenant_id_status_index');

            $table->index(['status', 'initiated_at'], 'hr_approval_instance_status_started_idx');
            $table->index(['approval_chain_id', 'status'], 'hr_approval_instance_chain_status_idx');
        });

        Schema::table('hr_leave_approval_chains', function (Blueprint $table): void {
            $table->dropIndex('hr_leave_approval_chains_tenant_id_index');
            $table->dropUnique('hr_leave_chain_tenant_user_level_unique');
            $table->dropIndex('hr_leave_chain_tenant_user_active_idx');

            $table->unique(['user_id', 'approval_level'], 'hr_leave_chain_user_level_uq');
            $table->index(['user_id', 'is_active', 'approval_level'], 'hr_leave_chain_user_active_level_idx');
            $table->index(['approver_user_id', 'is_active'], 'hr_leave_chain_approver_active_idx');
            $table->index(['delegate_user_id', 'is_active'], 'hr_leave_chain_delegate_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_approval_chains', function (Blueprint $table): void {
            $table->dropUnique('hr_leave_chain_user_level_uq');
            $table->dropIndex('hr_leave_chain_user_active_level_idx');
            $table->dropIndex('hr_leave_chain_approver_active_idx');
            $table->dropIndex('hr_leave_chain_delegate_active_idx');

            $table->index('tenant_id', 'hr_leave_approval_chains_tenant_id_index');
            $table->unique(['tenant_id', 'user_id', 'approval_level'], 'hr_leave_chain_tenant_user_level_unique');
            $table->index(['tenant_id', 'user_id', 'is_active'], 'hr_leave_chain_tenant_user_active_idx');
        });

        Schema::table('hr_approval_instances', function (Blueprint $table): void {
            $table->dropIndex('hr_approval_instance_status_started_idx');
            $table->dropIndex('hr_approval_instance_chain_status_idx');

            $table->index('tenant_id', 'hr_approval_instances_tenant_id_index');
            $table->index(['tenant_id', 'status'], 'hr_approval_instances_tenant_id_status_index');
        });

        Schema::table('hr_approval_chain_steps', function (Blueprint $table): void {
            $table->dropUnique('hr_approval_step_chain_order_uq');
        });

        Schema::table('hr_approval_chains', function (Blueprint $table): void {
            $table->dropUnique('hr_approval_chain_process_name_uq');
            $table->dropIndex('hr_approval_chain_process_active_idx');

            $table->index('tenant_id', 'hr_approval_chains_tenant_id_index');
            $table->index(['tenant_id', 'process_type'], 'hr_approval_chains_tenant_id_process_type_index');
        });
    }
};
