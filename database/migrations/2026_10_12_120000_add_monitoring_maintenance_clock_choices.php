<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_maintenance_windows', function (Blueprint $table): void {
            $table->string('dst_overlap', 16)->default('reject');
            $table->string('dst_gap', 16)->default('reject');
        });
    }

    public function down(): void
    {
        if (DB::table('monitoring_maintenance_windows')->where('dst_overlap', '!=', 'reject')->orWhere('dst_gap', '!=', 'reject')->exists()) {
            throw new RuntimeException('Reviewed maintenance clock choices are retained. Restore the deployment backup or retain this additive schema when rolling back.');
        }
        Schema::table('monitoring_maintenance_windows', function (Blueprint $table): void {
            $table->dropColumn(['dst_overlap', 'dst_gap']);
        });
    }
};
