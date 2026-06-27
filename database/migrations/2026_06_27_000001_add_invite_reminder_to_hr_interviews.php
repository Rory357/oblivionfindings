<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency timestamps for interview comms (A6 / handover item 16): when the
 * calendar invite was emailed and when the day-before reminder was sent, so the
 * reminder command never double-sends. Additive + nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_interviews', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_interviews', 'invite_sent_at')) {
                $table->timestamp('invite_sent_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('hr_interviews', 'reminder_sent_at')) {
                $table->timestamp('reminder_sent_at')->nullable()->after('invite_sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_interviews', function (Blueprint $table) {
            foreach (['invite_sent_at', 'reminder_sent_at'] as $column) {
                if (Schema::hasColumn('hr_interviews', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
