<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_incident_evidence_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('control_room_alert_id');
            $table->foreignId('it_ticket_id')->unique();
            $table->foreignId('device_id');
            $table->foreignId('device_event_id');
            $table->foreignId('site_id');
            $table->unsignedSmallInteger('evidence_version')->default(1);
            $table->timestamp('captured_at');
            $table->json('snapshot');
            $table->char('checksum', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['control_room_alert_id', 'created_at'], 'monitoring_incident_evidence_alert_idx');
            $table->index(['site_id', 'captured_at'], 'monitoring_incident_evidence_site_idx');
            $table->unique(['device_event_id', 'it_ticket_id'], 'monitoring_incident_evidence_event_ticket_uq');

            $table->foreign('control_room_alert_id', 'mon_incident_evidence_alert_fk')
                ->references('id')->on('control_room_alerts')->restrictOnDelete();
            $table->foreign('it_ticket_id', 'mon_incident_evidence_ticket_fk')
                ->references('id')->on('it_tickets')->restrictOnDelete();
            $table->foreign('device_id', 'mon_incident_evidence_device_fk')
                ->references('id')->on('devices')->restrictOnDelete();
            $table->foreign('device_event_id', 'mon_incident_evidence_event_fk')
                ->references('id')->on('device_events')->restrictOnDelete();
            $table->foreign('site_id', 'mon_incident_evidence_site_fk')
                ->references('id')->on('sites')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_incident_evidence_snapshots');
    }
};
