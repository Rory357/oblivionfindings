<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WorkforceAnalyticsService
{
    /**
     * Get headcount trend over the specified number of months.
     *
     * Returns an array of {month, count} entries representing the active
     * employee headcount at the end of each month.
     *
     * @param  int|null  $tenantId
     * @param  int       $months
     * @return array<int, array{month: string, count: int}>
     */
    public function getHeadcountTrend(?int $tenantId, int $months = 12): array
    {
        $trend = [];
        $now = Carbon::now();

        for ($i = $months - 1; $i >= 0; $i--) {
            $date = $now->copy()->subMonths($i)->endOfMonth();

            $count = HrEmployeeProfile::forTenant($tenantId)
                ->where('start_date', '<=', $date)
                ->where(function ($q) use ($date) {
                    $q->whereNull('end_date')
                      ->orWhere('end_date', '>', $date);
                })
                ->count();

            $trend[] = [
                'month' => $date->format('M Y'),
                'count' => $count,
            ];
        }

        return $trend;
    }

    /**
     * Calculate the turnover rate for a given period.
     *
     * Turnover = (Separations during period / Average headcount) * 100
     *
     * @param  int|null  $tenantId
     * @param  string    $period  'quarter' or 'year'
     * @return array{rate: float, separations: int, avg_headcount: float}
     */
    public function getTurnoverRate(?int $tenantId, string $period = 'year'): array
    {
        $now = Carbon::now();
        $start = $period === 'quarter'
            ? $now->copy()->subQuarter()->startOfDay()
            : $now->copy()->subYear()->startOfDay();

        $separations = HrEmployeeProfile::forTenant($tenantId)
            ->whereNotNull('end_date')
            ->where('end_date', '>=', $start)
            ->where('end_date', '<=', $now)
            ->count();

        $startCount = HrEmployeeProfile::forTenant($tenantId)
            ->where('start_date', '<=', $start)
            ->where(function ($q) use ($start) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>', $start);
            })
            ->count();

        $endCount = HrEmployeeProfile::forTenant($tenantId)
            ->active()
            ->count();

        $avgHeadcount = ($startCount + $endCount) / 2;

        $rate = $avgHeadcount > 0
            ? round(($separations / $avgHeadcount) * 100, 1)
            : 0;

        return [
            'rate' => $rate,
            'separations' => $separations,
            'avg_headcount' => $avgHeadcount,
        ];
    }

    /**
     * Get employee tenure brackets.
     *
     * Groups active employees into tenure ranges: <1yr, 1-2yr, 2-5yr, 5-10yr, 10+yr
     *
     * @param  int|null  $tenantId
     * @return array<int, array{bracket: string, count: int}>
     */
    public function getTenureBrackets(?int $tenantId): array
    {
        $now = Carbon::now();

        $profiles = HrEmployeeProfile::forTenant($tenantId)
            ->active()
            ->whereNotNull('start_date')
            ->get(['start_date']);

        $brackets = [
            '< 1 year' => 0,
            '1-2 years' => 0,
            '2-5 years' => 0,
            '5-10 years' => 0,
            '10+ years' => 0,
        ];

        foreach ($profiles as $profile) {
            $years = $profile->start_date->diffInYears($now);

            if ($years < 1) {
                $brackets['< 1 year']++;
            } elseif ($years < 2) {
                $brackets['1-2 years']++;
            } elseif ($years < 5) {
                $brackets['2-5 years']++;
            } elseif ($years < 10) {
                $brackets['5-10 years']++;
            } else {
                $brackets['10+ years']++;
            }
        }

        return collect($brackets)->map(fn ($count, $bracket) => [
            'bracket' => $bracket,
            'count' => $count,
        ])->values()->all();
    }

    /**
     * Calculate the overall compliance score as a percentage.
     *
     * @param  int|null  $tenantId
     * @return array{score: float, compliant: int, total: int}
     */
    public function getComplianceScore(?int $tenantId): array
    {
        $total = HrStaffComplianceStatus::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->count();
        $compliant = HrStaffComplianceStatus::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('status', 'compliant')
            ->count();

        $score = $total > 0 ? round(($compliant / $total) * 100, 1) : 100;

        return [
            'score' => $score,
            'compliant' => $compliant,
            'total' => $total,
        ];
    }

    /**
     * Get leave utilization breakdown by leave type.
     *
     * @param  int|null  $tenantId
     * @return array<int, array{type: string, approved: int, pending: int, declined: int}>
     */
    public function getLeaveUtilization(?int $tenantId): array
    {
        $results = HrLeaveRequest::forTenant($tenantId)
            ->whereYear('created_at', now()->year)
            ->select('leave_type', 'status', DB::raw('COUNT(*) as count'))
            ->groupBy('leave_type', 'status')
            ->get();

        $grouped = [];
        foreach ($results as $row) {
            $type = $row->leave_type;
            if (! isset($grouped[$type])) {
                $grouped[$type] = ['type' => $type, 'approved' => 0, 'pending' => 0, 'declined' => 0];
            }
            if (in_array($row->status, ['approved', 'pending', 'declined'])) {
                $grouped[$type][$row->status] = $row->count;
            }
        }

        return array_values($grouped);
    }

    /**
     * Get employee count breakdown by department.
     *
     * @param  int|null  $tenantId
     * @return array<int, array{department: string, count: int}>
     */
    public function getDepartmentBreakdown(?int $tenantId): array
    {
        return HrDepartment::query()
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->where('is_active', true)
            ->withCount(['employees' => fn ($q) => $q->where('is_active', true)])
            ->having('employees_count', '>', 0)
            ->orderByDesc('employees_count')
            ->get()
            ->map(fn ($dept) => [
                'department' => $dept->name,
                'count' => $dept->employees_count,
            ])
            ->all();
    }
}
