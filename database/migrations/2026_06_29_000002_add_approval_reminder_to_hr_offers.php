<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escalation for offers stuck awaiting sign-off: stamps when a reminder was last
 * sent to the approver so the daily command never double-nudges. Cleared when an
 * offer is (re)submitted for approval, re-arming the next reminder cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_offers', 'approval_reminder_sent_at')) {
                $table->timestamp('approval_reminder_sent_at')->nullable()->after('approval_declined_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_offers', function (Blueprint $table) {
            if (Schema::hasColumn('hr_offers', 'approval_reminder_sent_at')) {
                $table->dropColumn('approval_reminder_sent_at');
            }
        });
    }
};
