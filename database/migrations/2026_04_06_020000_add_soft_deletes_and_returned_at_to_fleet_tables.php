<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SoftDeletes for fleet tables that have the trait but lack the column
        $softDeleteTables = [
            'fleet_vehicle_bookings',
            'fleet_incidents',
            'fleet_outings',
        ];

        foreach ($softDeleteTables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->softDeletes();
                });
            }
        }

        // Resident accountability: track when each resident is marked as returned
        if (Schema::hasTable('fleet_outing_residents') && !Schema::hasColumn('fleet_outing_residents', 'returned_at')) {
            Schema::table('fleet_outing_residents', function (Blueprint $table) {
                $table->timestamp('returned_at')->nullable()->after('notes');
            });
        }
    }

    public function down(): void
    {
        $softDeleteTables = [
            'fleet_vehicle_bookings',
            'fleet_incidents',
            'fleet_outings',
        ];

        foreach ($softDeleteTables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropSoftDeletes();
                });
            }
        }

        if (Schema::hasTable('fleet_outing_residents') && Schema::hasColumn('fleet_outing_residents', 'returned_at')) {
            Schema::table('fleet_outing_residents', function (Blueprint $table) {
                $table->dropColumn('returned_at');
            });
        }
    }
};
