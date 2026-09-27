<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_geofence_rule_versions', fn (Blueprint $t) => $t->json('canonical_boundary_snapshot')->nullable());
    }

    public function down(): void
    {
        if (DB::table('client_geofence_rule_versions')->whereNotNull('canonical_boundary_snapshot')->exists()) {
            throw new RuntimeException('Client boundary provenance is retained evidence. Use a forward migration.');
        }
        Schema::table('client_geofence_rule_versions', fn (Blueprint $t) => $t->dropColumn('canonical_boundary_snapshot'));
    }
};
