<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_telemetry_snapshots', function (Blueprint $table): void {
            $table->dropForeign(['asset_tracker_id']);
            $table->unsignedBigInteger('asset_tracker_id')->nullable()->change();
            $table->foreign('asset_tracker_id')
                ->references('id')
                ->on('asset_trackers')
                ->nullOnDelete();
        });

        Schema::table('fleet_telemetry_events', function (Blueprint $table): void {
            $table->dropForeign(['asset_tracker_id']);
            $table->unsignedBigInteger('asset_tracker_id')->nullable()->change();
            $table->foreign('asset_tracker_id')
                ->references('id')
                ->on('asset_trackers')
                ->nullOnDelete();
        });

        // Existing canonical devices may pre-date DeviceAssetLink. Promote only
        // unambiguous paired legacy lineage when the device has no active link;
        // never overwrite or compete with an existing canonical ownership link.
        DB::table('devices')
            ->join('asset_trackers', 'asset_trackers.id', '=', 'devices.legacy_asset_tracker_id')
            ->whereNull('devices.deleted_at')
            ->where('asset_trackers.status', 'paired')
            ->select([
                'devices.id as device_id',
                'asset_trackers.id as asset_tracker_id',
                'asset_trackers.asset_id',
                'asset_trackers.paired_at',
                'asset_trackers.created_at as tracker_created_at',
            ])
            ->orderBy('devices.id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    $canonicalDeviceCount = DB::table('devices')
                        ->where('legacy_asset_tracker_id', $row->asset_tracker_id)
                        ->whereNull('deleted_at')
                        ->count();
                    $hasActiveLink = DB::table('device_asset_links')
                        ->where('device_id', $row->device_id)
                        ->whereNull('unlinked_at')
                        ->exists();

                    if ($canonicalDeviceCount !== 1 || $hasActiveLink) {
                        continue;
                    }

                    $linkedAt = $row->paired_at ?? $row->tracker_created_at ?? now();
                    DB::table('device_asset_links')->insert([
                        'device_id' => $row->device_id,
                        'asset_id' => $row->asset_id,
                        'link_type' => 'installed_in',
                        'linked_at' => $linkedAt,
                        'unlinked_at' => null,
                        'linked_by_user_id' => null,
                        'notes' => 'Canonical link backfilled from historical telemetry lineage.',
                        'created_at' => $linkedAt,
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (DB::table('asset_telemetry_snapshots')->whereNull('asset_tracker_id')->exists()
            || DB::table('fleet_telemetry_events')->whereNull('asset_tracker_id')->exists()) {
            throw new RuntimeException(
                'Cannot restore required asset_tracker_id columns while canonical-only telemetry exists.',
            );
        }

        Schema::table('fleet_telemetry_events', function (Blueprint $table): void {
            $table->dropForeign(['asset_tracker_id']);
            $table->unsignedBigInteger('asset_tracker_id')->nullable(false)->change();
            $table->foreign('asset_tracker_id')
                ->references('id')
                ->on('asset_trackers')
                ->cascadeOnDelete();
        });

        Schema::table('asset_telemetry_snapshots', function (Blueprint $table): void {
            $table->dropForeign(['asset_tracker_id']);
            $table->unsignedBigInteger('asset_tracker_id')->nullable(false)->change();
            $table->foreign('asset_tracker_id')
                ->references('id')
                ->on('asset_trackers')
                ->cascadeOnDelete();
        });
    }
};
