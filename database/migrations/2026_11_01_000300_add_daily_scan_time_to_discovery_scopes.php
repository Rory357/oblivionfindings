<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase B: a hub can scan its network once a day on its own. The time is New Zealand
 * wall-clock "HH:MM". Empty means off: a daily scan runs only after someone switches it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_discovery_scopes', function (Blueprint $table): void {
            $table->string('daily_scan_at', 5)->nullable()->after('schedule_cron');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_discovery_scopes', function (Blueprint $table): void {
            $table->dropColumn('daily_scan_at');
        });
    }
};
