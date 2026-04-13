<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short-term bridge: adds a nullable device_id FK to each legacy hardware
 * table so that consumers not yet refactored can follow the FK to the
 * canonical devices table.  These columns will be dropped when the legacy
 * tables are retired (PR23–PR26).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_hardware', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->after('meta')
                ->constrained('devices')->nullOnDelete();
            $table->index('device_id', 'lh_device_id_idx');
        });

        Schema::table('control_room_devices', function (Blueprint $table) {
            $table->foreignId('canonical_device_id')->nullable()->after('low_battery_alert_sent')
                ->constrained('devices')->nullOnDelete();
            $table->index('canonical_device_id', 'crd_canonical_device_id_idx');
        });

        Schema::table('asset_trackers', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->after('vendor_metadata')
                ->constrained('devices')->nullOnDelete();
            $table->index('device_id', 'at_device_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('location_hardware', function (Blueprint $table) {
            $table->dropForeign(['device_id']);
            $table->dropIndex('lh_device_id_idx');
            $table->dropColumn('device_id');
        });

        Schema::table('control_room_devices', function (Blueprint $table) {
            $table->dropForeign(['canonical_device_id']);
            $table->dropIndex('crd_canonical_device_id_idx');
            $table->dropColumn('canonical_device_id');
        });

        Schema::table('asset_trackers', function (Blueprint $table) {
            $table->dropForeign(['device_id']);
            $table->dropIndex('at_device_id_idx');
            $table->dropColumn('device_id');
        });
    }
};
