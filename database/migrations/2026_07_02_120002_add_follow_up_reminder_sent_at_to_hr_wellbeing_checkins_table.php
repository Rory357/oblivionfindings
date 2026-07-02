<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedupe stamp for the wellbeing follow-up reminder: SendWellbeingRemindersJob
 * notifies the check-in's manager once when follow_up_date arrives, then stamps
 * this column so the nudge never repeats.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_wellbeing_checkins', function (Blueprint $table) {
            $table->timestamp('follow_up_reminder_sent_at')->nullable()->after('follow_up_date');
        });
    }

    public function down(): void
    {
        Schema::table('hr_wellbeing_checkins', function (Blueprint $table) {
            $table->dropColumn('follow_up_reminder_sent_at');
        });
    }
};
