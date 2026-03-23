<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_vehicle_bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('fleet_vehicle_bookings', 'passengers')) {
                $table->integer('passengers')->nullable()->after('purpose');
            }
            if (!Schema::hasColumn('fleet_vehicle_bookings', 'pickup_site_id')) {
                $table->foreignId('pickup_site_id')->nullable()->after('passengers');
            }
            if (!Schema::hasColumn('fleet_vehicle_bookings', 'return_site_id')) {
                $table->foreignId('return_site_id')->nullable()->after('pickup_site_id');
            }
            if (!Schema::hasColumn('fleet_vehicle_bookings', 'notes')) {
                $table->text('notes')->nullable()->after('return_site_id');
            }
            if (!Schema::hasColumn('fleet_vehicle_bookings', 'pre_trip_inspection_id')) {
                $table->unsignedBigInteger('pre_trip_inspection_id')->nullable()->after('notes');
            }
            if (!Schema::hasColumn('fleet_vehicle_bookings', 'post_trip_inspection_id')) {
                $table->unsignedBigInteger('post_trip_inspection_id')->nullable()->after('pre_trip_inspection_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fleet_vehicle_bookings', function (Blueprint $table) {
            $columns = ['passengers', 'pickup_site_id', 'return_site_id', 'notes', 'pre_trip_inspection_id', 'post_trip_inspection_id'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('fleet_vehicle_bookings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
