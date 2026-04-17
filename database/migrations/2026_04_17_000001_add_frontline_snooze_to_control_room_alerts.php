<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frontline snooze support for control_room_alerts (PR 17).
 *
 * A small, per-alert snooze window that hides the alert from the assigned
 * frontline worker's /my-day open items until the window elapses. The alert
 * itself stays open — its CR-side status and SLA are untouched — so this is
 * strictly a frontline quality-of-life signal, not a lifecycle change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('control_room_alerts', function (Blueprint $table) {
            $table->timestamp('snoozed_until')->nullable()->after('closed_at');
            $table->foreignId('snoozed_by_user_id')->nullable()->after('snoozed_until')->constrained('users')->nullOnDelete();
            $table->index(['assigned_to_user_id', 'snoozed_until']);
        });
    }

    public function down(): void
    {
        Schema::table('control_room_alerts', function (Blueprint $table) {
            $table->dropIndex(['assigned_to_user_id', 'snoozed_until']);
            $table->dropConstrainedForeignId('snoozed_by_user_id');
            $table->dropColumn('snoozed_until');
        });
    }
};
