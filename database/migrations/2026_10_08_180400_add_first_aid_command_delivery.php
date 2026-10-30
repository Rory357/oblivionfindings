<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Add source command/outcome/delivery evidence without inventing any legacy observation. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('first_aid_records', function (Blueprint $table): void {
            $table->unsignedInteger('lock_version')->default(1);
            $table->uuid('creation_key')->nullable()->unique('fa_creation_key_unique');
            $table->string('creation_fingerprint', 64)->nullable();
        });
        Schema::table('first_aid_followups', function (Blueprint $table): void {
            $table->unsignedInteger('assignment_revision')->default(1);
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users', indexName: 'fa_completion_actor_fk')->restrictOnDelete();
            $table->text('completion_notes')->nullable();
            $table->json('completion_outcome')->nullable();
            $table->json('completion_evidence_ids')->nullable();
        });
        Schema::create('first_aid_command_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('first_aid_record_id')->constrained('first_aid_records', indexName: 'fa_receipt_source_fk')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users', indexName: 'fa_receipt_actor_fk')->restrictOnDelete();
            $table->foreignId('audit_log_id')->constrained('audit_logs', indexName: 'fa_receipt_audit_fk')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique('fa_receipt_key_unique');
            $table->string('command', 40);
            $table->string('fingerprint', 64);
            $table->json('confirmed_result');
            $table->timestamps();
        });
        Schema::create('first_aid_source_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('dedupe_key', 64)->unique('fa_delivery_dedupe_unique');
            $table->string('purpose', 40);
            $table->foreignId('first_aid_record_id')->nullable()->constrained('first_aid_records', indexName: 'fa_delivery_source_fk')->restrictOnDelete();
            $table->foreignId('followup_id')->nullable()->constrained('first_aid_followups', indexName: 'fa_delivery_followup_fk')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users', indexName: 'fa_delivery_recipient_fk')->restrictOnDelete();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users', indexName: 'fa_delivery_actor_fk')->restrictOnDelete();
            $table->unsignedInteger('assignment_revision')->nullable();
            $table->date('reminder_day')->nullable();
            $table->dateTime('due_cutoff_at')->nullable();
            $table->json('membership');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('failure_code', 48)->nullable();
            $table->dateTime('next_attempt_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->foreignId('hs_event_id')->nullable()->constrained('hs_events', indexName: 'fa_delivery_event_fk')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at'], 'fa_delivery_retry_idx');
            $table->index(['first_aid_record_id', 'purpose', 'created_at'], 'fa_delivery_source_idx');
        });
    }

    public function down(): void
    {
        $sourceEvidence = DB::table('first_aid_records')->where('lock_version', '!=', 1)->orWhereNotNull('creation_key')->orWhereNotNull('creation_fingerprint')->exists();
        $outcomeEvidence = DB::table('first_aid_followups')->where('assignment_revision', '!=', 1)->orWhereNotNull('completed_by_user_id')->orWhereNotNull('completion_notes')->orWhereNotNull('completion_outcome')->orWhereNotNull('completion_evidence_ids')->exists();
        if ($sourceEvidence || $outcomeEvidence || DB::table('first_aid_command_receipts')->exists() || DB::table('first_aid_source_deliveries')->exists()) {
            throw new RuntimeException('First Aid source, factual outcome, command or delivery provenance must be retained.');
        }
        Schema::dropIfExists('first_aid_source_deliveries');
        Schema::dropIfExists('first_aid_command_receipts');
        Schema::table('first_aid_followups', function (Blueprint $table): void {
            $table->dropForeign('fa_completion_actor_fk');
            $table->dropColumn(['assignment_revision', 'completed_by_user_id', 'completion_notes', 'completion_outcome', 'completion_evidence_ids']);
        });
        Schema::table('first_aid_records', function (Blueprint $table): void {
            $table->dropUnique('fa_creation_key_unique');
            $table->dropColumn(['lock_version', 'creation_key', 'creation_fingerprint']);
        });
    }
};
