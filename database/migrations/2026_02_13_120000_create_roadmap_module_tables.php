<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_initiative_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('key', 64);
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'key'], 'rdmp_cat_tenant_key_uq');
            $table->index(['tenant_id', 'is_active'], 'rdmp_cat_tenant_active_idx');
        });

        Schema::create('roadmap_initiatives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 40);
            $table->string('title');
            $table->text('summary')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('roadmap_initiative_categories')->nullOnDelete();
            $table->string('stream', 32)->default('operations');
            $table->string('status', 32)->default('draft');
            $table->string('priority_band', 16)->nullable();
            $table->decimal('priority_score', 8, 2)->nullable();
            $table->json('score_breakdown')->nullable();
            $table->string('score_profile', 32)->nullable();
            $table->json('impact_profile')->nullable();
            $table->decimal('cost_estimate_low', 14, 2)->nullable();
            $table->decimal('cost_estimate_high', 14, 2)->nullable();
            $table->text('benefit_summary')->nullable();
            $table->text('risk_summary')->nullable();
            $table->text('dependency_summary')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sponsor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('next_decision', 64)->nullable();
            $table->date('decision_due_at')->nullable();
            $table->unsignedSmallInteger('target_fiscal_year')->nullable();
            $table->unsignedTinyInteger('target_quarter')->nullable();
            $table->date('start_date')->nullable();
            $table->date('target_date')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('budget_mode', 24)->default('standard');
            $table->boolean('manual_priority_override')->default(false);
            $table->text('manual_priority_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'category_id', 'target_fiscal_year', 'target_quarter'], 'roadmap_init_cat_period_idx');
            $table->index(['tenant_id', 'owner_user_id']);
            $table->index(['tenant_id', 'priority_score']);
        });

        Schema::create('roadmap_initiative_site_scopes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('initiative_id')->constrained('roadmap_initiatives')->cascadeOnDelete();
            $table->string('scope_type', 24)->default('global');
            $table->string('rollout_mode', 24)->default('single_wave');
            $table->unsignedTinyInteger('wave_count')->default(1);
            $table->json('constraints')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'initiative_id'], 'rdmp_scope_tenant_init_uq');
        });

        Schema::create('roadmap_initiative_site_scope_sites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('initiative_site_scope_id')
                ->constrained('roadmap_initiative_site_scopes', 'id', 'rdmp_scope_sites_scope_fk')
                ->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->unsignedTinyInteger('wave_no')->default(1);
            $table->string('status', 24)->default('not_started');
            $table->string('readiness_status', 24)->default('pending');
            $table->json('readiness_checklist')->nullable();
            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->dateTime('actual_start')->nullable();
            $table->dateTime('actual_end')->nullable();
            $table->text('blocked_reason')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'initiative_site_scope_id', 'site_id'], 'roadmap_scope_site_unique');
            $table->index(['tenant_id', 'site_id', 'status'], 'rdmp_scope_site_status_idx');
            $table->index(['tenant_id', 'wave_no', 'readiness_status'], 'rdmp_scope_wave_ready_idx');
        });

        Schema::create('roadmap_initiative_budgets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('initiative_id')->constrained('roadmap_initiatives')->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('quarter')->nullable();
            $table->char('currency', 3)->default('NZD');
            $table->decimal('capex_low', 14, 2)->nullable();
            $table->decimal('capex_high', 14, 2)->nullable();
            $table->decimal('opex_low', 14, 2)->nullable();
            $table->decimal('opex_high', 14, 2)->nullable();
            $table->decimal('recurring_low', 14, 2)->nullable();
            $table->decimal('recurring_high', 14, 2)->nullable();
            $table->decimal('planned_total', 14, 2)->nullable();
            $table->decimal('forecast_total', 14, 2)->nullable();
            $table->decimal('actual_total', 14, 2)->nullable();
            $table->decimal('variance_total', 14, 2)->nullable();
            $table->text('variance_reason')->nullable();
            $table->string('status', 24)->default('draft');
            $table->timestamps();

            $table->unique(['tenant_id', 'initiative_id', 'fiscal_year', 'quarter'], 'roadmap_budget_period_unique');
            $table->index(['tenant_id', 'fiscal_year', 'quarter'], 'rdmp_budget_period_idx');
        });

        Schema::create('roadmap_initiative_benefits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('initiative_id')->constrained('roadmap_initiatives')->cascadeOnDelete();
            $table->string('benefit_type', 32);
            $table->decimal('baseline_value', 14, 2)->nullable();
            $table->decimal('target_value', 14, 2)->nullable();
            $table->decimal('estimated_value_low', 14, 2)->nullable();
            $table->decimal('estimated_value_high', 14, 2)->nullable();
            $table->string('measurement_method', 128)->nullable();
            $table->unsignedSmallInteger('realisation_fiscal_year')->nullable();
            $table->unsignedTinyInteger('realisation_quarter')->nullable();
            $table->string('status', 24)->default('planned');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'initiative_id', 'benefit_type'], 'rdmp_benefit_init_type_idx');
        });

        Schema::create('roadmap_initiative_risk_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('initiative_id')->constrained('roadmap_initiatives')->cascadeOnDelete();
            $table->foreignId('risk_register_entry_id')->constrained('risk_register_entries')->cascadeOnDelete();
            $table->string('link_type', 24)->default('mitigates');
            $table->decimal('risk_delta_expected', 6, 2)->nullable();
            $table->boolean('within_appetite_expected')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'initiative_id', 'risk_register_entry_id'], 'roadmap_risk_link_unique');
        });

        Schema::create('roadmap_initiative_quality_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('initiative_id')->constrained('roadmap_initiatives')->cascadeOnDelete();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->string('external_reference', 128)->nullable();
            $table->string('status', 24)->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'source_type', 'source_id'], 'rdmp_quality_source_idx');
            $table->index(['tenant_id', 'initiative_id'], 'rdmp_quality_init_idx');
        });

        Schema::create('roadmap_initiative_dependencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('initiative_id')->constrained('roadmap_initiatives')->cascadeOnDelete();
            $table->foreignId('depends_on_initiative_id')->nullable()->constrained('roadmap_initiatives')->nullOnDelete();
            $table->string('external_ref', 128)->nullable();
            $table->string('dependency_type', 24)->default('internal');
            $table->string('risk_level', 16)->default('medium');
            $table->string('status', 24)->default('open');
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'initiative_id', 'status'], 'rdmp_dep_init_status_idx');
            $table->index(['tenant_id', 'due_date'], 'rdmp_dep_due_idx');
        });

        Schema::create('roadmap_initiative_milestones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('initiative_id')->constrained('roadmap_initiatives')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 24)->default('pending');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'initiative_id', 'status'], 'rdmp_ms_init_status_idx');
            $table->index(['tenant_id', 'due_date'], 'rdmp_ms_due_idx');
        });

        Schema::create('roadmap_initiative_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('initiative_id')->constrained('roadmap_initiatives')->cascadeOnDelete();
            $table->foreignId('initiative_milestone_id')->nullable()->constrained('roadmap_initiative_milestones')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('task_type', 32)->default('generic');
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('pending');
            $table->string('priority', 16)->default('medium');
            $table->date('due_date')->nullable();
            $table->decimal('effort_hours_est', 8, 2)->nullable();
            $table->decimal('effort_hours_actual', 8, 2)->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'initiative_id', 'status'], 'rdmp_task_init_status_idx');
            $table->index(['tenant_id', 'site_id', 'status'], 'rdmp_task_site_status_idx');
        });

        Schema::create('roadmap_assurance_evidence_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('initiative_id')->constrained('roadmap_initiatives')->cascadeOnDelete();
            $table->string('control_name');
            $table->string('evidence_type', 64);
            $table->string('evidence_source_type', 64)->nullable();
            $table->unsignedBigInteger('evidence_source_id')->nullable();
            $table->foreignId('verifier_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('verify_due_date')->nullable();
            $table->string('verification_frequency', 32)->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->string('verification_result', 24)->nullable();
            $table->string('document_reference', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'initiative_id', 'verify_due_date'], 'rdmp_assure_due_idx');
            $table->index(['tenant_id', 'verification_result'], 'rdmp_assure_result_idx');
        });

        Schema::create('roadmap_quarterly_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('quarter');
            $table->string('status', 24)->default('draft');
            $table->string('preset_profile', 32)->default('board_ceo');
            $table->dateTime('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->string('snapshot_hash', 128)->nullable();
            $table->json('snapshot_payload')->nullable();
            $table->unsignedInteger('revision_no')->default(1);
            $table->text('change_summary')->nullable();
            $table->json('source_filters')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'fiscal_year', 'quarter', 'revision_no'], 'roadmap_plan_revision_unique');
            $table->index(['tenant_id', 'fiscal_year', 'quarter', 'status'], 'roadmap_plan_period_status_idx');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('roadmap_quarterly_plan_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('quarterly_plan_id')->constrained('roadmap_quarterly_plans')->cascadeOnDelete();
            $table->foreignId('initiative_id')->constrained('roadmap_initiatives')->cascadeOnDelete();
            $table->unsignedInteger('rank')->nullable();
            $table->decimal('planned_capex', 14, 2)->nullable();
            $table->decimal('planned_opex', 14, 2)->nullable();
            $table->string('planned_outcome', 255)->nullable();
            $table->boolean('decision_required')->default(false);
            $table->string('decision_type', 64)->nullable();
            $table->date('decision_due_date')->nullable();
            $table->string('status_at_snapshot', 24)->nullable();
            $table->decimal('score_at_snapshot', 8, 2)->nullable();
            $table->decimal('risk_delta_at_snapshot', 6, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'quarterly_plan_id', 'initiative_id'], 'roadmap_plan_item_unique');
            $table->index(['tenant_id', 'quarterly_plan_id', 'rank'], 'rdmp_plan_item_rank_idx');
        });

        Schema::create('roadmap_change_log_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id');
            $table->string('change_type', 64);
            $table->json('field_deltas')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'entity_type', 'entity_id', 'created_at'], 'roadmap_change_entity_idx');
            $table->index(['tenant_id', 'change_type']);
        });

        Schema::create('roadmap_delegation_authority_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('scope', 64);
            $table->decimal('amount_min', 14, 2)->nullable();
            $table->decimal('amount_max', 14, 2)->nullable();
            $table->decimal('risk_min', 6, 2)->nullable();
            $table->decimal('risk_max', 6, 2)->nullable();
            $table->string('required_approver_role', 64);
            $table->string('escalation_role', 64)->nullable();
            $table->date('active_from')->nullable();
            $table->date('active_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'scope', 'is_active'], 'rdmp_doa_scope_active_idx');
            $table->index(['tenant_id', 'is_active', 'active_from', 'active_to'], 'roadmap_doa_active_window_idx');
        });

        Schema::create('roadmap_decision_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('request_type', 64);
            $table->string('status', 24)->default('pending');
            $table->foreignId('delegation_rule_id')->nullable()->constrained('roadmap_delegation_authority_rules')->nullOnDelete();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('risk_level', 16)->nullable();
            $table->decimal('risk_delta', 6, 2)->nullable();
            $table->string('required_role', 64)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->text('rationale')->nullable();
            $table->text('recommendation')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->foreignId('governance_resolution_id')->nullable()->constrained('resolutions')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status', 'due_date'], 'rdmp_decision_due_idx');
            $table->index(['tenant_id', 'source_type', 'source_id'], 'rdmp_decision_source_idx');
            $table->index(['tenant_id', 'request_type', 'status'], 'rdmp_decision_type_status_idx');
        });

        Schema::create('roadmap_vendor_contract_refs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('initiative_id')->constrained('roadmap_initiatives')->cascadeOnDelete();
            $table->foreignId('site_vendor_id')->nullable()->constrained('site_vendors')->nullOnDelete();
            $table->string('vendor_name');
            $table->string('contract_ref', 128)->nullable();
            $table->date('renewal_date')->nullable();
            $table->unsignedSmallInteger('notice_days')->default(30);
            $table->decimal('annual_cost', 14, 2)->nullable();
            $table->string('status', 24)->default('active');
            $table->string('source_module', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'renewal_date', 'status'], 'rdmp_vendor_renewal_idx');
            $table->index(['tenant_id', 'initiative_id'], 'rdmp_vendor_init_idx');
        });

        Schema::create('roadmap_suggestions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('source', 64);
            $table->string('source_key', 128)->nullable();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('dedupe_key', 191);
            $table->decimal('score_hint', 8, 2)->nullable();
            $table->string('status', 24)->default('triage_pending');
            $table->foreignId('triage_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('snoozed_until')->nullable();
            $table->foreignId('converted_initiative_id')->nullable()->constrained('roadmap_initiatives')->nullOnDelete();
            $table->dateTime('first_seen_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->unsignedInteger('hit_count')->default(1);
            $table->dateTime('rate_limited_until')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'source', 'status']);
            $table->index(['tenant_id', 'dedupe_key']);
            $table->index(['tenant_id', 'status', 'snoozed_until'], 'rdmp_sugg_snooze_idx');
            $table->index(['tenant_id', 'triage_owner_id', 'status'], 'rdmp_sugg_owner_status_idx');
        });

        Schema::create('roadmap_report_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('quarterly_plan_id')->nullable()->constrained('roadmap_quarterly_plans')->nullOnDelete();
            $table->string('report_type', 64);
            $table->string('name');
            $table->string('checksum', 128);
            $table->json('payload')->nullable();
            $table->string('file_path')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('generated_at');
            $table->boolean('immutable')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'report_type', 'generated_at'], 'rdmp_report_type_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_report_snapshots');
        Schema::dropIfExists('roadmap_suggestions');
        Schema::dropIfExists('roadmap_vendor_contract_refs');
        Schema::dropIfExists('roadmap_decision_requests');
        Schema::dropIfExists('roadmap_delegation_authority_rules');
        Schema::dropIfExists('roadmap_change_log_entries');
        Schema::dropIfExists('roadmap_quarterly_plan_items');
        Schema::dropIfExists('roadmap_quarterly_plans');
        Schema::dropIfExists('roadmap_assurance_evidence_plans');
        Schema::dropIfExists('roadmap_initiative_tasks');
        Schema::dropIfExists('roadmap_initiative_milestones');
        Schema::dropIfExists('roadmap_initiative_dependencies');
        Schema::dropIfExists('roadmap_initiative_quality_links');
        Schema::dropIfExists('roadmap_initiative_risk_links');
        Schema::dropIfExists('roadmap_initiative_benefits');
        Schema::dropIfExists('roadmap_initiative_budgets');
        Schema::dropIfExists('roadmap_initiative_site_scope_sites');
        Schema::dropIfExists('roadmap_initiative_site_scopes');
        Schema::dropIfExists('roadmap_initiatives');
        Schema::dropIfExists('roadmap_initiative_categories');
    }
};
