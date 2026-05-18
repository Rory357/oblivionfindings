<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_telemetry_events', function (Blueprint $table) {
            if (! Schema::hasColumn('fleet_telemetry_events', 'address')) {
                $table->string('address')->nullable()->after('raw_payload');
            }

            if (! Schema::hasColumn('fleet_telemetry_events', 'reverse_geocoded_at')) {
                $table->timestamp('reverse_geocoded_at')->nullable()->after('address');
            }

            if (! Schema::hasColumn('fleet_telemetry_events', 'reverse_geocode_failed_at')) {
                $table->timestamp('reverse_geocode_failed_at')->nullable()->after('reverse_geocoded_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fleet_telemetry_events', function (Blueprint $table) {
            foreach (['reverse_geocode_failed_at', 'reverse_geocoded_at', 'address'] as $column) {
                if (Schema::hasColumn('fleet_telemetry_events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
