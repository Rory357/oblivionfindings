<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedupe stamp for the hr:probation-reminders sweep — set when the manager has
 * been notified that a probation review is due; cleared when probation is
 * extended (new end date ⇒ a fresh reminder should fire for it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employee_profiles', function (Blueprint $table) {
            $table->timestamp('probation_reminder_sent_at')->nullable()->after('probation_end_date');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employee_profiles', function (Blueprint $table) {
            $table->dropColumn('probation_reminder_sent_at');
        });
    }
};
