<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_incident_evidence_snapshots', function (Blueprint $table): void {
            $table->unsignedBigInteger('control_room_alert_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('monitoring_incident_evidence_snapshots')->whereNull('control_room_alert_id')->exists()) {
            throw new LogicException('Direct monitoring evidence must be retained; rollback requires an approved evidence migration.');
        }
        Schema::table('monitoring_incident_evidence_snapshots', function (Blueprint $table): void {
            $table->unsignedBigInteger('control_room_alert_id')->nullable(false)->change();
        });
    }
};
