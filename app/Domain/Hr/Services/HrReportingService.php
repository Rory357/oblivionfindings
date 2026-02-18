<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrReportExport;
use App\Domain\Hr\Models\HrReportSubscription;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Domain\Hr\Models\HrWellbeingIndicator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class HrReportingService
{
    /**
     * @return array<string, array{title: string, description: string, category: string}>
     */
    public function reportTypes(): array
    {
        return [
            'headcount' => ['title' => 'Headcount Summary', 'description' => 'Staff headcount and demographics summary', 'category' => 'headcount'],
            'turnover' => ['title' => 'Turnover Analysis', 'description' => 'Employee turnover analysis by period', 'category' => 'turnover'],
            'leave_summary' => ['title' => 'Leave Summary', 'description' => 'Leave usage and balance summary', 'category' => 'leave'],
            'performance' => ['title' => 'Performance Reviews', 'description' => 'Performance review completion and ratings', 'category' => 'compliance'],
            'supervision' => ['title' => 'Supervision Report', 'description' => 'Supervision session frequency and compliance', 'category' => 'compliance'],
            'cases' => ['title' => 'HR Cases', 'description' => 'HR cases by type, severity, and status', 'category' => 'compliance'],
            'wellbeing' => ['title' => 'Wellbeing Indicators', 'description' => 'Staff wellbeing indicators and flags', 'category' => 'compliance'],
            'compliance' => ['title' => 'Compliance Overview', 'description' => 'Compliance requirement status overview', 'category' => 'compliance'],
        ];
    }

    /**
     * @return array{report_type: string, report_title: string, date_from: string, date_to: string, data: array<string, mixed>}
     */
    public function generate(string $reportType, ?int $tenantId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $reportTypes = $this->reportTypes();
        if (! isset($reportTypes[$reportType])) {
            throw new \InvalidArgumentException("Unsupported report type '{$reportType}'.");
        }

        $resolvedFrom = $dateFrom ?: now()->subYear()->toDateString();
        $resolvedTo = $dateTo ?: now()->toDateString();

        $data = match ($reportType) {
            'headcount' => $this->generateHeadcount($tenantId),
            'turnover' => $this->generateTurnover($tenantId, $resolvedFrom, $resolvedTo),
            'leave_summary' => $this->generateLeaveSummary($tenantId, $resolvedFrom, $resolvedTo),
            'performance' => $this->generatePerformanceReport($tenantId, $resolvedFrom, $resolvedTo),
            'supervision' => $this->generateSupervisionReport($tenantId, $resolvedFrom, $resolvedTo),
            'cases' => $this->generateCasesReport($tenantId, $resolvedFrom, $resolvedTo),
            'wellbeing' => $this->generateWellbeingReport($tenantId),
            'compliance' => $this->generateComplianceReport($tenantId),
            default => [],
        };

        return [
            'report_type' => $reportType,
            'report_title' => $reportTypes[$reportType]['title'],
            'date_from' => $resolvedFrom,
            'date_to' => $resolvedTo,
            'data' => $data,
        ];
    }

    public function buildCsv(array $reportData): string
    {
        $lines = [];
        $lines[] = 'Metric,Value';

        foreach ($reportData as $key => $value) {
            if (is_array($value) || $value instanceof \Illuminate\Support\Collection) {
                $collection = collect($value);
                foreach ($collection as $subKey => $subValue) {
                    $displayValue = is_array($subValue)
                        ? json_encode($subValue)
                        : (string) $subValue;
                    $lines[] = '"' . str_replace('"', '""', $key . '.' . $subKey) . '","' . str_replace('"', '""', $displayValue) . '"';
                }
            } else {
                $lines[] = '"' . str_replace('"', '""', (string) $key) . '","' . str_replace('"', '""', (string) ($value ?? '')) . '"';
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function createExport(
        string $reportType,
        ?int $tenantId,
        array $filters = [],
        ?int $generatedBy = null,
        ?HrReportSubscription $subscription = null
    ): HrReportExport {
        $report = $this->generate(
            reportType: $reportType,
            tenantId: $tenantId,
            dateFrom: isset($filters['date_from']) ? (string) $filters['date_from'] : null,
            dateTo: isset($filters['date_to']) ? (string) $filters['date_to'] : null,
        );

        $csv = $this->buildCsv($report['data']);
        $filename = sprintf(
            'hr-reports/%s/%s_%s.csv',
            $reportType,
            now()->format('Y/m'),
            now()->format('Ymd_His_u')
        );
        Storage::disk('private')->put($filename, $csv);

        $rowCount = is_array($report['data']) ? count($report['data']) : 0;

        return HrReportExport::query()->create([
            'tenant_id' => $tenantId,
            'subscription_id' => $subscription?->id,
            'report_type' => $reportType,
            'period_start' => $report['date_from'],
            'period_end' => $report['date_to'],
            'filters' => $filters,
            'row_count' => $rowCount,
            'storage_path' => $filename,
            'export_format' => 'csv',
            'generated_at' => now(),
            'generated_by' => $generatedBy,
        ]);
    }

    public function calculateNextRunAt(HrReportSubscription $subscription, ?Carbon $from = null): Carbon
    {
        $base = ($from ?: now())->copy();
        $timezone = $subscription->timezone ?: 'Pacific/Auckland';
        $target = $base->copy()->timezone($timezone)->setSeconds(0);
        $runAt = Carbon::parse((string) $subscription->run_at, $timezone);

        $target->setTime($runAt->hour, $runAt->minute, 0);

        if ($subscription->cadence === 'daily') {
            if ($target->lessThanOrEqualTo($base->copy()->timezone($timezone))) {
                $target->addDay();
            }
            return $target->timezone(config('app.timezone'));
        }

        if ($subscription->cadence === 'weekly') {
            $weekday = max(0, min(6, (int) ($subscription->day_of_week ?? 1)));
            while ((int) $target->dayOfWeek !== $weekday || $target->lessThanOrEqualTo($base->copy()->timezone($timezone))) {
                $target->addDay();
            }
            return $target->timezone(config('app.timezone'));
        }

        $dayOfMonth = max(1, min(28, (int) ($subscription->day_of_month ?? 1)));
        $target->day = 1;
        $target->setTime($runAt->hour, $runAt->minute, 0);
        $target->day = $dayOfMonth;

        if ($target->lessThanOrEqualTo($base->copy()->timezone($timezone))) {
            $target->addMonthNoOverflow()->day($dayOfMonth);
        }

        return $target->timezone(config('app.timezone'));
    }

    private function generateHeadcount(?int $tenantId): array
    {
        $profiles = HrEmployeeProfile::query()
            ->where('is_active', true)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->get();

        return [
            'total_active' => $profiles->count(),
            'by_employment_type' => $profiles->groupBy('employment_type')->map(fn ($g) => $g->count()),
            'by_contract_type' => $profiles->groupBy('contract_type')->map(fn ($g) => $g->count()),
        ];
    }

    private function generateTurnover(?int $tenantId, string $dateFrom, string $dateTo): array
    {
        $leavers = HrEmployeeProfile::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [$dateFrom, $dateTo])
            ->get();

        $starters = HrEmployeeProfile::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->whereBetween('start_date', [$dateFrom, $dateTo])
            ->get();

        $totalActive = HrEmployeeProfile::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->where('is_active', true)
            ->count();

        return [
            'starters' => $starters->count(),
            'leavers' => $leavers->count(),
            'turnover_rate' => $totalActive > 0 ? round(($leavers->count() / $totalActive) * 100, 1) : 0,
            'by_reason' => $leavers->groupBy('termination_reason')->map(fn ($g) => $g->count()),
        ];
    }

    private function generateLeaveSummary(?int $tenantId, string $dateFrom, string $dateTo): array
    {
        $requests = HrLeaveRequest::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->whereBetween('starts_at', [$dateFrom, $dateTo])
            ->get();

        return [
            'total_requests' => $requests->count(),
            'by_status' => $requests->groupBy('status')->map(fn ($g) => $g->count()),
            'by_type' => $requests->groupBy('leave_type')->map(fn ($g) => [
                'count' => $g->count(),
                'total_hours' => (float) $g->sum('hours_requested'),
            ]),
            'total_hours_requested' => (float) $requests->sum('hours_requested'),
        ];
    }

    private function generatePerformanceReport(?int $tenantId, string $dateFrom, string $dateTo): array
    {
        $reviews = HrPerformanceReview::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->get();

        return [
            'total_reviews' => $reviews->count(),
            'by_status' => $reviews->groupBy('status')->map(fn ($g) => $g->count()),
            'by_type' => $reviews->groupBy('review_type')->map(fn ($g) => $g->count()),
            'average_rating' => round((float) ($reviews->whereNotNull('overall_rating')->avg('overall_rating') ?? 0), 2),
            'signed_off_count' => $reviews->where('employee_signed_off', true)->count(),
        ];
    }

    private function generateSupervisionReport(?int $tenantId, string $dateFrom, string $dateTo): array
    {
        $notes = HrSupervisionNote::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->whereBetween('session_date', [$dateFrom, $dateTo])
            ->get();

        return [
            'total_sessions' => $notes->count(),
            'by_type' => $notes->groupBy('session_type')->map(fn ($g) => $g->count()),
            'average_duration' => round((float) ($notes->avg('duration_minutes') ?? 0)),
            'employees_supervised' => $notes->pluck('employee_user_id')->unique()->count(),
            'acknowledged_count' => $notes->where('employee_acknowledged', true)->count(),
        ];
    }

    private function generateCasesReport(?int $tenantId, string $dateFrom, string $dateTo): array
    {
        $cases = HrCase::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->whereBetween('opened_at', [$dateFrom, $dateTo])
            ->get();

        return [
            'total_cases' => $cases->count(),
            'by_status' => $cases->groupBy('status')->map(fn ($g) => $g->count()),
            'by_type' => $cases->groupBy('case_type')->map(fn ($g) => $g->count()),
            'by_severity' => $cases->groupBy('severity')->map(fn ($g) => $g->count()),
            'average_days_to_close' => round((float) ($cases->whereNotNull('closed_at')->avg(fn ($c) => $c->opened_at?->diffInDays($c->closed_at)) ?? 0), 1),
        ];
    }

    private function generateWellbeingReport(?int $tenantId): array
    {
        $indicators = HrWellbeingIndicator::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->orderByDesc('calculated_at')
            ->limit(500)
            ->get();

        return [
            'total_assessed' => $indicators->pluck('user_id')->unique()->count(),
            'by_flag_level' => $indicators->groupBy('flag_level')->map(fn ($g) => $g->count()),
            'avg_overtime_hours' => round((float) ($indicators->avg('overtime_hours') ?? 0), 1),
            'avg_consecutive_days' => round((float) ($indicators->avg('consecutive_days_worked') ?? 0), 1),
        ];
    }

    private function generateComplianceReport(?int $tenantId): array
    {
        $profiles = HrEmployeeProfile::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->where('is_active', true)
            ->count();

        return [
            'total_active_staff' => $profiles,
            'report_generated_at' => now()->toIso8601String(),
        ];
    }
}

