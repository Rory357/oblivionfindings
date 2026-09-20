<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_work_orders', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(0);
            $table->string('waiting_reason', 64)->nullable();
            $table->text('next_action')->nullable();
            $table->index(['status', 'waiting_reason', 'due_at'], 'fleet_wo_progress_due_idx');
        });

        Schema::create('fleet_maintenance_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->string('asset_category', 64);
            $table->string('rule_kind', 32);
            $table->unsignedInteger('version');
            $table->json('rules_json');
            $table->char('content_sha256', 64);
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['site_id', 'asset_category', 'rule_kind', 'version'], 'fleet_mpv_site_category_kind_version_uq');
            $table->index(['site_id', 'asset_category', 'rule_kind', 'approved_at'], 'fleet_mpv_approved_idx');
        });

        Schema::create('fleet_maintenance_policy_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->string('asset_category', 64);
            $table->string('rule_kind', 32);
            $table->foreignId('policy_version_id')->constrained('fleet_maintenance_policy_versions')->restrictOnDelete();
            $table->foreignId('assigned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('assigned_at');
            $table->timestamps();
            $table->unique(['site_id', 'asset_category', 'rule_kind'], 'fleet_mpa_site_category_kind_uq');
        });

        Schema::create('fleet_maintenance_site_routes', function (Blueprint $table): void {
            $table->foreignId('site_id')->primary()->constrained('sites')->restrictOnDelete();
            $table->foreignId('coordinator_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('backup_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index('coordinator_user_id', 'fleet_msr_coordinator_idx');
            $table->index('backup_user_id', 'fleet_msr_backup_idx');
        });

        // Each grant or revocation is a new row. The latest version is the
        // current decision, preserving who approved or revoked earlier grants.
        Schema::create('fleet_maintenance_reviewer_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('asset_category', 64);
            $table->string('review_kind', 32);
            $table->unsignedInteger('version');
            $table->string('decision', 16);
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('recorded_at');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['site_id', 'user_id', 'asset_category', 'review_kind', 'version'], 'fleet_mrg_scope_version_uq');
            $table->index(['site_id', 'asset_category', 'review_kind', 'user_id', 'version'], 'fleet_mrg_current_idx');
        });

        Schema::create('fleet_maintenance_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('fleet_work_orders')->restrictOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('submitted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('observed_at')->nullable();
            $table->dateTime('submitted_at');
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('request_key', 100);
            $table->char('request_fingerprint', 64);
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('estimated_start_date')->nullable();
            $table->date('estimated_end_date')->nullable();
            $table->foreignId('duplicate_of_report_id')->nullable()->constrained('fleet_maintenance_reports')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['submitted_by_user_id', 'request_key'], 'fleet_mr_actor_request_uq');
            $table->index(['work_order_id', 'submitted_at'], 'fleet_mr_work_submitted_idx');
            $table->index(['asset_id', 'submitted_at'], 'fleet_mr_asset_submitted_idx');
            $table->index(['site_id', 'submitted_at'], 'fleet_mr_site_submitted_idx');
            $table->index(['source_type', 'source_id'], 'fleet_mr_source_idx');
        });

        Schema::table('fleet_checklist_runs', function (Blueprint $table): void {
            $table->foreignId('work_order_id')->nullable()->constrained('fleet_work_orders')->restrictOnDelete();
            $table->foreignId('source_report_id')->nullable()->constrained('fleet_maintenance_reports')->restrictOnDelete();
            $table->foreignId('rule_version_id')->nullable()->constrained('fleet_maintenance_policy_versions')->restrictOnDelete();
            $table->json('rule_snapshot_json')->nullable();
            $table->json('presented_template_json')->nullable();
            $table->char('rule_sha256', 64)->nullable();
            $table->string('outcome', 32)->nullable();
            $table->string('check_kind', 32)->nullable();
            $table->foreignId('corrects_run_id')->nullable()->constrained('fleet_checklist_runs')->restrictOnDelete();
            $table->json('covered_restriction_ids_json')->nullable();
            $table->dateTime('observed_at')->nullable();
            $table->dateTime('submitted_at', 6)->nullable();
            $table->string('request_key', 100)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->unique(['user_id', 'request_key'], 'fleet_cr_actor_request_uq');
            $table->index(['work_order_id', 'check_kind', 'submitted_at'], 'fleet_cr_work_kind_submitted_idx');
            $table->index(['asset_id', 'outcome', 'submitted_at'], 'fleet_cr_asset_outcome_idx');
        });

        Schema::create('fleet_maintenance_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('fleet_work_orders')->restrictOnDelete();
            $table->string('action_type', 48);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('check_run_id')->nullable()->constrained('fleet_checklist_runs')->restrictOnDelete();
            $table->foreignId('policy_version_id')->nullable()->constrained('fleet_maintenance_policy_versions')->restrictOnDelete();
            $table->unsignedInteger('expected_version');
            $table->unsignedInteger('resulting_version');
            $table->string('idempotency_key', 100);
            $table->char('payload_sha256', 64);
            $table->json('payload_json');
            $table->dateTime('occurred_at', 6);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['work_order_id', 'idempotency_key'], 'fleet_ma_work_request_uq');
            $table->unique(['work_order_id', 'resulting_version'], 'fleet_ma_work_version_uq');
            $table->index(['work_order_id', 'action_type', 'occurred_at'], 'fleet_ma_work_type_time_idx');
        });

        Schema::create('fleet_maintenance_restrictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('fleet_work_orders')->restrictOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->foreignId('source_run_id')->nullable()->constrained('fleet_checklist_runs')->restrictOnDelete();
            $table->foreignId('created_action_id')->nullable()->constrained('fleet_maintenance_actions')->restrictOnDelete();
            $table->string('source_key', 120)->unique();
            $table->string('restriction_kind', 32);
            $table->string('state', 24);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('released_at')->nullable();
            $table->foreignId('release_action_id')->nullable()->constrained('fleet_maintenance_actions')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->index(['asset_id', 'state'], 'fleet_mrest_asset_state_idx');
            $table->index(['work_order_id', 'state'], 'fleet_mrest_work_state_idx');
        });

        Schema::create('fleet_maintenance_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('fleet_work_orders')->restrictOnDelete();
            $table->foreignId('report_id')->nullable()->constrained('fleet_maintenance_reports')->restrictOnDelete();
            $table->foreignId('check_run_id')->nullable()->constrained('fleet_checklist_runs')->restrictOnDelete();
            $table->foreignId('action_id')->nullable()->constrained('fleet_maintenance_actions')->restrictOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('disk', 32);
            $table->string('path', 512);
            $table->string('original_name');
            $table->string('category', 64)->nullable();
            $table->text('description')->nullable();
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64);
            $table->string('request_key', 100);
            $table->char('request_fingerprint', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['uploaded_by_user_id', 'request_key'], 'fleet_matt_actor_request_uq');
            $table->index(['work_order_id', 'created_at'], 'fleet_matt_work_created_idx');
        });

        Schema::create('fleet_maintenance_effects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('fleet_work_orders')->restrictOnDelete();
            $table->foreignId('action_id')->constrained('fleet_maintenance_actions')->restrictOnDelete();
            $table->string('effect_key', 150)->unique();
            $table->string('effect_kind', 48);
            $table->json('payload_json');
            $table->string('state', 24);
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['state', 'next_attempt_at'], 'fleet_me_state_retry_idx');
        });

        Schema::create('fleet_maintenance_fin_bill_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('fleet_work_orders')->restrictOnDelete();
            $table->foreignId('fin_bill_id')->constrained('fin_bills')->restrictOnDelete();
            $table->foreignId('linked_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['work_order_id', 'fin_bill_id'], 'fleet_mfbl_work_bill_uq');
            $table->unique('fin_bill_id', 'fleet_mfbl_bill_uq');
        });
    }

    public function down(): void
    {
        \App\Services\Fleet\MaintenanceRollbackGuard::assertEmpty();
        foreach (['fleet_maintenance_reports', 'fleet_maintenance_actions', 'fleet_maintenance_restrictions',
            'fleet_maintenance_attachments', 'fleet_maintenance_effects', 'fleet_maintenance_fin_bill_links',
            'fleet_maintenance_reviewer_grants'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('PKG-01 evidence exists; preserve it and stop new writes instead of dropping audit records.');
            }
        }

        Schema::dropIfExists('fleet_maintenance_fin_bill_links');
        Schema::dropIfExists('fleet_maintenance_effects');
        Schema::dropIfExists('fleet_maintenance_attachments');
        Schema::dropIfExists('fleet_maintenance_restrictions');
        Schema::dropIfExists('fleet_maintenance_actions');
        // MySQL may replace the legacy asset FK index with our composite one.
        // Restore a standalone index before removing that package index.
        $assetIndexed = collect(Schema::getIndexes('fleet_checklist_runs'))
            ->contains(fn ($index) => $index['columns'] === ['asset_id']);
        if (! $assetIndexed) Schema::table('fleet_checklist_runs', fn (Blueprint $table) =>
            $table->index('asset_id', 'fleet_cr_asset_restored_idx'));
        Schema::table('fleet_checklist_runs', function (Blueprint $table): void {
            $table->dropForeign(['work_order_id']);
            $table->dropUnique('fleet_cr_actor_request_uq');
            $table->dropIndex('fleet_cr_work_kind_submitted_idx');
            $table->dropIndex('fleet_cr_asset_outcome_idx');
            $table->dropConstrainedForeignId('corrects_run_id');
            $table->dropConstrainedForeignId('rule_version_id');
            $table->dropConstrainedForeignId('source_report_id');
            $table->dropColumn('work_order_id');
            $table->dropColumn(['rule_snapshot_json', 'presented_template_json', 'covered_restriction_ids_json', 'rule_sha256', 'outcome', 'check_kind', 'observed_at', 'submitted_at', 'request_key', 'request_fingerprint']);
        });
        Schema::dropIfExists('fleet_maintenance_reports');
        Schema::dropIfExists('fleet_maintenance_reviewer_grants');
        Schema::dropIfExists('fleet_maintenance_site_routes');
        Schema::dropIfExists('fleet_maintenance_policy_assignments');
        Schema::dropIfExists('fleet_maintenance_policy_versions');
        Schema::table('fleet_work_orders', function (Blueprint $table): void {
            $table->dropIndex('fleet_wo_progress_due_idx');
            $table->dropColumn(['version', 'waiting_reason', 'next_action']);
        });
    }
};
