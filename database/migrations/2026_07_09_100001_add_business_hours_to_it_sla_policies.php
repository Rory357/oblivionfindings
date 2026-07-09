<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §P-S1 (stretch) — optional business-hours calendar for the SLA clocks.
 *
 * Both columns are nullable and default to null on every existing row, so the
 * v1 24/7 behaviour is preserved — nothing regresses until a tenant opts in.
 *
 *  - business_hours: per-weekday window map, e.g.
 *      {"mon":[["08:00","17:00"]], ..., "sat":[], "sun":[]}
 *  - holiday_dates: list of "Y-m-d" strings counted as non-working.
 *
 * Windows are read in the worker timezone (config('app.worker_timezone')).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_sla_policies', function (Blueprint $table) {
            $table->json('business_hours')->nullable()->after('resolution_minutes');
            $table->json('holiday_dates')->nullable()->after('business_hours');
        });
    }

    public function down(): void
    {
        Schema::table('it_sla_policies', function (Blueprint $table) {
            $table->dropColumn(['business_hours', 'holiday_dates']);
        });
    }
};
