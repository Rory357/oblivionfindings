<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_telemetry_snapshots', function (Blueprint $table) {
            $table->foreignId('device_id')
                ->nullable()
                ->after('asset_tracker_id')
                ->constrained('devices')
                ->nullOnDelete();
            $table->index(['device_id', 'occurred_at'], 'asset_telemetry_snapshots_device_occurred_idx');
        });

        Schema::table('fleet_telemetry_events', function (Blueprint $table) {
            $table->foreignId('device_id')
                ->nullable()
                ->after('asset_tracker_id')
                ->constrained('devices')
                ->nullOnDelete();
            $table->index(['device_id', 'occurred_at'], 'fleet_telemetry_events_device_occurred_idx');
        });

        Schema::table('fleet_signals', function (Blueprint $table) {
            $table->foreignId('device_id')
                ->nullable()
                ->after('asset_tracker_id')
                ->constrained('devices')
                ->nullOnDelete();
            $table->index(['device_id', 'occurred_at'], 'fleet_signals_device_occurred_idx');
        });

        DB::table('devices')
            ->select('legacy_asset_tracker_id')
            ->selectRaw('MIN(id) as device_id')
            ->whereNotNull('legacy_asset_tracker_id')
            ->groupBy('legacy_asset_tracker_id')
            ->havingRaw('COUNT(*) = 1')
            ->orderBy('legacy_asset_tracker_id')
            ->chunk(500, function ($mappings): void {
                foreach ($mappings as $mapping) {
                    DB::table('asset_telemetry_snapshots')
                        ->where('asset_tracker_id', $mapping->legacy_asset_tracker_id)
                        ->whereNull('device_id')
                        ->update(['device_id' => $mapping->device_id]);

                    DB::table('fleet_telemetry_events')
                        ->where('asset_tracker_id', $mapping->legacy_asset_tracker_id)
                        ->whereNull('device_id')
                        ->update(['device_id' => $mapping->device_id]);

                    DB::table('fleet_signals')
                        ->where('asset_tracker_id', $mapping->legacy_asset_tracker_id)
                        ->whereNull('device_id')
                        ->update(['device_id' => $mapping->device_id]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('fleet_signals', function (Blueprint $table) {
            $table->dropForeign(['device_id']);
            $table->dropIndex('fleet_signals_device_occurred_idx');
            $table->dropColumn('device_id');
        });

        Schema::table('fleet_telemetry_events', function (Blueprint $table) {
            $table->dropForeign(['device_id']);
            $table->dropIndex('fleet_telemetry_events_device_occurred_idx');
            $table->dropColumn('device_id');
        });

        Schema::table('asset_telemetry_snapshots', function (Blueprint $table) {
            $table->dropForeign(['device_id']);
            $table->dropIndex('asset_telemetry_snapshots_device_occurred_idx');
            $table->dropColumn('device_id');
        });
    }
};
