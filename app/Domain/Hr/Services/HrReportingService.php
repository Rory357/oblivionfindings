<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrReportExport;
use App\Domain\Hr\Models\HrReportSubscription;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Domain\Hr\Models\HrWellbeingIndicator;
use Carbon\Carbon;
use Illuminate\Support\Collection;
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
            'recruitment_funnel' => ['title' => 'Recruitment Funnel', 'description' => 'Candidate pipeline progression and conversion', 'category' => 'recruitment'],
            'payroll_overview' => ['title' => 'Payroll Overview', 'description' => 'Payroll run throughput and gross pay trends', 'category' => 'payroll'],
            'leave_sla' => ['title' => 'Leave Approval SLA', 'description' => 'Approval lead times and overdue leave queues', 'category' => 'leave'],
            'onboarding_completion' => ['title' => 'Onboarding & Offboarding Completion', 'description' => 'Workflow completion and overdue task posture', 'category' => 'onboarding'],
        ];
    }

    /**
     * @return array{report_type: string, report_title: string, date_from: string, date_to: string, data: array<string, mixed>}
     */
    public function generate(string $reportType, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $reportTypes = $this->reportTypes();
        if (! isset($reportTypes[$reportType])) {
            throw new \InvalidArgumentException("Unsupported report type '{$reportType}'.");
        }

        $resolvedFrom = $dateFrom ?: now()->subYear()->toDateString();
        $resolvedTo = $dateTo ?: now()->toDateString();

        $data = match ($reportType) {
            'headcount' => $this->generateHeadcount(),
            'turnover' => $this->generateTurnover($resolvedFrom, $resolvedTo),
            'leave_summary' => $this->generateLeaveSummary($resolvedFrom, $resolvedTo),
            'performance' => $this->generatePerformanceReport($resolvedFrom, $resolvedTo),
            'supervision' => $this->generateSupervisionReport($resolvedFrom, $resolvedTo),
            'cases' => $this->generateCasesReport($resolvedFrom, $resolvedTo),
            'wellbeing' => $this->generateWellbeingReport(),
            'compliance' => $this->generateComplianceReport(),
            'recruitment_funnel' => $this->generateRecruitmentFunnel($resolvedFrom, $resolvedTo),
            'payroll_overview' => $this->generatePayrollOverview($resolvedFrom, $resolvedTo),
            'leave_sla' => $this->generateLeaveSlaReport($resolvedFrom, $resolvedTo),
            'onboarding_completion' => $this->generateOnboardingCompletionReport($resolvedFrom, $resolvedTo),
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
            if (is_array($value) || $value instanceof Collection) {
                $collection = collect($value);
                foreach ($collection as $subKey => $subValue) {
                    $displayValue = is_array($subValue)
                        ? json_encode($subValue)
                        : (string) $subValue;
                    $lines[] = $this->csvRow([(string) $key.'.'.$subKey, $displayValue]);
                }
            } else {
                $lines[] = $this->csvRow([(string) $key, (string) ($value ?? '')]);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /** @param array<int, string> $values */
    private function csvRow(array $values): string
    {
        return collect($values)
            ->map(fn (string $value): string => '"'.str_replace('"', '""', $this->sanitizeCsvCell($value)).'"')
            ->implode(',');
    }

    private function sanitizeCsvCell(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $trimmed = ltrim($value, " \v\f");
        $firstMeaningful = $trimmed[0] ?? '';

        if (in_array($firstMeaningful, ["\t", "\r"], true)) {
            return "'".$value;
        }

        if (! is_numeric($trimmed) && in_array($firstMeaningful, ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function createExport(
        string $reportType,
        array $filters = [],
        ?int $generatedBy = null,
        ?HrReportSubscription $subscription = null
    ): HrReportExport {
        $report = $this->generate(
            reportType: $reportType,
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

    private function generateHeadcount(): array
    {
        $profiles = HrEmployeeProfile::query()
            ->where('is_active', true)
            ->get();

        return [
            'total_active' => $profiles->count(),
            'by_employment_type' => $profiles->groupBy('employment_type')->map(fn ($g) => $g->count()),
            'by_contract_type' => $profiles->groupBy('contract_type')->map(fn ($g) => $g->count()),
        ];
    }

    private function generateTurnover(string $dateFrom, string $dateTo): array
    {
        $leavers = HrEmployeeProfile::query()
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [$dateFrom, $dateTo])
            ->get();

        $starters = HrEmployeeProfile::query()
            ->whereBetween('start_date', [$dateFrom, $dateTo])
            ->get();

        $totalActive = HrEmployeeProfile::query()
            ->where('is_active', true)
            ->count();

        return [
            'starters' => $starters->count(),
            'leavers' => $leavers->count(),
            'turnover_rate' => $totalActive > 0 ? round(($leavers->count() / $totalActive) * 100, 1) : 0,
            'by_reason' => $leavers->groupBy('termination_reason')->map(fn ($g) => $g->count()),
        ];
    }

    private function generateLeaveSummary(string $dateFrom, string $dateTo): array
    {
        $requests = HrLeaveRequest::query()
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

    private function generatePerformanceReport(string $dateFrom, string $dateTo): array
    {
        $reviews = HrPerformanceReview::query()
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

    private function generateSupervisionReport(string $dateFrom, string $dateTo): array
    {
        $notes = HrSupervisionNote::query()
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

    private function generateCasesReport(string $dateFrom, string $dateTo): array
    {
        $cases = HrCase::query()
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

    private function generateWellbeingReport(): array
    {
        $indicators = HrWellbeingIndicator::query()
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

    private function generateComplianceReport(): array
    {
        $profiles = HrEmployeeProfile::query()
            ->where('is_active', true)
            ->count();

        return [
            'total_active_staff' => $profiles,
            'report_generated_at' => now()->toIso8601String(),
        ];
    }

    private function generateRecruitmentFunnel(string $dateFrom, string $dateTo): array
    {
        $candidates = HrCandidate::query()
            ->whereBetween('created_at', [$dateFrom, Carbon::parse($dateTo)->endOfDay()])
            ->get();

        $hiredCount = $candidates->where('status', 'hired')->count();
        $activeCount = $candidates->reject(fn (HrCandidate $candidate) => in_array($candidate->status, ['withdrawn', 'rejected', 'hired'], true))->count();

        return [
            'total_candidates' => $candidates->count(),
            'active_candidates' => $activeCount,
            'hired_candidates' => $hiredCount,
            'withdrawn_candidates' => $candidates->where('status', 'withdrawn')->count(),
            'rejected_candidates' => $candidates->where('status', 'rejected')->count(),
            'conversion_rate' => $candidates->count() > 0 ? round(($hiredCount / $candidates->count()) * 100, 1) : 0.0,
            'stage_breakdown' => $candidates->groupBy('status')->map(fn ($group) => $group->count()),
            'source_breakdown' => $candidates->groupBy(fn (HrCandidate $candidate) => $candidate->source ?: 'unknown')->map(fn ($group) => $group->count()),
        ];
    }

    private function generatePayrollOverview(string $dateFrom, string $dateTo): array
    {
        $runs = HrPayrollRun::query()
            ->whereDate('period_start', '>=', $dateFrom)
            ->whereDate('period_end', '<=', $dateTo)
            ->get();

        return [
            'total_runs' => $runs->count(),
            'by_status' => $runs->groupBy('status')->map(fn ($group) => $group->count()),
            'total_gross' => round((float) $runs->sum('total_gross'), 2),
            'total_hours' => round((float) $runs->sum('total_hours'), 2),
            'total_staff_paid' => (int) $runs->sum('total_staff'),
            'average_gross_per_run' => $runs->count() > 0 ? round((float) $runs->avg('total_gross'), 2) : 0.0,
            'average_hours_per_run' => $runs->count() > 0 ? round((float) $runs->avg('total_hours'), 2) : 0.0,
            'locked_runs' => $runs->where('status', 'locked')->count(),
            'exported_runs' => $runs->whereNotNull('exported_at')->count(),
            'latest_exported_at' => optional($runs->whereNotNull('exported_at')->sortByDesc('exported_at')->first()?->exported_at)->toDateTimeString(),
        ];
    }

    private function generateLeaveSlaReport(string $dateFrom, string $dateTo): array
    {
        $requests = HrLeaveRequest::query()
            ->whereBetween('submitted_at', [$dateFrom, Carbon::parse($dateTo)->endOfDay()])
            ->get();

        $decided = $requests->filter(
            fn (HrLeaveRequest $request) => in_array((string) $request->status, ['approved', 'declined', 'cancelled'], true)
                && $request->reviewed_at
                && $request->submitted_at
        );

        $averageDecisionHours = $decided->count() > 0
            ? round((float) $decided->avg(fn (HrLeaveRequest $request) => $request->submitted_at->diffInMinutes($request->reviewed_at) / 60), 2)
            : 0.0;

        return [
            'total_requests' => $requests->count(),
            'pending_requests' => $requests->where('status', 'pending')->count(),
            'pending_overdue' => $requests
                ->where('status', 'pending')
                ->filter(fn (HrLeaveRequest $request) => $request->approval_due_at && $request->approval_due_at->lt(now()))
                ->count(),
            'pending_due_within_24h' => $requests
                ->where('status', 'pending')
                ->filter(fn (HrLeaveRequest $request) => $request->approval_due_at && $request->approval_due_at->between(now(), now()->addDay()))
                ->count(),
            'escalated_requests' => $requests->whereNotNull('escalated_at')->count(),
            'average_decision_hours' => $averageDecisionHours,
            'status_breakdown' => $requests->groupBy('status')->map(fn ($group) => $group->count()),
        ];
    }

    private function generateOnboardingCompletionReport(string $dateFrom, string $dateTo): array
    {
        $onboarding = HrOnboardingChecklist::query()
            ->whereBetween('started_at', [$dateFrom, Carbon::parse($dateTo)->endOfDay()])
            ->get();

        $offboarding = HrOffboardingChecklist::query()
            ->whereBetween('started_at', [$dateFrom, Carbon::parse($dateTo)->endOfDay()])
            ->get();

        $onboardingCompleted = $onboarding->where('status', 'completed');
        $offboardingCompleted = $offboarding->where('status', 'completed');

        $onboardingAverageDays = $onboardingCompleted->count() > 0
            ? round((float) $onboardingCompleted->avg(fn (HrOnboardingChecklist $checklist) => $checklist->started_at?->diffInDays($checklist->completed_at)), 1)
            : 0.0;

        $offboardingAverageDays = $offboardingCompleted->count() > 0
            ? round((float) $offboardingCompleted->avg(fn (HrOffboardingChecklist $checklist) => $checklist->started_at?->diffInDays($checklist->completed_at)), 1)
            : 0.0;

        return [
            'onboarding_total' => $onboarding->count(),
            'onboarding_completed' => $onboardingCompleted->count(),
            'onboarding_overdue' => $onboarding
                ->filter(fn (HrOnboardingChecklist $checklist) => $checklist->status !== 'completed'
                    && $checklist->due_date
                    && Carbon::parse($checklist->due_date)->lt(now()->startOfDay()))
                ->count(),
            'onboarding_status_breakdown' => $onboarding->groupBy('status')->map(fn ($group) => $group->count()),
            'onboarding_average_completion_days' => $onboardingAverageDays,
            'offboarding_total' => $offboarding->count(),
            'offboarding_completed' => $offboardingCompleted->count(),
            'offboarding_overdue' => $offboarding
                ->filter(fn (HrOffboardingChecklist $checklist) => $checklist->status !== 'completed'
                    && $checklist->due_date
                    && Carbon::parse($checklist->due_date)->lt(now()->startOfDay()))
                ->count(),
            'offboarding_status_breakdown' => $offboarding->groupBy('status')->map(fn ($group) => $group->count()),
            'offboarding_average_completion_days' => $offboardingAverageDays,
        ];
    }
}
