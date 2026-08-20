<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safeguarding_terminal_transitions', function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key', 191);
            $table->foreignId('safeguarding_concern_id');
            $table->foreignId('hs_event_id');
            $table->foreignId('control_room_alert_id')->nullable();
            $table->foreignId('site_id')->nullable();
            $table->foreignId('requested_by_user_id');
            $table->foreignId('applied_by_user_id')->nullable();
            $table->string('target_status', 40);
            $table->string('status', 20)->default('pending');
            $table->string('authority', 100);
            $table->text('reason');
            $table->text('override_reason')->nullable();
            $table->string('evidence_reference', 191);
            $table->json('authority_snapshot');
            $table->json('evidence_snapshot');
            $table->char('request_hash', 64);
            $table->char('evidence_hash', 64);
            $table->char('provenance_hash', 64)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('last_error_code', 191)->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key', 'stt_idempotency_uq');
            $table->unique('safeguarding_concern_id', 'stt_concern_uq');
            $table->index('hs_event_id', 'stt_hs_event_idx');
            $table->index('control_room_alert_id', 'stt_control_alert_idx');
            $table->index('site_id', 'stt_site_idx');
            $table->index('requested_by_user_id', 'stt_requested_by_idx');
            $table->index('applied_by_user_id', 'stt_applied_by_idx');
            $table->index('status', 'stt_status_idx');
            $table->unique('provenance_hash', 'stt_provenance_uq');

            $table->foreign('safeguarding_concern_id', 'stt_concern_fk')
                ->references('id')->on('safeguarding_concerns')->restrictOnDelete();
            $table->foreign('hs_event_id', 'stt_hs_event_fk')
                ->references('id')->on('hs_events')->restrictOnDelete();
            $table->foreign('control_room_alert_id', 'stt_control_alert_fk')
                ->references('id')->on('control_room_alerts')->restrictOnDelete();
            $table->foreign('site_id', 'stt_site_fk')
                ->references('id')->on('sites')->restrictOnDelete();
            $table->foreign('requested_by_user_id', 'stt_requested_by_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('applied_by_user_id', 'stt_applied_by_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('safeguarding_terminal_transitions')) {
            return;
        }

        Schema::table('safeguarding_terminal_transitions', function (Blueprint $table): void {
            $table->dropForeign('stt_concern_fk');
            $table->dropForeign('stt_hs_event_fk');
            $table->dropForeign('stt_control_alert_fk');
            $table->dropForeign('stt_site_fk');
            $table->dropForeign('stt_requested_by_fk');
            $table->dropForeign('stt_applied_by_fk');

            $table->dropUnique('stt_idempotency_uq');
            $table->dropUnique('stt_concern_uq');
            $table->dropIndex('stt_hs_event_idx');
            $table->dropIndex('stt_control_alert_idx');
            $table->dropIndex('stt_site_idx');
            $table->dropIndex('stt_requested_by_idx');
            $table->dropIndex('stt_applied_by_idx');
            $table->dropIndex('stt_status_idx');
            $table->dropUnique('stt_provenance_uq');
        });

        Schema::dropIfExists('safeguarding_terminal_transitions');
    }
};
