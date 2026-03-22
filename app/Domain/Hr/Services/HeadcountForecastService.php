<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPosition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class HeadcountForecastService
{
    /**
     * Returns: total active, by department, by employment type, FTE total.
     */
    public function getCurrentHeadcount(?int $tenantId): array
    {
        $profiles = HrEmployeeProfile::where('is_active', true)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->get(['id', 'department', 'employment_type', 'hours_per_week']);

        $total = $profiles->count();

        $byDepartment = $profiles->groupBy('department')
            ->map(fn ($items, $dept) => [
                'department' => $dept ?: 'Unassigned',
                'count' => $items->count(),
            ])
            ->values()
            ->sortByDesc('count')
            ->values()
            ->toArray();

        $byEmploymentType = $profiles->groupBy('employment_type')
            ->map(fn ($items, $type) => [
                'type' => $type ?: 'Unknown',
                'count' => $items->count(),
            ])
            ->values()
            ->toArray();

        // Approximate FTE: full-time = 1.0, else hours/40
        $fteTotal = $profiles->sum(function ($p) {
            if (strtolower($p->employment_type ?? '') === 'full_time') {
                return 1.0;
            }
            return ($p->hours_per_week ?? 0) / 40;
        });

        return [
            'total' => $total,
            'fte_total' => round($fteTotal, 1),
            'by_department' => $byDepartment,
            'by_employment_type' => $byEmploymentType,
        ];
    }

    /**
     * Project headcount based on: current + open positions - anticipated terminations.
     * Returns monthly forecast array with projected headcount.
     */
    public function getForecast(?int $tenantId, int $months = 12): array
    {
        $now = Carbon::now();
        $currentCount = HrEmployeeProfile::where('is_active', true)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->count();

        // Open positions (vacancies)
        $openPositions = HrPosition::where('is_active', true)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->get()
            ->sum(fn ($p) => max(0, ($p->headcount_budget ?? 0) - ($p->current_headcount ?? 0)));

        // Employees with end_date set in the future (known departures)
        $knownDepartures = HrEmployeeProfile::where('is_active', true)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotNull('end_date')
            ->where('end_date', '>', $now)
            ->where('end_date', '<=', $now->copy()->addMonths($months))
            ->selectRaw('DATE_FORMAT(end_date, "%Y-%m") as month, COUNT(*) as departures')
            ->groupBy('month')
            ->pluck('departures', 'month')
            ->toArray();

        // Build monthly forecast
        $forecast = [];
        $projected = $currentCount;
        $monthlyHireRate = $openPositions > 0 ? ceil($openPositions / max($months, 1)) : 0;

        for ($i = 0; $i < $months; $i++) {
            $date = $now->copy()->addMonths($i);
            $monthKey = $date->format('Y-m');

            $departures = $knownDepartures[$monthKey] ?? 0;
            $projected = $projected + $monthlyHireRate - $departures;

            $forecast[] = [
                'month' => $date->format('M Y'),
                'projected' => max(0, $projected),
                'departures' => $departures,
                'hires' => $monthlyHireRate,
            ];
        }

        return $forecast;
    }

    /**
     * Compare position headcount_budget vs current_headcount across all positions.
     * Returns: total budgeted, total filled, total vacant, by department.
     */
    public function getBudgetVsActual(?int $tenantId): array
    {
        $positions = HrPosition::where('is_active', true)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->get(['id', 'title', 'department', 'headcount_budget', 'current_headcount']);

        $totalBudgeted = $positions->sum('headcount_budget');
        $totalFilled = $positions->sum('current_headcount');
        $totalVacant = max(0, $totalBudgeted - $totalFilled);

        $byDepartment = $positions->groupBy('department')
            ->map(fn ($items, $dept) => [
                'department' => $dept ?: 'Unassigned',
                'budgeted' => $items->sum('headcount_budget'),
                'filled' => $items->sum('current_headcount'),
                'vacant' => max(0, $items->sum('headcount_budget') - $items->sum('current_headcount')),
                'fill_rate' => $items->sum('headcount_budget') > 0
                    ? round(($items->sum('current_headcount') / $items->sum('headcount_budget')) * 100, 1)
                    : 0,
            ])
            ->values()
            ->sortByDesc('vacant')
            ->values()
            ->toArray();

        $byPosition = $positions->map(fn ($p) => [
            'id' => $p->id,
            'title' => $p->title,
            'department' => $p->department ?: 'Unassigned',
            'budgeted' => $p->headcount_budget ?? 0,
            'filled' => $p->current_headcount ?? 0,
            'vacant' => max(0, ($p->headcount_budget ?? 0) - ($p->current_headcount ?? 0)),
            'fill_rate' => ($p->headcount_budget ?? 0) > 0
                ? round((($p->current_headcount ?? 0) / $p->headcount_budget) * 100, 1)
                : 0,
        ])->toArray();

        return [
            'total_budgeted' => $totalBudgeted,
            'total_filled' => $totalFilled,
            'total_vacant' => $totalVacant,
            'fill_rate' => $totalBudgeted > 0 ? round(($totalFilled / $totalBudgeted) * 100, 1) : 0,
            'by_department' => $byDepartment,
            'by_position' => $byPosition,
        ];
    }

    /**
     * Employees approaching milestones (1yr, 2yr, 5yr) -- common turnover points.
     * Returns list of at-risk employees with tenure and risk factors.
     */
    public function getAttritionRisk(?int $tenantId): array
    {
        $now = Carbon::now();

        // Milestone windows: employees within 3 months of these anniversaries
        $milestones = [
            ['years' => 1, 'label' => '1-Year Mark', 'risk' => 'high'],
            ['years' => 2, 'label' => '2-Year Mark', 'risk' => 'medium'],
            ['years' => 5, 'label' => '5-Year Mark', 'risk' => 'medium'],
        ];

        $atRisk = [];

        foreach ($milestones as $milestone) {
            $anniversaryDate = $now->copy()->subYears($milestone['years']);
            $windowStart = $anniversaryDate->copy()->subMonths(3);
            $windowEnd = $anniversaryDate->copy()->addMonths(3);

            $employees = HrEmployeeProfile::where('is_active', true)
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->whereBetween('start_date', [$windowStart, $windowEnd])
                ->with('user:id,name,email')
                ->get(['id', 'user_id', 'start_date', 'department', 'position_title']);

            foreach ($employees as $emp) {
                $tenure = $now->diffInMonths(Carbon::parse($emp->start_date));
                $atRisk[] = [
                    'id' => $emp->id,
                    'name' => $emp->user?->name ?? 'Unknown',
                    'department' => $emp->department,
                    'position_title' => $emp->position_title,
                    'start_date' => $emp->start_date,
                    'tenure_months' => $tenure,
                    'tenure_label' => $this->formatTenure($tenure),
                    'milestone' => $milestone['label'],
                    'risk_level' => $milestone['risk'],
                ];
            }
        }

        // Sort by risk level (high first)
        usort($atRisk, fn ($a, $b) => $a['risk_level'] === 'high' ? -1 : ($b['risk_level'] === 'high' ? 1 : 0));

        return $atRisk;
    }

    private function formatTenure(int $months): string
    {
        $years = intdiv($months, 12);
        $remaining = $months % 12;

        if ($years === 0) {
            return "{$remaining}m";
        }

        return $remaining > 0 ? "{$years}y {$remaining}m" : "{$years}y";
    }
}
