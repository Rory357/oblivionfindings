<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('substance_exposure_records', function (Blueprint $t) {
            $t->unsignedTinyInteger('source_schema_version')->default(0);
            $t->unsignedInteger('source_revision')->default(0);
            $t->json('original_context')->nullable();
        });
        Schema::create('chemical_exposure_source_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('exposure_id');
            $t->string('source_type', 24);
            $t->unsignedBigInteger('source_id');
            $t->unsignedBigInteger('substance_id');
            $t->unsignedBigInteger('worker_id');
            $t->unsignedBigInteger('site_id');
            $t->unsignedBigInteger('recorder_id')->nullable();
            $t->unsignedBigInteger('actor_id');
            $t->unsignedBigInteger('intent_id');
            $t->unsignedInteger('aggregate_revision');
            $t->unsignedInteger('expected_version')->nullable();
            $t->unsignedTinyInteger('source_schema_version')->default(1);
            $t->string('command', 64);
            $t->uuid('idempotency_key');
            $t->json('actor_snapshot');
            $t->json('content');
            $t->char('content_digest', 64);
            $t->dateTime('recorded_at', 6);
            $t->unique(['exposure_id', 'aggregate_revision'], 'ce_version_revision_uq');
            $t->index(['exposure_id', 'source_type', 'source_id'], 'ce_version_source_idx');
            $t->foreign('exposure_id', 'ce_version_exposure_fk')->references('id')->on('substance_exposure_records')->restrictOnDelete();
            $t->foreign('intent_id', 'ce_version_intent_fk')->references('id')->on('chemical_command_intents')->restrictOnDelete();
        });
        Schema::create('chemical_exposure_command_receipts', function (Blueprint $t) {
            $t->id();
            $t->uuid('idempotency_key')->unique('ce_receipt_uuid_uq');
            $t->unsignedBigInteger('intent_id')->unique('ce_receipt_intent_uq');
            $t->unsignedBigInteger('actor_id');
            $t->unsignedBigInteger('exposure_id');
            $t->string('source_type', 24);
            $t->unsignedBigInteger('source_id');
            $t->string('command', 64);
            $t->unsignedInteger('expected_version')->nullable();
            $t->char('fingerprint', 64);
            $t->unsignedBigInteger('source_version_id')->nullable();
            $t->char('source_content_digest', 64)->nullable();
            $t->json('source_tuple');
            $t->char('source_tuple_digest', 64);
            $t->unsignedBigInteger('audit_log_id');
            $t->json('accepted_result');
            $t->dateTime('created_at', 6);
            $t->foreign('intent_id', 'ce_receipt_intent_fk')->references('id')->on('chemical_command_intents')->restrictOnDelete();
            $t->foreign('exposure_id', 'ce_receipt_exposure_fk')->references('id')->on('substance_exposure_records')->restrictOnDelete();
            $t->foreign('source_version_id', 'ce_receipt_version_fk')->references('id')->on('chemical_exposure_source_versions')->restrictOnDelete();
            $t->foreign('audit_log_id', 'ce_receipt_audit_fk')->references('id')->on('audit_logs')->restrictOnDelete();
        });
        Schema::create('chemical_exposure_reviews', function (Blueprint $t) {
            $this->child($t, 'ce_review');
            $t->dateTime('reviewed_at', 6);
            $t->string('outcome', 32);
            $t->text('notes');
            $t->unsignedBigInteger('recorded_by');
            $t->json('recorder_snapshot');
        });
        Schema::create('chemical_exposure_followups', function (Blueprint $t) {
            $this->child($t, 'ce_followup');
            $t->unsignedBigInteger('assigned_to');
            $t->json('assignee_snapshot');
            $t->text('instruction');
            $t->dateTime('due_at', 6);
            $t->unsignedBigInteger('recorded_by');
            $t->dateTime('completed_at', 6)->nullable();
            $t->unsignedBigInteger('completed_by')->nullable();
            $t->text('completion_notes')->nullable();
        });
        Schema::create('chemical_exposure_evidence', function (Blueprint $t) {
            $this->child($t, 'ce_evidence');
            $t->string('disk', 24);
            $t->string('path', 512);
            $t->string('original_name');
            $t->string('mime', 128);
            $t->unsignedBigInteger('size');
            $t->char('sha256', 64);
            $t->text('description')->nullable();
            $t->unsignedBigInteger('uploaded_by');
            $t->json('uploader_snapshot');
            $t->softDeletes('deleted_at', 6);
            $t->unsignedBigInteger('removed_by')->nullable();
            $t->text('removal_reason')->nullable();
            $t->unique('path', 'ce_evidence_path_uq');
        });
        Schema::create('chemical_exposure_evidence_staging', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('intent_id')->unique('ce_stage_intent_uq');
            $t->unsignedBigInteger('actor_id');
            $t->unsignedBigInteger('exposure_id');
            $t->unsignedBigInteger('substance_id');
            $t->unsignedBigInteger('worker_id');
            $t->unsignedBigInteger('site_id');
            $t->unsignedInteger('expected_version');
            $t->string('disk', 24);
            $t->string('path', 512)->unique('ce_stage_path_uq');
            $t->json('file_evidence');
            $t->json('command_values');
            $t->char('reservation_digest', 64);
            $t->string('state', 24)->default('staged');
            $t->unsignedBigInteger('receipt_id')->nullable();
            $t->unsignedBigInteger('evidence_id')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamps(6);
            $t->foreign('intent_id', 'ce_stage_intent_fk')->references('id')->on('chemical_command_intents')->restrictOnDelete();
            $t->foreign('exposure_id', 'ce_stage_exposure_fk')->references('id')->on('substance_exposure_records')->restrictOnDelete();
        });
        Schema::create('chemical_exposure_evidence_cleanups', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('actor_id');
            $t->unsignedBigInteger('exposure_id');
            $t->unsignedBigInteger('site_id');
            $t->unsignedBigInteger('evidence_id');
            $t->unsignedBigInteger('receipt_id');
            $t->string('disk', 24);
            $t->string('path', 512);
            $t->char('sha256', 64);
            $t->unsignedBigInteger('size');
            $t->string('state', 24)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamps(6);
            $t->unique('evidence_id', 'ce_cleanup_evidence_uq');
            $t->foreign('receipt_id', 'ce_cleanup_receipt_fk')->references('id')->on('chemical_exposure_command_receipts')->restrictOnDelete();
        });
        Schema::create('chemical_exposure_source_deliveries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('exposure_id');
            $t->unsignedBigInteger('source_version_id');
            $t->unsignedBigInteger('actor_id');
            $t->string('kind', 32);
            $t->unsignedBigInteger('recipient_id')->nullable();
            $t->json('membership');
            $t->string('state', 24)->default('pending');
            $t->unsignedBigInteger('hs_event_id')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamps(6);
            $t->unique(['source_version_id', 'kind'], 'ce_delivery_version_kind_uq');
            $t->foreign('source_version_id', 'ce_delivery_version_fk')->references('id')->on('chemical_exposure_source_versions')->restrictOnDelete();
        });
    }

    private function child(Blueprint $t, string $prefix): void
    {
        $t->id();
        $t->unsignedBigInteger('exposure_id');
        $t->unsignedTinyInteger('source_schema_version')->default(1);
        $t->unsignedInteger('source_revision');
        $t->timestamps(6);
        $t->foreign('exposure_id', $prefix.'_exposure_fk')->references('id')->on('substance_exposure_records')->restrictOnDelete();
    }

    public function down(): void
    {
        if (Schema::hasTable('chemical_exposure_hs_projections')) {
            throw new RuntimeException('Reverse the retained downstream exposure projection schema first.');
        }
        // Do not discard recorded source facts, rejected physical attempts, private bytes or legacy-context correction evidence.
        foreach (['chemical_exposure_source_deliveries', 'chemical_exposure_evidence_cleanups',
            'chemical_exposure_evidence_staging', 'chemical_exposure_evidence', 'chemical_exposure_followups',
            'chemical_exposure_reviews', 'chemical_exposure_command_receipts', 'chemical_exposure_source_versions'] as $name) {
            if (DB::table($name)->exists()) { throw new RuntimeException('Retained private exposure evidence prevents destructive rollback.'); }
        }
        if (DB::table('substance_exposure_records')->where(fn ($q) => $q->where('source_schema_version', '!=', 0)
            ->orWhere('source_revision', '!=', 0)->orWhereNotNull('original_context'))->exists()) {
            throw new RuntimeException('Governed exposure metadata prevents destructive rollback.');
        }
        foreach (['chemical_exposure_source_deliveries', 'chemical_exposure_evidence_cleanups',
            'chemical_exposure_evidence_staging', 'chemical_exposure_evidence', 'chemical_exposure_followups',
            'chemical_exposure_reviews', 'chemical_exposure_command_receipts', 'chemical_exposure_source_versions'] as $name) {
            Schema::drop($name);
        }
        Schema::table('substance_exposure_records', fn (Blueprint $t) => $t->dropColumn(['source_schema_version', 'source_revision', 'original_context']));
    }
};