<?php

namespace App\Domain\Hr\Services;

use Illuminate\Support\Facades\DB;

class RecruitmentAnalyticsService
{
    /** @param list<int> $applicationIds */
    public function getTimeToHire(array $applicationIds, int $months = 12): array
    {
        $since = now()->subMonths($months)->startOfMonth();

        $results = DB::table('hr_offers')
            ->join('hr_applications', 'hr_offers.application_id', '=', 'hr_applications.id')
            ->whereIn('hr_applications.id', $applicationIds)
            ->where('hr_offers.created_at', '>=', $since)
            ->whereNotNull('hr_offers.created_at')
            ->selectRaw("
                DATE_FORMAT(hr_offers.created_at, '%Y-%m') as month,
                AVG(DATEDIFF(hr_offers.created_at, hr_applications.created_at)) as avg_days,
                COUNT(*) as count
            ")
            ->groupByRaw("DATE_FORMAT(hr_offers.created_at, '%Y-%m')")
            ->orderBy('month')
            ->get();

        return $results->map(fn ($r) => [
            'month' => $r->month,
            'avg_days' => round((float) $r->avg_days, 1),
            'count' => (int) $r->count,
        ])->toArray();
    }

    /** @param list<int> $candidateIds */
    public function getSourceEffectiveness(array $candidateIds, ?string $from = null, ?string $to = null): array
    {
        $results = DB::table('hr_candidates')
            ->whereIn('id', $candidateIds)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->selectRaw("
                source,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'hired' THEN 1 ELSE 0 END) as hired,
                SUM(CASE WHEN status NOT IN ('withdrawn', 'rejected') THEN 1 ELSE 0 END) as active
            ")
            ->groupBy('source')
            ->orderByDesc('total')
            ->get();

        return $results->map(fn ($r) => [
            'source' => $r->source,
            'total' => (int) $r->total,
            'hired' => (int) $r->hired,
            'active' => (int) $r->active,
            'conversion_rate' => $r->total > 0 ? round(($r->hired / $r->total) * 100, 1) : 0,
        ])->toArray();
    }

    /** @param list<int> $candidateIds */
    public function getPipelineConversion(array $candidateIds, ?string $from = null, ?string $to = null): array
    {
        $stages = RecruitmentService::STAGES;

        $counts = DB::table('hr_candidates')
            ->whereIn('id', $candidateIds)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $total = array_sum($counts);
        $result = [];

        foreach ($stages as $stage) {
            $count = $counts[$stage] ?? 0;
            $result[] = [
                'stage' => $stage,
                'count' => $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
            ];
        }

        foreach (['withdrawn', 'rejected'] as $terminal) {
            $count = $counts[$terminal] ?? 0;
            $result[] = [
                'stage' => $terminal,
                'count' => $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
            ];
        }

        return $result;
    }

    /**
     * Open positions keyed on requisition_id (not the free-text position_title),
     * so two requisitions that happen to share a title stay distinct. Optional
     * created-at date window. COALESCE keeps legacy applications without a linked
     * requisition visible under their stored title.
     */
    /** @param list<int> $applicationIds */
    public function getOpenPositionsSummary(array $applicationIds, ?string $from = null, ?string $to = null): array
    {
        $results = DB::table('hr_applications')
            ->leftJoin('hr_job_requisitions as r', 'r.id', '=', 'hr_applications.requisition_id')
            ->whereIn('hr_applications.id', $applicationIds)
            ->whereNotIn('hr_applications.status', ['rejected', 'withdrawn'])
            ->when($from, fn ($q) => $q->where('hr_applications.created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('hr_applications.created_at', '<=', $to))
            ->selectRaw('
                hr_applications.requisition_id,
                COALESCE(r.title, hr_applications.position_title) as title,
                COUNT(*) as applications,
                MIN(hr_applications.created_at) as first_application,
                DATEDIFF(NOW(), MIN(hr_applications.created_at)) as days_open
            ')
            // Group by the raw columns inside COALESCE (not the alias) so MySQL's
            // ONLY_FULL_GROUP_BY is satisfied — grouping by the alias leaves
            // position_title non-functionally-dependent and 500s the page.
            ->groupBy('hr_applications.requisition_id', 'r.title', 'hr_applications.position_title')
            ->orderByDesc('applications')
            ->get();

        return $results->map(fn ($r) => [
            'requisition_id' => $r->requisition_id !== null ? (int) $r->requisition_id : null,
            'position_title' => $r->title,
            'applications' => (int) $r->applications,
            'days_open' => (int) $r->days_open,
            'first_application' => $r->first_application,
        ])->toArray();
    }

    /** @param list<int> $applicationIds */
    public function getHiringVelocity(array $applicationIds, int $months = 12): array
    {
        $since = now()->subMonths($months)->startOfMonth();

        $results = DB::table('hr_offers')
            ->join('hr_applications', 'hr_offers.application_id', '=', 'hr_applications.id')
            ->whereIn('hr_applications.id', $applicationIds)
            ->where('hr_offers.response', 'accepted')
            ->where('hr_offers.response_at', '>=', $since)
            ->selectRaw("
                DATE_FORMAT(hr_offers.response_at, '%Y-%m') as month,
                COUNT(*) as count
            ")
            ->groupByRaw("DATE_FORMAT(hr_offers.response_at, '%Y-%m')")
            ->orderBy('month')
            ->get();

        return $results->map(fn ($r) => [
            'month' => $r->month,
            'count' => (int) $r->count,
        ])->toArray();
    }

    /** @param list<int> $candidateIds */
    public function getStageBottlenecks(array $candidateIds): array
    {
        $results = DB::table('hr_candidates')
            ->whereIn('id', $candidateIds)
            ->whereNotIn('status', ['withdrawn', 'rejected', 'hired'])
            ->whereNotNull('current_stage_entered_at')
            ->selectRaw('
                status,
                AVG(DATEDIFF(NOW(), current_stage_entered_at)) as avg_days,
                COUNT(*) as count
            ')
            ->groupBy('status')
            ->orderByDesc('avg_days')
            ->get();

        return $results->map(fn ($r) => [
            'stage' => $r->status,
            'avg_days' => round((float) $r->avg_days, 1),
            'count' => (int) $r->count,
        ])->toArray();
    }

    /** @param list<int> $candidateIds */
    public function getMonthlyApplicationTrend(array $candidateIds, int $months = 12): array
    {
        $since = now()->subMonths($months)->startOfMonth();

        $results = DB::table('hr_candidates')
            ->whereIn('id', $candidateIds)
            ->where('created_at', '>=', $since)
            ->selectRaw("
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as count
            ")
            ->groupByRaw("DATE_FORMAT(created_at, '%Y-%m')")
            ->orderBy('month')
            ->get();

        return $results->map(fn ($r) => [
            'month' => $r->month,
            'count' => (int) $r->count,
        ])->toArray();
    }
}
