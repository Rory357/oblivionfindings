<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fleet_medication_transit_logs')) {
            return;
        }

        Schema::table('fleet_medication_transit_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('fleet_medication_transit_logs', 'packed_witness_name')) {
                $table->string('packed_witness_name')->nullable()->after('is_controlled_drug');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('fleet_medication_transit_logs')) {
            return;
        }

        Schema::table('fleet_medication_transit_logs', function (Blueprint $table) {
            if (Schema::hasColumn('fleet_medication_transit_logs', 'packed_witness_name')) {
                $table->dropColumn('packed_witness_name');
            }
        });
    }
};
