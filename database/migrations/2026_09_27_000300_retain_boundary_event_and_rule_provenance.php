<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boundary_rule_versions', function (Blueprint $table): void {
            // Pre-existing assignments can outlive their deleted boundary. Keep
            // their snapshot and audit, with an honest absent source reference.
            $table->unsignedBigInteger('boundary_id')->nullable()->change();
        });
        Schema::table('fleet_signals', function (Blueprint $table): void {
            $table->foreignId('source_event_id')->nullable()->constrained('fleet_telemetry_events')->nullOnDelete();
        });
        // Do not invent source links for old signals from timestamps or latest state.
        // Missing provenance withholds location history, without deleting evidence.
    }

    public function down(): void
    {
        if (DB::table('boundary_rule_versions')->whereNull('boundary_id')->exists()
            || DB::table('fleet_signals')->whereNotNull('source_event_id')->exists()) {
            throw new RuntimeException('Boundary provenance exists. Preserve it and use a forward migration.');
        }
        Schema::table('fleet_signals', fn (Blueprint $table) => $table->dropConstrainedForeignId('source_event_id'));
        Schema::table('boundary_rule_versions', fn (Blueprint $table) => $table->unsignedBigInteger('boundary_id')->nullable(false)->change());
    }
};
