<?php

namespace App\Services\HealthSafety;

use App\Models\BehaviourSupportPlan;
use App\Models\RestraintEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Restraint & behaviour-support KPIs for the cross-module H&S command centre
 * (dashboard) and analytics explorer. Kept as its own service — separate from the
 * shared HsKpiService / HsAnalyticsService — so the restraints module contributes
 * its numbers without churning those heavily-shared files. NZ least-restrictive
 * practice (Ngā Paerewa NZS 8134:2021): restrictive practice should be rare,
 * within a current plan, reviewed, and trending down.
 *
 * Events scope by their own site_id (set at capture); plans scope via the client's
 * site, matching how the rest of the dashboard site-filters behaviour-support data.
 */
class RestraintKpiService
{
    /**
     * Command-centre summary — period + site scoped. Lagging signals (what happened
     * in the window) plus standing oversight signals (open items, not period-bound).
     *
     * @return array<string,int>
     */
    public function summary(?int $siteId, CarbonInterface $from, CarbonInterface $to): array
    {
        $eventsInPeriod = $this->scopedEvents($siteId)->whereBetween('started_at', [$from, $to]);

        return [
            'events_in_period' => (clone $eventsInPeriod)->count(),
            'out_of_plan' => (clone $eventsInPeriod)->where('within_support_plan', false)->count(),
            'with_injury' => (clone $eventsInPeriod)->where('injury_occurred', true)->count(),
            'critical' => (clone $eventsInPeriod)->where('severity', 'critical')->count(),
            // Standing oversight — open items regardless of period.
            'unreviewed' => $this->scopedEvents($siteId)->whereNull('reviewed_at')->count(),
            'active_plans' => $this->scopedPlans($siteId)->where('status', 'active')->count(),
            'plans_review_due' => $this->scopedPlans($siteId)
                ->whereIn('status', ['active', 'under_review'])
                ->whereNotNull('review_date')
                ->where('review_date', '<=', now()->addDays(30))
                ->count(),
            'clients_no_active_bsp' => $this->clientsWithEventsButNoActivePlan($siteId),
        ];
    }

    /**
     * Unreviewed restraint events worklist for the dashboard Lagging tab — the
     * "needs governance attention" queue.
     *
     * @return array<int,array<string,mixed>>
     */
    public function unreviewedWorklist(?int $siteId, int $limit = 8): array
    {
        return $this->scopedEvents($siteId)
            ->with(['client:id,first_name,last_name'])
            ->whereNull('reviewed_at')
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->map(fn (RestraintEvent $e) => [
                'id' => $e->id,
                'reference' => $e->reference_number ?? 'RE-'.str_pad((string) $e->id, 3, '0', STR_PAD_LEFT),
                'client' => $e->client ? trim($e->client->first_name.' '.$e->client->last_name) : null,
                'restraint_type' => $e->restraint_type,
                'severity' => $e->severity,
                'within_support_plan' => (bool) $e->within_support_plan,
                'injury_occurred' => (bool) $e->injury_occurred,
                'started_at' => optional($e->started_at)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Analytics breakdowns: restraint events by type / severity, plans by status.
     *
     * @return array<string,array<int,array{label:string,count:int}>>
     */
    public function breakdowns(?int $siteId, CarbonInterface $from, CarbonInterface $to): array
    {
        $events = $this->scopedEvents($siteId)->whereBetween('started_at', [$from, $to]);

        return [
            'by_type' => (clone $events)
                ->select('restraint_type', DB::raw('COUNT(*) as count'))
                ->groupBy('restraint_type')
                ->orderByDesc('count')
                ->get()
                ->map(fn ($r) => ['label' => (string) $r->restraint_type, 'count' => (int) $r->count])
                ->all(),
            'by_severity' => (clone $events)
                ->select('severity', DB::raw('COUNT(*) as count'))
                ->groupBy('severity')
                ->orderByDesc('count')
                ->get()
                ->map(fn ($r) => ['label' => (string) $r->severity, 'count' => (int) $r->count])
                ->all(),
            'by_plan_status' => $this->scopedPlans($siteId)
                ->select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->orderByDesc('count')
                ->get()
                ->map(fn ($r) => ['label' => (string) $r->status, 'count' => (int) $r->count])
                ->all(),
        ];
    }

    private function scopedEvents(?int $siteId): Builder
    {
        return RestraintEvent::query()
            ->when($siteId, fn (Builder $q) => $q->where('site_id', $siteId));
    }

    private function scopedPlans(?int $siteId): Builder
    {
        return BehaviourSupportPlan::query()
            ->when($siteId, fn (Builder $q) => $q->whereHas('client', fn (Builder $c) => $c->where('site_id', $siteId)));
    }

    /**
     * Clients who have had a restraint event but have no active behaviour support
     * plan — a least-restrictive-practice governance gap.
     */
    private function clientsWithEventsButNoActivePlan(?int $siteId): int
    {
        $clientIdsWithEvents = $this->scopedEvents($siteId)
            ->whereNotNull('client_id')
            ->distinct()
            ->pluck('client_id');

        if ($clientIdsWithEvents->isEmpty()) {
            return 0;
        }

        $clientsWithActivePlan = BehaviourSupportPlan::query()
            ->whereIn('client_id', $clientIdsWithEvents)
            ->where('status', 'active')
            ->distinct()
            ->pluck('client_id');

        return $clientIdsWithEvents->diff($clientsWithActivePlan)->count();
    }
}
