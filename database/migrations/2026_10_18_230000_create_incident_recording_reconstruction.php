<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Passive H&S persistence only. No existing incident, policy, source or grant is backfilled. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_evidence_aggregates', function (Blueprint $table): void {
            $table->unsignedBigInteger('incident_id')->primary();
            $table->foreign('incident_id', 'iea_incident_fk')->references('id')->on('client_incidents')->restrictOnDelete();
            $table->unsignedInteger('incident_evidence_version');
            $table->unsignedInteger('active_window_revision')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users', indexName: 'iea_created_actor_fk')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->constrained('users', indexName: 'iea_updated_actor_fk')->restrictOnDelete();
            $table->dateTime('created_at', 6); $table->dateTime('updated_at', 6);
        });
        Schema::create('incident_evidence_windows', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('incident_id'); $table->unsignedInteger('window_revision');
            $table->unsignedInteger('introduced_evidence_version');
            $table->foreign('incident_id', 'iew_aggregate_fk')->references('incident_id')->on('incident_evidence_aggregates')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users', indexName: 'iew_actor_fk')->restrictOnDelete();
            $table->dateTime('recorded_at', 6); $table->dateTime('start_at'); $table->dateTime('end_at');
            $table->string('rule_identifier', 128); $table->unsignedInteger('rule_revision');
            $table->foreignId('policy_change_id')->constrained('health_safety_setting_changes', indexName: 'iew_policy_fk')->restrictOnDelete();
            $table->char('rule_snapshot_sha256', 64);
            $table->longText('encrypted_override_reason')->nullable();
            $table->foreignId('effective_site_id')->constrained('sites', indexName: 'iew_site_fk')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients', indexName: 'iew_client_fk')->restrictOnDelete();
            $table->longText('encrypted_context_anchor'); $table->char('context_anchor_sha256', 64);
            $table->longText('encrypted_approved_sources'); $table->char('source_set_sha256', 64); $table->char('window_sha256', 64);
            $table->foreignId('audit_log_id')->constrained('audit_logs', indexName: 'iew_audit_fk')->restrictOnDelete();
            $table->unique(['incident_id', 'window_revision'], 'iew_revision_unique');
            $table->unique(['incident_id', 'window_revision', 'effective_site_id'], 'iew_revision_site_unique');
            $table->unique(['incident_id', 'window_revision', 'start_at', 'end_at'], 'iew_exact_window_unique');
        });
        Schema::table('incident_evidence_aggregates', function (Blueprint $table): void {
            $table->foreign(['incident_id', 'active_window_revision'], 'iea_active_window_fk')
                ->references(['incident_id', 'window_revision'])->on('incident_evidence_windows')->restrictOnDelete();
        });
        Schema::create('incident_evidence_window_sources', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('incident_id'); $table->unsignedInteger('window_revision');
            $table->unsignedBigInteger('site_id');
            $table->foreign(['incident_id', 'window_revision', 'site_id'], 'iews_window_site_fk')
                ->references(['incident_id', 'window_revision', 'effective_site_id'])->on('incident_evidence_windows')->restrictOnDelete();
            $table->foreignId('camera_id')->constrained('devices', indexName: 'iews_camera_fk')->restrictOnDelete();
            $table->foreignId('recorder_device_id')->constrained('devices', indexName: 'iews_recorder_fk')->restrictOnDelete();
            $table->unique(['incident_id', 'window_revision', 'camera_id', 'recorder_device_id', 'site_id'], 'iews_identity_unique');
        });
        Schema::create('incident_evidence_revisions', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('incident_id'); $table->unsignedInteger('incident_evidence_version');
            $table->foreign('incident_id', 'ier_aggregate_fk')->references('incident_id')->on('incident_evidence_aggregates')->restrictOnDelete();
            // Passive journal capacity; no supersede/unlink/new-window command is activated by this schema.
            $table->string('kind', 40); $table->unsignedInteger('window_revision');
            $table->uuid('attachment_uuid')->nullable(); $table->uuid('predecessor_attachment_uuid')->nullable();
            $table->foreignId('actor_user_id')->constrained('users', indexName: 'ier_actor_fk')->restrictOnDelete();
            $table->dateTime('recorded_at', 6); $table->longText('encrypted_reason')->nullable();
            $table->foreignId('audit_log_id')->constrained('audit_logs', indexName: 'ier_audit_fk')->restrictOnDelete();
            $table->unique(['incident_id', 'incident_evidence_version'], 'ier_version_unique');
            $table->foreign(['incident_id', 'window_revision'], 'ier_window_fk')
                ->references(['incident_id', 'window_revision'])->on('incident_evidence_windows')->restrictOnDelete();
        });
        Schema::create('incident_recording_attachments', function (Blueprint $table): void {
            $table->id(); $table->uuid('attachment_uuid')->unique('ira_uuid_unique');
            $table->unsignedBigInteger('incident_id'); $table->unsignedInteger('window_revision');
            $table->unsignedInteger('attached_evidence_version');
            $table->uuid('reference_uuid');
            $table->foreign('reference_uuid', 'ira_reference_fk')->references('uuid')->on('cctv_recording_references')->restrictOnDelete();
            $table->unsignedBigInteger('camera_id'); $table->unsignedBigInteger('recorder_device_id'); $table->unsignedBigInteger('site_id');
            $table->foreign(['incident_id', 'window_revision', 'camera_id', 'recorder_device_id', 'site_id'], 'ira_approved_source_fk')
                ->references(['incident_id', 'window_revision', 'camera_id', 'recorder_device_id', 'site_id'])
                ->on('incident_evidence_window_sources')->restrictOnDelete();
            $table->foreign(['incident_id', 'attached_evidence_version'], 'ira_revision_fk')
                ->references(['incident_id', 'incident_evidence_version'])->on('incident_evidence_revisions')->restrictOnDelete();
            $table->dateTime('requested_start_at'); $table->dateTime('requested_end_at');
            $table->foreign(['incident_id', 'window_revision', 'requested_start_at', 'requested_end_at'], 'ira_exact_window_fk')
                ->references(['incident_id', 'window_revision', 'start_at', 'end_at'])->on('incident_evidence_windows')->restrictOnDelete();
            $table->longText('encrypted_source_snapshot'); $table->char('source_snapshot_sha256', 64);
            $table->longText('encrypted_purpose');
            $table->foreignId('actor_user_id')->constrained('users', indexName: 'ira_actor_fk')->restrictOnDelete();
            $table->dateTime('recorded_at', 6);
            $table->foreignId('audit_log_id')->constrained('audit_logs', indexName: 'ira_audit_fk')->restrictOnDelete();
            $table->unique(['incident_id', 'reference_uuid', 'window_revision'], 'ira_original_link_unique');
            $table->unique(['incident_id', 'attached_evidence_version'], 'ira_evidence_version_unique');
            $table->unique(['incident_id', 'attached_evidence_version', 'attachment_uuid'], 'ira_confirmed_identity_unique');
        });
        Schema::create('incident_recording_command_receipts', function (Blueprint $table): void {
            $table->id(); $table->uuid('idempotency_key')->unique('ircr_key_unique');
            $table->unsignedBigInteger('incident_id'); $table->foreignId('actor_user_id')->constrained('users', indexName: 'ircr_actor_fk')->restrictOnDelete();
            $table->string('command', 40); $table->char('fingerprint', 64);
            $table->unsignedInteger('submitted_evidence_version'); $table->unsignedInteger('window_revision');
            $table->unsignedInteger('confirmed_evidence_version'); $table->uuid('attachment_uuid');
            $table->unique('attachment_uuid', 'ircr_original_command_unique');
            $table->foreign(['incident_id', 'confirmed_evidence_version', 'attachment_uuid'], 'ircr_attachment_fk')
                ->references(['incident_id', 'attached_evidence_version', 'attachment_uuid'])->on('incident_recording_attachments')->restrictOnDelete();
            // Immutable committed identity only: never cached current_source/provider metadata or a read grant.
            $table->json('confirmed_result'); $table->dateTime('confirmed_at', 6);
            $table->foreignId('audit_log_id')->constrained('audit_logs', indexName: 'ircr_audit_fk')->restrictOnDelete();
            $table->index(['actor_user_id', 'incident_id', 'id'], 'ircr_actor_incident_idx');
        });
        // Same MySQL CHECK convention as existing workflow/stock invariants; execution remains unverified.
        DB::statement('ALTER TABLE incident_evidence_aggregates ADD CONSTRAINT iea_versions_check CHECK (
            incident_evidence_version BETWEEN 1 AND 2147483647 AND (active_window_revision IS NULL OR active_window_revision BETWEEN 1 AND 2147483647))');
        DB::statement('ALTER TABLE incident_evidence_windows ADD CONSTRAINT iew_values_check CHECK (
            window_revision BETWEEN 1 AND 2147483647 AND introduced_evidence_version BETWEEN 1 AND 2147483647
            AND rule_revision BETWEEN 1 AND 2147483647 AND start_at < end_at)');
        DB::statement("ALTER TABLE incident_evidence_revisions ADD CONSTRAINT ier_values_check CHECK (
            incident_evidence_version BETWEEN 1 AND 2147483647 AND window_revision BETWEEN 1 AND 2147483647
            AND kind IN ('window_recorded','window_invalidated','recording_attached','recording_superseded','recording_unlinked')
            AND (incident_evidence_version <> 1 OR kind = 'window_recorded'))");
        DB::statement('ALTER TABLE incident_recording_attachments ADD CONSTRAINT ira_version_check CHECK (attached_evidence_version BETWEEN 2 AND 2147483647)');
        DB::statement("ALTER TABLE incident_recording_command_receipts ADD CONSTRAINT ircr_values_check CHECK (
            command = 'attach_recording_reference' AND submitted_evidence_version BETWEEN 1 AND 2147483646
            AND confirmed_evidence_version = submitted_evidence_version + 1 AND window_revision BETWEEN 1 AND 2147483647)");
    }

    public function down(): void
    {
        foreach (['incident_evidence_aggregates', 'incident_evidence_windows', 'incident_evidence_window_sources',
            'incident_evidence_revisions', 'incident_recording_attachments', 'incident_recording_command_receipts'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Incident reconstruction evidence and command provenance must be retained.');
            }
        }
        Schema::dropIfExists('incident_recording_command_receipts');
        Schema::dropIfExists('incident_recording_attachments');
        Schema::dropIfExists('incident_evidence_revisions');
        Schema::dropIfExists('incident_evidence_window_sources');
        if (Schema::hasTable('incident_evidence_aggregates')) {
            foreach (Schema::getForeignKeys('incident_evidence_aggregates') as $foreign) {
                if (($foreign['name']??null)==='iea_active_window_fk') {
                    Schema::table('incident_evidence_aggregates', fn (Blueprint $table) => $table->dropForeign('iea_active_window_fk'));
                    break;
                }
            }
        }
        Schema::dropIfExists('incident_evidence_windows');
        Schema::dropIfExists('incident_evidence_aggregates');
    }
};