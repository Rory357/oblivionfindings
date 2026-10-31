<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chemical_exposure_recovery_cases', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('exposure_id')->unique('cer_case_exposure_uq');
            $t->unsignedBigInteger('worker_id'); $t->unsignedBigInteger('site_id'); $t->unsignedBigInteger('substance_id');
            $t->json('sealed_identity'); $t->char('sealed_digest', 64); $t->unsignedInteger('revision')->default(0);
            $t->unsignedBigInteger('current_version_id')->nullable(); $t->dateTime('created_at', 6);
            $t->foreign('exposure_id', 'cer_case_root_fk')->references('id')->on('substance_exposure_records')->restrictOnDelete();
            $t->foreign('worker_id', 'cer_case_worker_fk')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('site_id', 'cer_case_site_fk')->references('id')->on('sites')->restrictOnDelete();
            $t->foreign('substance_id', 'cer_case_substance_fk')->references('id')->on('hazardous_substances')->restrictOnDelete();
        });
        Schema::create('chemical_exposure_recovery_versions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('case_id'); $t->unsignedBigInteger('exposure_id');
            $t->unsignedBigInteger('intent_id')->unique('cer_version_intent_uq'); $t->uuid('idempotency_key')->unique('cer_version_uuid_uq');
            $t->unsignedBigInteger('actor_id'); $t->unsignedInteger('revision'); $t->unsignedInteger('expected_version');
            $t->unsignedBigInteger('previous_version_id')->nullable(); $t->string('command', 48);
            $t->string('decision', 32); $t->json('review_context')->nullable(); $t->char('binding_digest', 64);
            // Exact UTF-8 serialized bytes permit digest verification without returning sealed prose.
            $t->longText('content'); $t->char('content_digest', 64); $t->dateTime('reviewed_at', 6); $t->dateTime('recorded_at', 6);
            $t->unique(['case_id', 'revision'], 'cer_version_sequence_uq');
            $t->foreign('case_id', 'cer_version_case_fk')->references('id')->on('chemical_exposure_recovery_cases')->restrictOnDelete();
            $t->foreign('exposure_id', 'cer_version_root_fk')->references('id')->on('substance_exposure_records')->restrictOnDelete();
            $t->foreign('intent_id', 'cer_version_intent_fk')->references('id')->on('chemical_command_intents')->restrictOnDelete();
            $t->foreign('actor_id', 'cer_version_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('previous_version_id', 'cer_version_previous_fk')->references('id')->on('chemical_exposure_recovery_versions')->restrictOnDelete();
        });
        Schema::create('chemical_exposure_recovery_receipts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('case_id'); $t->unsignedBigInteger('version_id')->unique('cer_receipt_version_uq');
            $t->unsignedBigInteger('intent_id')->unique('cer_receipt_intent_uq'); $t->uuid('idempotency_key')->unique('cer_receipt_uuid_uq');
            $t->unsignedBigInteger('actor_id'); $t->unsignedBigInteger('audit_log_id')->unique('cer_receipt_audit_uq');
            $t->json('accepted_result'); $t->json('audit_metadata'); $t->char('content_digest', 64); $t->char('binding_digest', 64);
            $t->dateTime('created_at', 6);
            $t->foreign('case_id', 'cer_receipt_case_fk')->references('id')->on('chemical_exposure_recovery_cases')->restrictOnDelete();
            $t->foreign('version_id', 'cer_receipt_version_fk')->references('id')->on('chemical_exposure_recovery_versions')->restrictOnDelete();
            $t->foreign('intent_id', 'cer_receipt_intent_fk')->references('id')->on('chemical_command_intents')->restrictOnDelete();
            $t->foreign('actor_id', 'cer_receipt_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('audit_log_id', 'cer_receipt_audit_fk')->references('id')->on('audit_logs')->restrictOnDelete();
        });
        Schema::create('chemical_exposure_recovery_staging', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('case_id'); $t->unsignedBigInteger('exposure_id');
            $t->unsignedBigInteger('intent_id')->unique('cer_stage_intent_uq'); $t->uuid('idempotency_key')->unique('cer_stage_uuid_uq'); $t->unsignedBigInteger('actor_id');
            $t->unsignedInteger('expected_version'); $t->string('path', 512)->unique('cer_stage_path_uq');
            $t->longText('claim_frame'); $t->char('claim_fingerprint', 64); $t->char('reservation_digest', 64);
            $t->string('state', 24)->default('reserved'); $t->unsignedBigInteger('receipt_id')->nullable();
            $t->unsignedInteger('attempts')->default(0); $t->timestamps(6);
            $t->foreign('case_id', 'cer_stage_case_fk')->references('id')->on('chemical_exposure_recovery_cases')->restrictOnDelete();
            $t->foreign('exposure_id', 'cer_stage_root_fk')->references('id')->on('substance_exposure_records')->restrictOnDelete();
            $t->foreign('intent_id', 'cer_stage_intent_fk')->references('id')->on('chemical_command_intents')->restrictOnDelete();
            $t->foreign('actor_id', 'cer_stage_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('receipt_id', 'cer_stage_receipt_fk')->references('id')->on('chemical_exposure_recovery_receipts')->restrictOnDelete();
        });
        Schema::create('chemical_exposure_recovery_evidence', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('case_id'); $t->unsignedBigInteger('version_id')->unique('cer_file_version_uq');
            $t->unsignedBigInteger('staging_id')->unique('cer_file_stage_uq'); $t->string('path', 512)->unique('cer_file_path_uq');
            $t->char('sha256', 64); $t->unsignedBigInteger('size'); $t->string('mime', 128); $t->string('original_name');
            $t->foreign('case_id', 'cer_file_case_fk')->references('id')->on('chemical_exposure_recovery_cases')->restrictOnDelete();
            $t->foreign('version_id', 'cer_file_version_fk')->references('id')->on('chemical_exposure_recovery_versions')->restrictOnDelete();
            $t->foreign('staging_id', 'cer_file_stage_fk')->references('id')->on('chemical_exposure_recovery_staging')->restrictOnDelete();
        });
        Schema::table('chemical_exposure_recovery_cases', function (Blueprint $t) {
            $t->foreign('current_version_id', 'cer_case_version_fk')->references('id')->on('chemical_exposure_recovery_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chemical_exposure_recovery_cases', fn (Blueprint $t) => $t->dropForeign('cer_case_version_fk'));
        foreach (['evidence', 'staging', 'receipts', 'versions', 'cases'] as $suffix) {
            Schema::dropIfExists('chemical_exposure_recovery_'.$suffix);
        }
    }
};
