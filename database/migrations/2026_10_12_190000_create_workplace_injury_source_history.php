<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workplace_injuries', function (Blueprint $table): void {
            $table->unsignedInteger('source_schema_version')->default(0);
            $table->unsignedInteger('source_revision')->default(0);
            $table->unsignedBigInteger('coordinator_user_id')->nullable();
            $table->json('coordinator_snapshot')->nullable();
            $table->date('next_review_date')->nullable();
            $table->uuid('source_creation_key')->nullable()->unique('inj_source_create_uq');
        });
        Schema::table('work_capacity_assessments', function (Blueprint $table): void {
            $table->unsignedInteger('source_schema_version')->default(0);
            $table->unsignedInteger('source_revision')->default(0);
            $table->unsignedBigInteger('worker_id')->nullable();
            $table->unsignedBigInteger('assessor_user_id')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('attribution_state', 32)->default('legacy_unknown');
            $table->json('worker_snapshot')->nullable();
            $table->json('assessor_snapshot')->nullable();
            $table->json('recorder_snapshot')->nullable();
        });
        Schema::table('workplace_injury_attachments', function (Blueprint $table): void {
            $table->unsignedInteger('source_schema_version')->default(0);
            $table->unsignedInteger('source_revision')->default(0);
        });
        foreach (['return_to_work_plans', 'modified_duties'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedInteger('source_schema_version')->default(0);
                $table->unsignedInteger('source_revision')->default(0);
                $table->json('worker_snapshot')->nullable();
                $table->json('recorder_snapshot')->nullable();
            });
        }
        Schema::create('workplace_injury_source_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workplace_injury_id');
            $table->unsignedInteger('aggregate_revision');
            $table->unsignedInteger('source_schema_version');
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->string('command', 64);
            $table->unsignedBigInteger('worker_id');
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('prior_version_id')->nullable();
            $table->unsignedBigInteger('recorded_by');
            $table->json('recorder_snapshot');
            $table->json('content');
            $table->char('content_digest', 64);
            $table->json('factual_content')->nullable();
            $table->char('factual_digest', 64)->nullable();
            $table->uuid('idempotency_key')->nullable();
            $table->timestamp('recorded_at', 6);
            $table->unique(['workplace_injury_id', 'aggregate_revision'], 'inj_source_revision_uq');
            $table->index(['source_type', 'source_id'], 'inj_source_subject_ix');
            $table->foreign('workplace_injury_id', 'inj_source_parent_fk')->references('id')->on('workplace_injuries')->restrictOnDelete();
        });
        Schema::create('workplace_injury_command_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workplace_injury_id');
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('user_id');
            $table->json('actor_snapshot');
            $table->string('command', 64);
            $table->unsignedInteger('expected_version')->nullable();
            $table->boolean('requires_medical_access')->default(false);
            $table->uuid('idempotency_key')->unique('inj_receipt_key_uq');
            $table->char('fingerprint', 64);
            $table->json('accepted_result');
            $table->unsignedBigInteger('audit_log_id');
            $table->timestamp('created_at', 6);
            $table->index(['workplace_injury_id', 'user_id'], 'inj_receipt_actor_ix');
            $table->foreign('workplace_injury_id', 'inj_receipt_parent_fk')->references('id')->on('workplace_injuries')->restrictOnDelete();
            $table->foreign('audit_log_id', 'inj_receipt_audit_fk')->references('id')->on('audit_logs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['workplace_injury_source_versions', 'workplace_injury_command_receipts'] as $name) {
            if (Schema::hasTable($name) && DB::table($name)->exists()) {
                throw new LogicException('Rollback would discard independent injury source evidence.');
            }
        }
        foreach (['workplace_injuries', 'work_capacity_assessments', 'return_to_work_plans', 'modified_duties', 'workplace_injury_attachments'] as $name) {
            if (DB::table($name)->where('source_schema_version', '<>', 0)->orWhere('source_revision', '<>', 0)->exists()) {
                throw new LogicException('Rollback would discard attributed injury source facts.');
            }
        }
        if (DB::table('workplace_injuries')->whereNotNull('coordinator_user_id')->orWhereNotNull('coordinator_snapshot')->orWhereNotNull('next_review_date')->orWhereNotNull('source_creation_key')->exists()
            || DB::table('work_capacity_assessments')->whereNotNull('worker_id')->orWhereNotNull('assessor_user_id')->orWhereNotNull('recorded_by')
                ->orWhere('attribution_state', '<>', 'legacy_unknown')->orWhereNotNull('worker_snapshot')->orWhereNotNull('assessor_snapshot')->orWhereNotNull('recorder_snapshot')->exists()) {
            throw new LogicException('Rollback would discard attribution or recovery coordination.');
        }
        foreach (['return_to_work_plans', 'modified_duties'] as $name) {
            if (DB::table($name)->whereNotNull('worker_snapshot')->orWhereNotNull('recorder_snapshot')->exists()) {
                throw new LogicException('Rollback would discard recorded recovery identities.');
            }
        }
        Schema::dropIfExists('workplace_injury_command_receipts');
        Schema::dropIfExists('workplace_injury_source_versions');
        Schema::table('workplace_injuries', function (Blueprint $table): void {
            $table->dropUnique('inj_source_create_uq');
            $table->dropColumn(['source_schema_version', 'source_revision', 'coordinator_user_id', 'coordinator_snapshot', 'next_review_date', 'source_creation_key']);
        });
        Schema::table('workplace_injury_attachments', fn (Blueprint $table) => $table->dropColumn(['source_schema_version', 'source_revision']));
        Schema::table('work_capacity_assessments', fn (Blueprint $table) => $table->dropColumn(['source_schema_version', 'source_revision', 'worker_id', 'assessor_user_id', 'recorded_by', 'attribution_state', 'worker_snapshot', 'assessor_snapshot', 'recorder_snapshot']));
        foreach (['return_to_work_plans', 'modified_duties'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['source_schema_version', 'source_revision', 'worker_snapshot', 'recorder_snapshot']));
        }
    }
};

