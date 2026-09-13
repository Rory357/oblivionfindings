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
            $table->unsignedBigInteger('device_event_id')->nullable()->change();
            $table->foreignId('fleet_signal_id')->nullable()->constrained('fleet_signals')->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('monitoring_incident_evidence_snapshots')->whereNull('device_event_id')->orWhereNotNull('fleet_signal_id')->exists()) {
            throw new LogicException('Fleet evidence must be retained before rolling back its canonical source.');
        }
        Schema::table('monitoring_incident_evidence_snapshots', function (Blueprint $table): void {
            $table->dropForeign(['fleet_signal_id']);
            $table->dropForeign(['asset_id']);
            $table->dropColumn(['fleet_signal_id', 'asset_id']);
            $table->unsignedBigInteger('device_event_id')->nullable(false)->change();
        });
    }
};
