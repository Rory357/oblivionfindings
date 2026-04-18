<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR M: Add device-level "next service due" date.
 *
 * Separate from DeviceMaintenanceRecord.scheduled_for (which tracks
 * individual maintenance jobs). This column is a fast, denormalised
 * at-a-glance marker operators can set without having to create a
 * maintenance record — useful for surfacing overdue markers on the
 * Dashboard and in CSV exports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->date('next_service_due')->nullable()->after('firmware_version');

            // Cheap index for dashboard "overdue service" rollup.
            $table->index('next_service_due', 'devices_next_service_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex('devices_next_service_due_idx');
            $table->dropColumn('next_service_due');
        });
    }
};
