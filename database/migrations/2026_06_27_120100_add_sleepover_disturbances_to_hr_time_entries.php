<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sleepover disturbance log — wake-ups during a sleepover are paid as active
 * time. Stored as a JSON array of {start, end, minutes} rows on the entry so the
 * Add/Edit/Clock-on-behalf wizards can capture them when is_sleepover is on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_time_entries', function (Blueprint $table) {
            $table->json('sleepover_disturbances')->nullable()->after('is_public_holiday');
        });
    }

    public function down(): void
    {
        Schema::table('hr_time_entries', function (Blueprint $table) {
            $table->dropColumn('sleepover_disturbances');
        });
    }
};
