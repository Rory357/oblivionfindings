<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Domain\Hr\Models\HrWellbeingIndicator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class HrReportController extends Controller
{
    /**
     * Available report types and their descriptions.
     */
    private const REPORT_TYPES = [
        'headcount' => ['title' => 'Headcount Summary', 'description' => 'Staff headcount and demographics summary', 'category' => 'headcount'],
        'turnover' => ['title' => 'Turnover Analysis', 'description' => 'Employee turnover analysis by period', 'category' => 'turnover'],
        'leave_summary' => ['title' => 'Leave Summary', 'description' => 'Leave usage and balance summary', 'category' => 'leave'],
        'performance' => ['title' => 'Performance Reviews', 'description' => 'Performance review completion and ratings', 'category' => 'compliance'],
        'supervision' => ['title' => 'Supervision Report', 'description' => 'Supervision session frequency and compliance', 'category' => 'compliance'],
        'cases' => ['title' => 'HR Cases', 'description' => 'HR cases by type, severity, and status', 'category' => 'compliance'],
        'wellbeing' => ['title' => 'Wellbeing Indicators', 'description' => 'Staff wellbeing indicators and flags', 'category' => 'compliance'],
        'compliance' => ['title' => 'Compliance Overview', 'description' => 'Compliance requirement status overview', 'category' => 'compliance'],
    ];

    /**
     * Show the report dashboard with available report types.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.view'), 403);

        return Inertia::render('hr/reports/index', [
            'availableReports' => collect(self::REPORT_TYPES)->map(fn ($meta, $key) => [
                'key' => $key,
                'title' => $meta['title'],
                'description' => $meta['description'],
                'category' => $meta['category'],
            ])->values(),
            'recentReports' => [],
            'can' => [
                'export_data' => $user->canDo('hr.reports.export'),
            ],
        ]);
    }

    /**
     * Generate and return a report.
     */
    public function generate(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.view'), 403);

        $data = $request->validate([
            'report_type' => ['required', 'string', 'in:' . implode(',', array_keys(self::REPORT_TYPES))],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $tenantId = null;
        $dateFrom = $data['date_from'] ?? now()->subYear()->toDateString();
        $dateTo = $data['date_to'] ?? now()->toDateString();

        $reportData = match ($data['report_type']) {
            'headcount' => $this->generateHeadcount($tenantId),
            'turnover' => $this->generateTurnover($tenantId, $dateFrom, $dateTo),
            'leave_summary' => $this->generateLeaveSummary($tenantId, $dateFrom, $dateTo),
            'performance' => $this->generatePerformanceReport($tenantId, $dateFrom, $dateTo),
            'supervision' => $this->generateSupervisionReport($tenantId, $dateFrom, $dateTo),
            'cases' => $this->generateCasesReport($tenantId, $dateFrom, $dateTo),
            'wellbeing' => $this->generateWellbeingReport($tenantId),
            'compliance' => $this->generateComplianceReport($tenantId),
            default => [],
        };

        return Inertia::render('hr/reports/show', [
            'reportType' => $data['report_type'],
            'reportTitle' => self::REPORT_TYPES[$data['report_type']]['title'] ?? $data['report_type'],
            'reportData' => $reportData,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'can' => [
                'export' => $user->canDo('hr.reports.export'),
            ],
        ]);
    }

    /**
     * Export a report as CSV download.
     */
    public function export(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.export'), 403);

        $data = $request->validate([
            'report_type' => ['required', 'string', 'in:' . implode(',', array_keys(self::REPORT_TYPES))],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $tenantId = null;
        $dateFrom = $data['date_from'] ?? now()->subYear()->toDateString();
        $dateTo = $data['date_to'] ?? now()->toDateString();

        $reportData = match ($data['report_type']) {
            'headcount' => $this->generateHeadcount($tenantId),
            'turnover' => $this->generateTurnover($tenantId, $dateFrom, $dateTo),
            'leave_summary' => $this->generateLeaveSummary($tenantId, $dateFrom, $dateTo),
            'performance' => $this->generatePerformanceReport($tenantId, $dateFrom, $dateTo),
            'supervision' => $this->generateSupervisionReport($tenantId, $dateFrom, $dateTo),
            'cases' => $this->generateCasesReport($tenantId, $dateFrom, $dateTo),
            'wellbeing' => $this->generateWellbeingReport($tenantId),
            'compliance' => $this->generateComplianceReport($tenantId),
            default => [],
        };

        $csv = $this->buildCsv($reportData);

        $filename = sprintf('hr-report-%s-%s.csv', $data['report_type'], now()->format('Y-m-d'));
        $path = 'hr-reports/' . $filename;

        Storage::disk('private')->put($path, $csv);

        return Storage::disk('private')->download($path, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /* ------------------------------------------------------------------
     *  Report generators
     * ------------------------------------------------------------------ */

    private function generateHeadcount(?int $tenantId): array
    {
        $profiles = HrEmployeeProfile::active()->get();

        return [
            'total_active' => $profiles->count(),
            'by_employment_type' => $profiles->groupBy('employment_type')
                ->map(fn ($g) => $g->count()),
            'by_contract_type' => $profiles->groupBy('contract_type')
                ->map(fn ($g) => $g->count()),
        ];
    }

    private function generateTurnover(?int $tenantId, string $dateFrom, string $dateTo): array
    {
        $leavers = HrEmployeeProfile::whereNotNull('end_date')
            ->whereBetween('end_date', [$dateFrom, $dateTo])
            ->get();

        $starters = HrEmployeeProfile::whereBetween('start_date', [$dateFrom, $dateTo])
            ->get();

        $totalActive = HrEmployeeProfile::active()->count();

        return [
            'starters' => $starters->count(),
            'leavers' => $leavers->count(),
            'turnover_rate' => $totalActive > 0
                ? round(($leavers->count() / $totalActive) * 100, 1)
                : 0,
            'by_reason' => $leavers->groupBy('termination_reason')
                ->map(fn ($g) => $g->count()),
        ];
    }

    private function generateLeaveSummary(?int $tenantId, string $dateFrom, string $dateTo): array
    {
        $requests = HrLeaveRequest::whereBetween('starts_at', [$dateFrom, $dateTo])
            ->get();

        return [
            'total_requests' => $requests->count(),
            'by_status' => $requests->groupBy('status')
                ->map(fn ($g) => $g->count()),
            'by_type' => $requests->groupBy('leave_type')
                ->map(fn ($g) => [
                    'count' => $g->count(),
                    'total_hours' => $g->sum('hours_requested'),
                ]),
            'total_hours_requested' => $requests->sum('hours_requested'),
        ];
    }

    private function generatePerformanceReport(?int $tenantId, string $dateFrom, string $dateTo): array
    {
        $reviews = HrPerformanceReview::whereBetween('created_at', [$dateFrom, $dateTo])
            ->get();

        return [
            'total_reviews' => $reviews->count(),
            'by_status' => $reviews->groupBy('status')
                ->map(fn ($g) => $g->count()),
            'by_type' => $reviews->groupBy('review_type')
                ->map(fn ($g) => $g->count()),
            'average_rating' => $reviews->whereNotNull('overall_rating')->avg('overall_rating'),
            'signed_off_count' => $reviews->where('employee_signed_off', true)->count(),
        ];
    }

    private function generateSupervisionReport(?int $tenantId, string $dateFrom, string $dateTo): array
    {
        $notes = HrSupervisionNote::whereBetween('session_date', [$dateFrom, $dateTo])
            ->get();

        return [
            'total_sessions' => $notes->count(),
            'by_type' => $notes->groupBy('session_type')
                ->map(fn ($g) => $g->count()),
            'average_duration' => round($notes->avg('duration_minutes') ?? 0),
            'employees_supervised' => $notes->pluck('employee_user_id')->unique()->count(),
            'acknowledged_count' => $notes->where('employee_acknowledged', true)->count(),
        ];
    }

    private function generateCasesReport(?int $tenantId, string $dateFrom, string $dateTo): array
    {
        $cases = HrCase::whereBetween('opened_at', [$dateFrom, $dateTo])
            ->get();

        return [
            'total_cases' => $cases->count(),
            'by_status' => $cases->groupBy('status')
                ->map(fn ($g) => $g->count()),
            'by_type' => $cases->groupBy('case_type')
                ->map(fn ($g) => $g->count()),
            'by_severity' => $cases->groupBy('severity')
                ->map(fn ($g) => $g->count()),
            'average_days_to_close' => $cases->whereNotNull('closed_at')
                ->avg(fn ($c) => $c->opened_at?->diffInDays($c->closed_at)),
        ];
    }

    private function generateWellbeingReport(?int $tenantId): array
    {
        $indicators = HrWellbeingIndicator::orderByDesc('calculated_at')
            ->limit(500)
            ->get();

        return [
            'total_assessed' => $indicators->pluck('user_id')->unique()->count(),
            'by_flag_level' => $indicators->groupBy('flag_level')
                ->map(fn ($g) => $g->count()),
            'avg_overtime_hours' => round($indicators->avg('overtime_hours') ?? 0, 1),
            'avg_consecutive_days' => round($indicators->avg('consecutive_days_worked') ?? 0, 1),
        ];
    }

    private function generateComplianceReport(?int $tenantId): array
    {
        // Provides a summary; compliance details are in the ComplianceController
        $profiles = HrEmployeeProfile::active()->count();

        return [
            'total_active_staff' => $profiles,
            'report_generated_at' => now()->toIso8601String(),
        ];
    }

    /* ------------------------------------------------------------------
     *  CSV builder
     * ------------------------------------------------------------------ */

    private function buildCsv(array $reportData): string
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
}
