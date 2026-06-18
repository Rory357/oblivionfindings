<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet & Asset Incidents redesign — Step 1 (plan §5 / §6.3, Gap F1).
 *
 * Direct reverse FK so a transport-cascade `ClientIncident` points back at the
 * originating `FleetIncident` (today the link is implicit via shared description).
 * Lets both detail surfaces show the reciprocal link.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_incidents') || Schema::hasColumn('client_incidents', 'fleet_incident_id')) {
            return;
        }

        Schema::table('client_incidents', function (Blueprint $table) {
            $table->unsignedBigInteger('fleet_incident_id')->nullable()->after('id');
            $table->index('fleet_incident_id', 'ci_fleet_incident_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('client_incidents') || ! Schema::hasColumn('client_incidents', 'fleet_incident_id')) {
            return;
        }

        Schema::table('client_incidents', function (Blueprint $table) {
            if (Schema::hasColumn('client_incidents', 'fleet_incident_id')) {
                $table->dropIndex('ci_fleet_incident_idx');
                $table->dropColumn('fleet_incident_id');
            }
        });
    }
};
