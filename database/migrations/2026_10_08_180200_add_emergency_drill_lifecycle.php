<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emergency_drills', function (Blueprint $table): void {
            $table->unsignedInteger('lock_version')->default(1);
            $table->uuid('creation_key')->nullable()->unique('drill_creation_key_unique');
            $table->string('creation_fingerprint', 64)->nullable();
            $table->foreignId('previous_drill_id')->nullable()->constrained('emergency_drills', indexName: 'drill_previous_fk')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users', indexName: 'drill_cancel_actor_fk')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
        });
        Schema::table('emergency_drill_participants', fn (Blueprint $table) => $table->text('accommodations')->nullable());
        Schema::table('emergency_drill_findings', function (Blueprint $table): void {
            $table->foreignId('hs_corrective_action_id')->nullable()->constrained('hs_corrective_actions', indexName: 'drill_finding_remedy_fk')->restrictOnDelete();
            $table->unique('hs_corrective_action_id', 'drill_finding_remedy_unique');
            $table->json('resolution_evidence_ids')->nullable();
        });
        Schema::create('emergency_drill_command_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emergency_drill_id')->constrained('emergency_drills', indexName: 'drill_receipt_source_fk')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users', indexName: 'drill_receipt_actor_fk')->restrictOnDelete();
            $table->foreignId('audit_log_id')->constrained('audit_logs', indexName: 'drill_receipt_audit_fk')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique('drill_receipt_key_unique');
            $table->string('fingerprint', 64);
            $table->json('confirmed_result');
            $table->timestamps();
        });
        Schema::create('emergency_drill_failure_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emergency_drill_id')->constrained('emergency_drills', indexName: 'drill_delivery_source_fk')->restrictOnDelete();
            $table->unique('emergency_drill_id', 'drill_delivery_source_unique');
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users', indexName: 'drill_delivery_actor_fk')->restrictOnDelete();
            $table->foreignId('hs_event_id')->nullable()->constrained('hs_events', indexName: 'drill_delivery_event_fk')->restrictOnDelete();
            $table->foreignId('signal_id')->nullable()->constrained('control_room_signals', indexName: 'drill_delivery_signal_fk')->restrictOnDelete();
            $table->string('signal_key', 64)->unique('drill_delivery_signal_unique');
            $table->string('event_status')->default('pending');
            $table->string('signal_status')->default('pending');
            $table->timestamp('event_delivered_at')->nullable();
            $table->timestamp('signal_delivered_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('failure_code')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
            $table->index(['next_attempt_at', 'event_status', 'signal_status'], 'drill_delivery_retry_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('emergency_drill_command_receipts')->exists() || DB::table('emergency_drill_failure_deliveries')->exists()
            || DB::table('emergency_drills')->whereNotNull('creation_key')->orWhereNotNull('creation_fingerprint')->orWhereNotNull('previous_drill_id')->orWhereNotNull('cancelled_at')->orWhereNotNull('cancelled_by')->orWhereNotNull('cancellation_reason')->orWhere('lock_version', '>', 1)->exists()
            || DB::table('emergency_drill_participants')->whereNotNull('accommodations')->exists()
            || DB::table('emergency_drill_findings')->whereNotNull('hs_corrective_action_id')->orWhereNotNull('resolution_evidence_ids')->exists()) {
            throw new RuntimeException('Preserve drill lifecycle, remedy and delivery evidence before rollback.');
        }
        Schema::dropIfExists('emergency_drill_failure_deliveries');
        Schema::dropIfExists('emergency_drill_command_receipts');
        Schema::table('emergency_drill_findings', function (Blueprint $table): void {
            $table->dropForeign('drill_finding_remedy_fk');
            $table->dropUnique('drill_finding_remedy_unique');
            $table->dropColumn(['hs_corrective_action_id', 'resolution_evidence_ids']);
        });
        Schema::table('emergency_drill_participants', fn (Blueprint $table) => $table->dropColumn('accommodations'));
        Schema::table('emergency_drills', function (Blueprint $table): void {
            $table->dropForeign('drill_previous_fk');
            $table->dropForeign('drill_cancel_actor_fk');
            $table->dropUnique('drill_creation_key_unique');
            $table->dropColumn(['lock_version', 'creation_key', 'creation_fingerprint', 'previous_drill_id', 'cancelled_at', 'cancelled_by', 'cancellation_reason']);
        });
    }
};
