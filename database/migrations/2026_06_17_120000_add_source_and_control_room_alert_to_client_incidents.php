<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incidents redesign — Gap C + Gap D.
 *
 * Gap C: distinguish how an incident entered the system — staff-reported vs
 *        operator-flagged in the Control Room vs sensor/automated detection.
 *        `interactive` (staff-driven vs machine-driven) is DERIVED from source
 *        via a model accessor, not stored, to avoid drift.
 *
 * Gap D: a first-class, bidirectional link between a ClientIncident and the
 *        ControlRoomAlert that raised/tracks it — today this is only inferred
 *        indirectly through HsEvent.control_room_alert_id or a soft id in the
 *        alert's `context` JSON.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_incidents', function (Blueprint $table) {
            // Gap C — how the incident was raised. manual = staff-reported (default,
            // backfills all existing rows), control_room = operator-flagged,
            // sensor = device/signal-detected, automated = system-generated.
            $table->string('source', 20)->default('manual')->after('type')->index();

            // Gap D — direct FK to the Control Room alert (nullOnDelete: losing the
            // alert must never delete the incident, which is the system of record).
            $table->foreignId('control_room_alert_id')
                ->nullable()
                ->after('shift_id')
                ->constrained('control_room_alerts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_incidents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('control_room_alert_id');
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
