<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LeaveReportService
{
    /**
     * Monthly sick-leave counts and top absentees for a given year.
     */
    public function getAbsenteeismReport(int $tenantId, int $year): array
    {
        $monthlyCounts = HrLeaveRequest::query()
            ->forTenant($tenantId)
            ->where('leave_type', 'sick')
            ->where('status', 'approved')
            ->whereYear('starts_at', $year)
            ->selectRaw('MONTH(starts_at) as month, COUNT(*) as count, SUM(hours_requested) as total_hours')
            ->groupByRaw('MONTH(starts_at)')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $monthly = [];
        for ($m = 1; $m <= 12; $m++) {
            $row = $monthlyCounts->get($m);
            $monthly[] = [
                'month' => $m,
                'label' => date('M', mktime(0, 0, 0, $m, 1)),
                'count' => $row ? (int) $row->count : 0,
                'total_hours' => $row ? round((float) $row->total_hours, 1) : 0,
            ];
        }

        $topAbsentees = HrLeaveRequest::query()
            ->where('hr_leave_requests.tenant_id', $tenantId)
            ->where('leave_type', 'sick')
            ->where('status', 'approved')
            ->whereYear('starts_at', $year)
            ->join('users', 'users.id', '=', 'hr_leave_requests.user_id')
            ->selectRaw('hr_leave_requests.user_id, users.name, COUNT(*) as occurrences, SUM(hours_requested) as total_hours')
            ->groupBy('hr_leave_requests.user_id', 'users.name')
            ->orderByDesc('occurrences')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'user_id' => $row->user_id,
                'name' => $row->name,
                'occurrences' => (int) $row->occurrences,
                'total_hours' => round((float) $row->total_hours, 1),
            ])
            ->toArray();

        return [
            'monthly' => $monthly,
            'top_absentees' => $topAbsentees,
            'year' => $year,
        ];
    }

    /**
     * Bradford Factor per employee: spells (S), days (D), factor (S² x D), risk level.
     */
    public function getBradfordFactor(int $tenantId, int $year): array
    {
        // Group contiguous sick-leave requests into "spells" per employee
        $requests = HrLeaveRequest::query()
            ->where('hr_leave_requests.tenant_id', $tenantId)
            ->where('leave_type', 'sick')
            ->where('status', 'approved')
            ->whereYear('starts_at', $year)
            ->join('users', 'users.id', '=', 'hr_leave_requests.user_id')
            ->select('hr_leave_requests.user_id', 'users.name', 'hr_leave_requests.starts_at', 'hr_leave_requests.ends_at', 'hr_leave_requests.hours_requested')
            ->orderBy('hr_leave_requests.user_id')
            ->orderBy('hr_leave_requests.starts_at')
            ->get()
            ->groupBy('user_id');

        $results = [];

        foreach ($requests as $userId => $employeeRequests) {
            $spells = 0;
            $totalDays = 0;
            $lastEnd = null;

            foreach ($employeeRequests as $req) {
                $startDate = $req->starts_at;
                $endDate = $req->ends_at;
                $days = max(1, $startDate->diffInWeekdays($endDate) + 1);
                $totalDays += $days;

                // A new spell if there's a gap of more than 1 calendar day since last absence
                if ($lastEnd === null || $startDate->diffInDays($lastEnd) > 1) {
                    $spells++;
                }
                $lastEnd = $endDate;
            }

            $factor = ($spells * $spells) * $totalDays;

            $results[] = [
                'user_id' => $userId,
                'name' => $employeeRequests->first()->name,
                'spells' => $spells,
                'days' => $totalDays,
                'factor' => $factor,
                'risk_level' => $this->bradfordRiskLevel($factor),
            ];
        }

        usort($results, fn ($a, $b) => $b['factor'] <=> $a['factor']);

        return [
            'employees' => $results,
            'year' => $year,
        ];
    }

    /**
     * Leave utilisation per employee: entitlement, taken, remaining, % used by type.
     */
    public function getLeaveUtilizationReport(int $tenantId, int $year): array
    {
        $balances = HrLeaveBalance::query()
            ->where('hr_leave_balances.tenant_id', $tenantId)
            ->where('year', $year)
            ->join('users', 'users.id', '=', 'hr_leave_balances.user_id')
            ->select(
                'hr_leave_balances.user_id',
                'users.name',
                'hr_leave_balances.leave_type',
                'hr_leave_balances.balance_hours',
                'hr_leave_balances.used_hours',
                'hr_leave_balances.pending_hours',
            )
            ->orderBy('users.name')
            ->get()
            ->groupBy('user_id');

        $results = [];

        foreach ($balances as $userId => $userBalances) {
            $types = [];
            $totalEntitlement = 0;
            $totalUsed = 0;

            foreach ($userBalances as $bal) {
                $entitlement = (float) $bal->balance_hours;
                $used = (float) $bal->used_hours;
                $remaining = max(0, $entitlement - $used);
                $pctUsed = $entitlement > 0 ? round(($used / $entitlement) * 100, 1) : 0;

                $types[] = [
                    'leave_type' => $bal->leave_type,
                    'entitlement' => $entitlement,
                    'taken' => $used,
                    'pending' => (float) $bal->pending_hours,
                    'remaining' => $remaining,
                    'pct_used' => $pctUsed,
                ];

                $totalEntitlement += $entitlement;
                $totalUsed += $used;
            }

            $results[] = [
                'user_id' => $userId,
                'name' => $userBalances->first()->name,
                'types' => $types,
                'total_entitlement' => $totalEntitlement,
                'total_used' => $totalUsed,
                'total_remaining' => max(0, $totalEntitlement - $totalUsed),
                'overall_pct' => $totalEntitlement > 0 ? round(($totalUsed / $totalEntitlement) * 100, 1) : 0,
            ];
        }

        return [
            'employees' => $results,
            'year' => $year,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function bradfordRiskLevel(int $factor): string
    {
        return match (true) {
            $factor >= 900 => 'critical',
            $factor >= 500 => 'high',
            $factor >= 200 => 'medium',
            default => 'low',
        };
    }
}
