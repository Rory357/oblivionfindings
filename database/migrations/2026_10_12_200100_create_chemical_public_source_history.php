<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['hazardous_substances', 'safety_data_sheets', 'substance_storage_locations'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedTinyInteger('source_schema_version')->default(0);
                $table->unsignedInteger('source_revision')->default(0);
            });
        }
        Schema::table('safety_data_sheets', function (Blueprint $table) {
            $table->string('document_disk', 32)->default('private');
            $table->char('document_sha256', 64)->nullable();
            $table->unsignedBigInteger('document_size')->nullable();
            $table->string('document_mime', 128)->nullable();
            $table->string('original_name')->nullable();
        });
        Schema::table('hazardous_substances', function (Blueprint $table) {
            $table->unsignedBigInteger('current_sds_id')->nullable();
            $table->foreign('current_sds_id', 'chem_current_sds_fk')->references('id')->on('safety_data_sheets')->restrictOnDelete();
        });
        Schema::create('chemical_source_versions', function (Blueprint $table) {
            $table->id();
            $table->string('root_type', 32);
            $table->unsignedBigInteger('root_id');
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedInteger('aggregate_revision');
            $table->unsignedInteger('expected_version')->nullable();
            $table->unsignedInteger('parent_expected_version')->nullable();
            $table->unsignedTinyInteger('source_schema_version')->default(1);
            $table->string('command', 64);
            $table->uuid('idempotency_key');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('actor_snapshot');
            $table->json('content');
            $table->char('content_digest', 64);
            $table->dateTime('recorded_at', 6);
            $table->foreign('actor_id', 'chem_version_actor_fk')->references('id')->on('users')->nullOnDelete();
            $table->index(['root_type', 'root_id', 'aggregate_revision'], 'chem_version_root_idx');
            $table->index(['source_type', 'source_id'], 'chem_version_source_idx');
            $table->unique(['root_type', 'root_id', 'aggregate_revision', 'source_type', 'source_id'], 'chem_version_tuple_unique');
        });
        Schema::create('chemical_command_intents', function (Blueprint $table) {
            $table->id();
            $table->uuid('idempotency_key')->unique('chem_intent_uuid_unique');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('actor_snapshot');
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('command', 64);
            $table->unsignedInteger('expected_version')->nullable();
            $table->unsignedInteger('parent_expected_version')->nullable();
            $table->char('fingerprint', 64);
            $table->dateTime('created_at', 6);
            $table->foreign('actor_id', 'chem_intent_actor_fk')->references('id')->on('users')->nullOnDelete();
        });
        Schema::create('chemical_command_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('idempotency_key')->unique('chem_receipt_uuid_unique');
            $table->unsignedBigInteger('intent_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('root_type', 32);
            $table->unsignedBigInteger('root_id');
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('command', 64);
            $table->unsignedInteger('expected_version')->nullable();
            $table->unsignedInteger('parent_expected_version')->nullable();
            $table->char('fingerprint', 64);
            $table->unsignedBigInteger('source_version_id')->nullable();
            $table->char('source_version_digest', 64)->nullable();
            $table->unsignedBigInteger('audit_log_id');
            $table->json('accepted_result');
            $table->dateTime('created_at', 6);
            $table->foreign('intent_id', 'chem_receipt_intent_fk')->references('id')->on('chemical_command_intents')->restrictOnDelete();
            $table->foreign('actor_id', 'chem_receipt_actor_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('source_version_id', 'chem_receipt_version_fk')->references('id')->on('chemical_source_versions')->restrictOnDelete();
            $table->foreign('audit_log_id', 'chem_receipt_audit_fk')->references('id')->on('audit_logs')->restrictOnDelete();
        });
        Schema::create('chemical_evidence_staging', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('intent_id')->unique('chem_stage_intent_unique');
            $table->unsignedBigInteger('parent_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->uuid('idempotency_key')->unique('chem_stage_uuid_unique');
            $table->string('disk', 32);
            $table->string('path');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size');
            $table->string('mime', 128);
            $table->string('original_name');
            $table->json('command_values');
            $table->string('state', 32)->default('reserved');
            $table->unsignedBigInteger('receipt_id')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('source_version_id')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->foreign('intent_id', 'chem_stage_intent_fk')->references('id')->on('chemical_command_intents')->restrictOnDelete();
            $table->foreign('actor_id', 'chem_stage_actor_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('receipt_id', 'chem_stage_receipt_fk')->references('id')->on('chemical_command_receipts')->restrictOnDelete();
            $table->foreign('source_version_id', 'chem_stage_version_fk')->references('id')->on('chemical_source_versions')->restrictOnDelete();
        });
        Schema::create('chemical_evidence_cleanup', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staging_id')->unique('chem_cleanup_stage_unique');
            $table->string('state', 32)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->foreign('staging_id', 'chem_cleanup_stage_fk')->references('id')->on('chemical_evidence_staging')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Any governed evidence, including reserved/failed physical intent, prevents destructive rollback.
        foreach (['chemical_source_versions', 'chemical_command_intents', 'chemical_command_receipts', 'chemical_evidence_staging', 'chemical_evidence_cleanup'] as $name) {
            if (Schema::hasTable($name) && DB::table($name)->exists()) {
                throw new LogicException('Retained chemical provenance prevents rollback.');
            }
        }
        foreach (['hazardous_substances', 'safety_data_sheets', 'substance_storage_locations'] as $name) {
            if (DB::table($name)->where('source_schema_version', '!=', 0)->orWhere('source_revision', '!=', 0)->exists()) {
                throw new LogicException('Governed chemical source markers prevent rollback.');
            }
        }
        if (DB::table('hazardous_substances')->whereNotNull('current_sds_id')->exists()
            || DB::table('safety_data_sheets')->where('document_disk', '!=', 'private')->orWhereNotNull('document_sha256')->orWhereNotNull('document_size')->orWhereNotNull('document_mime')->orWhereNotNull('original_name')->exists()) {
            throw new LogicException('Retained chemical document provenance prevents rollback.');
        }
        foreach (['chemical_exposure_source_versions', 'chemical_exposure_command_receipts', 'chemical_exposure_evidence_staging'] as $dependent) {
            if (Schema::hasTable($dependent)) {
                throw new LogicException('Reverse dependent private chemical migrations before the shared UUID ledger.');
            }
        }
        foreach (['chemical_evidence_cleanup', 'chemical_evidence_staging', 'chemical_command_receipts', 'chemical_command_intents', 'chemical_source_versions'] as $name) {
            Schema::drop($name);
        }
        Schema::table('hazardous_substances', function (Blueprint $table) {
            $table->dropForeign('chem_current_sds_fk');
            $table->dropColumn('current_sds_id');
        });
        Schema::table('safety_data_sheets', fn (Blueprint $table) => $table->dropColumn(['document_disk', 'document_sha256', 'document_size', 'document_mime', 'original_name']));
        foreach (['hazardous_substances', 'safety_data_sheets', 'substance_storage_locations'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['source_schema_version', 'source_revision']));
        }
    }
};
