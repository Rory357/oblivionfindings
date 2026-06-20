<?php

namespace App\Services\HealthSafety;

use App\Models\EmergencyDrill;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Single source of truth for emergency-drill compliance.
 *
 * Canonical definition (was duplicated/divergent across HsAnalyticsService,
 * EmergencyDrillController and the H&S dashboard): a site is graded on its MOST
 * RECENT completed drill within a rolling 6-month window —
 *   • last completed >= now-6mo                       => compliant
 *   • last completed in the 6–7mo grace band          => due_soon
 *   • older, or the site has NEVER completed a drill   => overdue
 *
 * Uses whereNotNull('completed_at') (a drill is "done" once it has a completion
 * timestamp). Sites with no completed drill are absent from statusBySite() and
 * default to 'overdue' via statusForSite().
 */
class DrillComplianceService
{
    /**
     * Per-site grade keyed by site id. Omits sites with no completed drill.
     *
     * @return array<int,'compliant'|'due_soon'|'overdue'>
     */
    public function statusBySite(): array
    {
        $sixMonthsAgo = Carbon::now()->subMonths(6);
        $graceFloor = $sixMonthsAgo->copy()->subMonth();

        return EmergencyDrill::query()
            ->whereNotNull('completed_at')
            ->groupBy('site_id')
            ->selectRaw('site_id, MAX(completed_at) as last')
            ->pluck('last', 'site_id')
            ->map(function ($last) use ($sixMonthsAgo, $graceFloor) {
                $date = Carbon::parse($last);

                if ($date->gte($sixMonthsAgo)) {
                    return 'compliant';
                }

                return $date->gte($graceFloor) ? 'due_soon' : 'overdue';
            })
            ->all();
    }

    /**
     * Grade for one site (zero-drill sites default to 'overdue').
     */
    public function statusForSite(int $siteId): string
    {
        return $this->statusBySite()[$siteId] ?? 'overdue';
    }

    /**
     * Org-wide breakdown over ACTIVE sites — the reconciled headline numbers.
     *
     * @return array{total_sites:int, compliant:int, due_soon:int, overdue:int, pct:int}
     */
    public function summary(): array
    {
        $byId = $this->statusBySite();
        $activeSiteIds = Site::query()->where('is_active', true)->pluck('id');

        $compliant = 0;
        $dueSoon = 0;
        $overdue = 0;

        foreach ($activeSiteIds as $id) {
            switch ($byId[$id] ?? 'overdue') {
                case 'compliant':
                    $compliant++;
                    break;
                case 'due_soon':
                    $dueSoon++;
                    break;
                default:
                    $overdue++;
            }
        }

        $total = $activeSiteIds->count();

        return [
            'total_sites' => $total,
            'compliant' => $compliant,
            'due_soon' => $dueSoon,
            'overdue' => $overdue,
            'pct' => $total > 0 ? (int) round(($compliant / $total) * 100) : 0,
        ];
    }

    /**
     * Reconciled compliance percentage (active sites with a current drill).
     */
    public function compliancePct(): int
    {
        return $this->summary()['pct'];
    }

    /**
     * Count of active sites currently graded 'overdue' (never drilled or >7mo).
     */
    public function sitesOverdue(): int
    {
        return $this->summary()['overdue'];
    }

    /**
     * Per-site drill snapshot for the site profile (Drills tab + badge).
     *
     * @return array{drill_status:string, last_drill_at:?string, next_drill_at:?string, scheduled_count:int, open_findings:int}
     */
    public function siteSummary(int $siteId): array
    {
        $lastDrillAt = EmergencyDrill::query()
            ->where('site_id', $siteId)
            ->whereNotNull('completed_at')
            ->max('completed_at');

        $nextDrillAt = EmergencyDrill::query()
            ->where('site_id', $siteId)
            ->where('status', 'scheduled')
            ->where('scheduled_at', '>=', Carbon::now())
            ->min('scheduled_at');

        $scheduledCount = EmergencyDrill::query()
            ->where('site_id', $siteId)
            ->where('status', 'scheduled')
            ->count();

        $openFindings = EmergencyDrill::query()
            ->where('site_id', $siteId)
            ->withCount(['findings as open_findings_count' => fn ($q) => $q->whereNotIn('status', ['resolved', 'closed'])])
            ->get()
            ->sum('open_findings_count');

        return [
            'drill_status' => $this->statusForSite($siteId),
            'last_drill_at' => $lastDrillAt ? Carbon::parse($lastDrillAt)->toIso8601String() : null,
            'next_drill_at' => $nextDrillAt ? Carbon::parse($nextDrillAt)->toIso8601String() : null,
            'scheduled_count' => $scheduledCount,
            'open_findings' => (int) $openFindings,
        ];
    }
}
