<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Add factual provenance without inventing historical review, ownership, approval or delivery. */
return new class extends Migration
{
    private const TABLES = ['restraint_event_reviews', 'restraint_monitoring_observations', 'restraint_debriefs', 'restraint_followups', 'restraint_followup_histories', 'restraint_command_receipts', 'restraint_source_deliveries'];

    private const EVENT_COLUMNS = ['lock_version', 'source_revision', 'source_schema_version', 'creation_key', 'creation_fingerprint', 'recorded_client_id', 'recorded_site_id', 'recorded_stay_id', 'behaviour_support_plan_version_id', 'plan_authorization_state', 'plan_authorization_snapshot', 'review_owner_id', 'review_due_at', 'review_assignment_revision', 'reviewed_source_revision'];

    private const EVIDENCE_COLUMNS = ['sha256', 'source_revision', 'removed_by', 'removed_at', 'removal_reason', 'removal_command_key', 'uploader_snapshot'];

    public function up(): void
    {
        Schema::table('restraint_events', function (Blueprint $t): void {
            // Widen precision without changing any legacy instant; down retains this safe precision.
            $t->dateTime('started_at', 6)->change();
            $t->dateTime('ended_at', 6)->nullable()->change();
            $t->dateTime('reviewed_at', 6)->nullable()->change();
            $t->unsignedInteger('lock_version')->default(1);
            $t->unsignedInteger('source_revision')->default(1);
            $t->unsignedSmallInteger('source_schema_version')->nullable();
            $t->uuid('creation_key')->nullable()->unique('rst_creation_key_unique');
            $t->char('creation_fingerprint', 64)->nullable();
            foreach (['recorded_client_id', 'recorded_site_id', 'recorded_stay_id', 'behaviour_support_plan_version_id'] as $field) {
                $t->unsignedBigInteger($field)->nullable();
            }
            $t->string('plan_authorization_state', 40)->nullable();
            $t->json('plan_authorization_snapshot')->nullable();
            $t->unsignedBigInteger('review_owner_id')->nullable();
            $t->dateTime('review_due_at', 6)->nullable();
            $t->unsignedInteger('review_assignment_revision')->default(0);
            $t->unsignedInteger('reviewed_source_revision')->nullable();
            $t->index(['review_owner_id', 'review_due_at'], 'rst_review_owner_due_idx');
        });
        Schema::table('restraint_event_attachments', function (Blueprint $t): void {
            $t->char('sha256', 64)->nullable();
            $t->unsignedInteger('source_revision')->nullable();
            $t->unsignedBigInteger('removed_by')->nullable();
            $t->dateTime('removed_at', 6)->nullable();
            $t->text('removal_reason')->nullable();
            $t->uuid('removal_command_key')->nullable();
            $t->json('uploader_snapshot')->nullable();
        });
        foreach (['restraint_event_reviews', 'restraint_monitoring_observations', 'restraint_debriefs', 'restraint_followup_histories'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table): void {
                $t->id();
                $t->unsignedBigInteger('restraint_event_id');
                $t->unsignedInteger('source_revision');
                $t->unsignedBigInteger('recorder_id')->nullable();
                $t->json('actor_snapshot');
                $t->unsignedBigInteger('actual_actor_id')->nullable();
                $t->json('actual_actor_snapshot')->nullable();
                $t->unsignedBigInteger('supersedes_id')->nullable();
                $t->dateTime('occurred_at', 6)->nullable();
                $t->json('facts');
                $t->json('evidence_ids')->nullable();
                $t->uuid('command_key');
                $t->timestamps();
                if ($table === 'restraint_followup_histories') {
                    $t->unsignedBigInteger('followup_id');
                }
                $t->index(['restraint_event_id', 'id'], substr($table, 0, 38).'_source_idx');
                $t->foreign('restraint_event_id', substr($table, 0, 38).'_source_fk')->references('id')->on('restraint_events')->restrictOnDelete();
            });
        }
        Schema::create('restraint_followups', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('restraint_event_id');
            $t->text('notes');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->json('creator_snapshot');
            $t->unsignedBigInteger('assigned_to_user_id')->nullable();
            $t->unsignedInteger('assignment_revision')->default(1);
            $t->dateTime('due_at', 6)->nullable();
            $t->dateTime('completed_at', 6)->nullable();
            $t->unsignedBigInteger('completed_by_user_id')->nullable();
            $t->json('completion_outcome')->nullable();
            $t->json('completion_evidence_ids')->nullable();
            $t->dateTime('cancelled_at', 6)->nullable();
            $t->unsignedBigInteger('cancelled_by')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->timestamps();
            $t->foreign('restraint_event_id', 'rst_followup_source_fk')->references('id')->on('restraint_events')->restrictOnDelete();
            $t->index(['assigned_to_user_id', 'due_at'], 'rst_followup_owner_due_idx');
        });
        Schema::create('restraint_command_receipts', function (Blueprint $t): void {
            $t->id();
            $t->string('source_type', 16);
            $t->unsignedBigInteger('source_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->json('actor_snapshot')->nullable();
            $t->foreignId('audit_log_id')->constrained('audit_logs', indexName: 'rst_receipt_audit_fk')->restrictOnDelete();
            $t->uuid('idempotency_key')->unique('rst_command_key_unique');
            $t->string('command', 48);
            $t->unsignedBigInteger('expected_version')->nullable();
            $t->char('fingerprint', 64);
            $t->json('accepted_result');
            $t->dateTime('created_at', 6);
            $t->index(['source_type', 'source_id'], 'rst_receipt_source_idx');
        });
        Schema::create('restraint_source_deliveries', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->char('dedupe_key', 64)->unique('rst_delivery_key_unique');
            $t->string('purpose', 40);
            foreach (['restraint_event_id', 'followup_id', 'attachment_id', 'recipient_user_id', 'initiated_by_user_id', 'hs_event_id', 'control_room_alert_id', 'linked_incident_id'] as $field) {
                $t->unsignedBigInteger($field)->nullable();
            }
            $t->unsignedInteger('assignment_revision')->nullable();
            $t->date('reminder_day')->nullable();
            $t->dateTime('due_cutoff_at', 6)->nullable();
            $t->json('membership');
            $t->string('status', 20)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->string('failure_code', 48)->nullable();
            $t->dateTime('next_attempt_at', 6)->nullable();
            $t->dateTime('delivered_at', 6)->nullable();
            $t->timestamps();
            $t->index(['status', 'next_attempt_at'], 'rst_delivery_retry_idx');
        });
    }

    public function down(): void
    {
        // Test every preservation guard before the first destructive operation.
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Restraint source command, factual history or delivery evidence must be retained.');
            }
        }
        $events = DB::table('restraint_events')->where('lock_version', '!=', 1)->orWhere('source_revision', '!=', 1)->orWhere('review_assignment_revision', '!=', 0);
        foreach (array_diff(self::EVENT_COLUMNS, ['lock_version', 'source_revision', 'review_assignment_revision']) as $field) {
            $events->orWhereNotNull($field);
        }
        if ($events->exists()) {
            throw new RuntimeException('Restraint event provenance must be retained.');
        }
        $evidence = DB::table('restraint_event_attachments')->whereRaw('1 = 0');
        foreach (self::EVIDENCE_COLUMNS as $field) {
            $evidence->orWhereNotNull($field);
        }
        if ($evidence->exists()) {
            throw new RuntimeException('Restraint evidence provenance must be retained.');
        }
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('restraint_events', function (Blueprint $t): void {
            $t->dropUnique('rst_creation_key_unique');
            $t->dropIndex('rst_review_owner_due_idx');
            $t->dropColumn(self::EVENT_COLUMNS);
        });
        Schema::table('restraint_event_attachments', fn (Blueprint $t) => $t->dropColumn(self::EVIDENCE_COLUMNS));
    }
};
