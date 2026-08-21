<?php

namespace App\Support;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Models\EmergencyDrill;
use App\Models\SafetyDataSheet;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Assembles the five canonical NZ compliance-badge counts/booleans for the
 * Hazards hero (and reusable by any H&S hero). It feeds the shared
 * `HeroComplianceBadges` component, which expects counts/booleans — never
 * pre-formatted strings.
 *
 * Numbers are sourced from the same models the H&S dashboard / analytics use so
 * the badges agree across heroes:
 *  - worksafe_awaiting → NotifiableIncident pending WorkSafe notifications
 *    (matches HsAnalyticsService::worksafeTotals()['awaiting']).
 *  - drills_due / drills_overdue → EmergencyDrill 6-month cadence per site
 *    (matches HsAnalyticsService::drillStatusBySite()).
 *  - sds_expiring → SafetyDataSheet review dates expiring within 30 days or past.
 *
 * Ngā Paerewa certification and first-aider cover are intentionally absent:
 * those independent Site-scoped signals come from NzsAssuranceResolver.
 */
class HazardComplianceSnapshot
{
    /**
     * @return array{worksafe_awaiting:int, sds_expiring:int, drills_due:int, drills_overdue:int}
     */
    public static function badges(): array
    {
        return [
            'worksafe_awaiting' => self::worksafeAwaiting(),
            'sds_expiring' => self::sdsExpiring(),
            'drills_due' => self::drillCounts()['due'],
            'drills_overdue' => self::drillCounts()['overdue'],
        ];
    }

    private static function worksafeAwaiting(): int
    {
        try {
            return (int) NotifiableIncident::query()
                ->where('notification_authority', 'worksafe')
                ->where('status', 'pending')
                ->count();
        } catch (\Throwable $e) {
            Log::warning('HazardComplianceSnapshot: worksafe count failed', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    private static function sdsExpiring(): int
    {
        try {
            return (int) SafetyDataSheet::query()
                ->whereNotNull('review_date')
                ->whereDate('review_date', '<=', now()->addDays(30)->toDateString())
                ->count();
        } catch (\Throwable $e) {
            Log::warning('HazardComplianceSnapshot: SDS count failed', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * @return array{due:int, overdue:int}
     */
    private static function drillCounts(): array
    {
        try {
            $sixMonthsAgo = Carbon::now()->subMonths(6);
            $lastDrills = EmergencyDrill::query()
                ->whereNotNull('completed_at')
                ->groupBy('site_id')
                ->selectRaw('site_id, MAX(completed_at) as last')
                ->pluck('last', 'site_id');

            $due = 0;
            $overdue = 0;
            foreach ($lastDrills as $last) {
                $d = Carbon::parse($last);
                if ($d->gte($sixMonthsAgo)) {
                    continue; // compliant
                }
                if ($d->gte($sixMonthsAgo->copy()->subMonth())) {
                    $due++;
                } else {
                    $overdue++;
                }
            }

            return ['due' => $due, 'overdue' => $overdue];
        } catch (\Throwable $e) {
            Log::warning('HazardComplianceSnapshot: drill counts failed', ['error' => $e->getMessage()]);

            return ['due' => 0, 'overdue' => 0];
        }
    }
}
