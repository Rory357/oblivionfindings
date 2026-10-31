<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SOURCES = ['ppe_types', 'ppe_inventory', 'ppe_allocations', 'ppe_inspections', 'ppe_attachments', 'ppe_allocation_attachments', 'ppe_inspection_attachments'];

    public function up(): void
    {
        foreach (self::SOURCES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedTinyInteger('source_schema_version')->default(0);
                $table->unsignedInteger('source_revision')->default(0);
            });
        }
        Schema::create('ppe_source_versions', function (Blueprint $table) {
            $table->id();
            $table->string('root_type', 20);
            $table->unsignedBigInteger('root_id');
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('person_id')->nullable();
            $table->unsignedInteger('aggregate_revision');
            $table->unsignedTinyInteger('source_schema_version')->default(1);
            $table->string('command', 40);
            $table->uuid('idempotency_key');
            $table->unsignedBigInteger('user_id');
            $table->json('actor_snapshot');
            $table->json('content');
            $table->char('content_digest', 64);
            $table->json('factual_content')->nullable();
            $table->char('factual_digest', 64)->nullable();
            $table->json('own_content')->nullable();
            $table->char('own_digest', 64)->nullable();
            $table->timestamp('recorded_at', 6);
            $table->unique(['root_type', 'root_id', 'aggregate_revision'], 'ppe_version_root_revision_uq');
            $table->index(['source_type', 'source_id'], 'ppe_version_source_idx');
        });
        Schema::create('ppe_command_intent_claims', function (Blueprint $table) {
            $table->id();
            $table->uuid('idempotency_key')->unique('ppe_intent_uuid_uq');
            $table->unsignedBigInteger('user_id');
            $table->string('command', 40);
            $table->string('root_type', 20);
            $table->unsignedBigInteger('root_id')->nullable();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('attachment_id')->nullable();
            $table->unsignedInteger('expected_version')->nullable();
            $table->char('fingerprint', 64);
            $table->char('identity_digest', 64);
            $table->timestamp('created_at', 6);
        });
        Schema::create('ppe_command_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('intent_claim_id');
            $table->foreign('intent_claim_id', 'ppe_receipt_intent_fk')->references('id')->on('ppe_command_intent_claims')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique('ppe_receipt_uuid_uq');
            $table->unsignedBigInteger('user_id');
            $table->string('root_type', 20);
            $table->unsignedBigInteger('root_id');
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id');
            $table->string('command', 40);
            $table->unsignedInteger('expected_version')->nullable();
            $table->char('fingerprint', 64);
            $table->json('accepted_result');
            $table->unsignedBigInteger('source_version_id')->nullable();
            $table->unsignedBigInteger('audit_log_id');
            $table->timestamp('created_at', 6);
            $table->index(['root_type', 'root_id'], 'ppe_receipt_root_idx');
            $table->foreign('audit_log_id', 'ppe_receipt_audit_fk')->references('id')->on('audit_logs')->restrictOnDelete();
        });
        Schema::create('ppe_evidence_staging', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('intent_claim_id');
            $table->foreign('intent_claim_id', 'ppe_staging_intent_fk')->references('id')->on('ppe_command_intent_claims')->restrictOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('root_type', 20);
            $table->unsignedBigInteger('root_id')->nullable();
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('site_id');
            $table->string('command', 40);
            $table->uuid('idempotency_key');
            $table->unsignedInteger('expected_version')->nullable();
            $table->char('fingerprint', 64);
            $table->json('intent');
            $table->json('legacy_origin')->nullable();
            $table->char('reservation_digest', 64);
            $table->string('disk')->default('private');
            $table->string('path')->unique('ppe_staging_path_uq');
            $table->char('file_digest', 64);
            $table->unsignedBigInteger('file_size');
            $table->string('state', 20)->default('staged');
            $table->unsignedBigInteger('receipt_id')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps(6);
            $table->index(['user_id', 'idempotency_key'], 'ppe_staging_actor_command_idx');
            $table->index('idempotency_key', 'ppe_staging_uuid_idx');
            $table->foreign('receipt_id', 'ppe_staging_receipt_fk')->references('id')->on('ppe_command_receipts')->restrictOnDelete();
        });
        Schema::create('ppe_evidence_relocation_cleanups', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 30)->default('legacy_public_origin');
            $table->unsignedBigInteger('receipt_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('root_id');
            $table->unsignedBigInteger('site_id');
            $table->string('parent_type', 20);
            $table->unsignedBigInteger('parent_id');
            $table->string('attachment_type', 30);
            $table->unsignedBigInteger('attachment_id');
            $table->string('origin_disk')->default('public');
            $table->string('origin_path');
            $table->char('file_digest', 64);
            $table->unsignedBigInteger('file_size');
            $table->string('destination_path');
            $table->string('state', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('completed_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique('receipt_id', 'ppe_relocation_receipt_uq');
            $table->index(['user_id', 'state'], 'ppe_relocation_actor_state_idx');
            $table->foreign('receipt_id', 'ppe_relocation_receipt_fk')->references('id')->on('ppe_command_receipts')->restrictOnDelete();
        });
        Schema::create('ppe_evidence_cleanups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('receipt_id');
            $table->string('parent_type', 20);
            $table->unsignedBigInteger('parent_id');
            $table->string('attachment_type', 30);
            $table->unsignedBigInteger('attachment_id');
            $table->unsignedBigInteger('user_id');
            $table->string('disk')->default('private');
            $table->string('path');
            $table->char('file_digest', 64)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('state', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('completed_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['attachment_type', 'attachment_id'], 'ppe_cleanup_attachment_uq');
            $table->foreign('receipt_id', 'ppe_cleanup_receipt_fk')->references('id')->on('ppe_command_receipts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['ppe_source_versions', 'ppe_command_intent_claims', 'ppe_command_receipts', 'ppe_evidence_cleanups', 'ppe_evidence_staging', 'ppe_evidence_relocation_cleanups'] as $name) {
            if (Schema::hasTable($name) && DB::table($name)->exists()) {
                throw new RuntimeException('PPE governed acceptance, history and cleanup evidence must be retained.');
            }
        }
        foreach (self::SOURCES as $name) {
            if (DB::table($name)->where('source_schema_version', '!=', 0)->orWhere('source_revision', '!=', 0)->exists()) {
                throw new RuntimeException('PPE governed source metadata cannot be discarded.');
            }
        }
        Schema::dropIfExists('ppe_evidence_relocation_cleanups');
        Schema::dropIfExists('ppe_evidence_staging');
        Schema::dropIfExists('ppe_evidence_cleanups');
        Schema::dropIfExists('ppe_command_receipts');
        Schema::dropIfExists('ppe_source_versions');
        Schema::dropIfExists('ppe_command_intent_claims');
        foreach (self::SOURCES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['source_schema_version', 'source_revision']));
        }
    }
};
