<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-Gap 2 — WorkSafe notification workflow. The create migration holds
 * worksafe_status + worksafe_reference but no place to persist *when* / *how*
 * the regulator was notified/acknowledged, nor the HSWA site-preservation duty.
 * These four additive, nullable columns close that gap (mirrors the
 * worksafe_notified_at already on client_incidents / fleet_incidents).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hs_events', function (Blueprint $table) {
            $table->timestamp('worksafe_notified_at')->nullable()->after('worksafe_reference');
            $table->string('worksafe_method', 50)->nullable()->after('worksafe_notified_at');
            $table->timestamp('worksafe_acknowledged_at')->nullable()->after('worksafe_method');
            $table->boolean('worksafe_site_preserved')->default(false)->after('worksafe_acknowledged_at');
        });
    }

    public function down(): void
    {
        Schema::table('hs_events', function (Blueprint $table) {
            $table->dropColumn([
                'worksafe_notified_at',
                'worksafe_method',
                'worksafe_acknowledged_at',
                'worksafe_site_preserved',
            ]);
        });
    }
};
