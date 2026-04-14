<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR26 cleanup: drop bridge columns that are no longer used by live reads.
 *
 * Kept intentionally:
 * - devices.legacy_location_hardware_id
 * - devices.legacy_asset_tracker_id
 * - control_room_devices.canonical_device_id
 *
 * Removed here after repo-wide audit:
 * - devices.legacy_control_room_device_id
 * - location_hardware.device_id
 * - asset_trackers.device_id
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            if (Schema::hasColumn('devices', 'legacy_control_room_device_id')) {
                $table->dropIndex(['legacy_control_room_device_id']);
                $table->dropColumn('legacy_control_room_device_id');
            }
        });

        Schema::table('location_hardware', function (Blueprint $table) {
            if (Schema::hasColumn('location_hardware', 'device_id')) {
                $table->dropForeign(['device_id']);
                $table->dropIndex('lh_device_id_idx');
                $table->dropColumn('device_id');
            }
        });

        Schema::table('asset_trackers', function (Blueprint $table) {
            if (Schema::hasColumn('asset_trackers', 'device_id')) {
                $table->dropForeign(['device_id']);
                $table->dropIndex('at_device_id_idx');
                $table->dropColumn('device_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            if (!Schema::hasColumn('devices', 'legacy_control_room_device_id')) {
                $table->unsignedBigInteger('legacy_control_room_device_id')->nullable()->after('legacy_location_hardware_id');
                $table->index('legacy_control_room_device_id');
            }
        });

        Schema::table('location_hardware', function (Blueprint $table) {
            if (!Schema::hasColumn('location_hardware', 'device_id')) {
                $table->foreignId('device_id')->nullable()->after('meta')
                    ->constrained('devices')->nullOnDelete();
                $table->index('device_id', 'lh_device_id_idx');
            }
        });

        Schema::table('asset_trackers', function (Blueprint $table) {
            if (!Schema::hasColumn('asset_trackers', 'device_id')) {
                $table->foreignId('device_id')->nullable()->after('vendor_metadata')
                    ->constrained('devices')->nullOnDelete();
                $table->index('device_id', 'at_device_id_idx');
            }
        });
    }
};
