<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedupe stamps for the daily offer-expiry and PIP reminder sweeps:
 *
 * - hr_offers.expiry_reminder_sent_at   — last "offer expiring soon" nudge
 *   (candidate + hiring manager). Re-armed once more inside the final-day
 *   window; never re-sent after that.
 * - hr_offers.expired_notice_sent_at    — one-time "offer expired unanswered"
 *   notice to the hiring manager.
 * - hr_pip_milestones.overdue_reminder_sent_at — one-time overdue-milestone
 *   nudge to the PIP manager + employee.
 * - hr_performance_improvement_plans.end_reminder_sent_at — one-time
 *   "plan ends within 7 days / has ended" nudge to the PIP manager.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_offers', 'expiry_reminder_sent_at')) {
                $table->timestamp('expiry_reminder_sent_at')->nullable()->after('portal_expires_at');
            }
            if (! Schema::hasColumn('hr_offers', 'expired_notice_sent_at')) {
                $table->timestamp('expired_notice_sent_at')->nullable()->after('expiry_reminder_sent_at');
            }
        });

        Schema::table('hr_pip_milestones', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_pip_milestones', 'overdue_reminder_sent_at')) {
                $table->timestamp('overdue_reminder_sent_at')->nullable()->after('reviewed_at');
            }
        });

        Schema::table('hr_performance_improvement_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_performance_improvement_plans', 'end_reminder_sent_at')) {
                $table->timestamp('end_reminder_sent_at')->nullable()->after('completed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_offers', function (Blueprint $table) {
            if (Schema::hasColumn('hr_offers', 'expiry_reminder_sent_at')) {
                $table->dropColumn('expiry_reminder_sent_at');
            }
            if (Schema::hasColumn('hr_offers', 'expired_notice_sent_at')) {
                $table->dropColumn('expired_notice_sent_at');
            }
        });

        Schema::table('hr_pip_milestones', function (Blueprint $table) {
            if (Schema::hasColumn('hr_pip_milestones', 'overdue_reminder_sent_at')) {
                $table->dropColumn('overdue_reminder_sent_at');
            }
        });

        Schema::table('hr_performance_improvement_plans', function (Blueprint $table) {
            if (Schema::hasColumn('hr_performance_improvement_plans', 'end_reminder_sent_at')) {
                $table->dropColumn('end_reminder_sent_at');
            }
        });
    }
};
