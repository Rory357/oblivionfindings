<?php

namespace App\Http\Controllers;

use App\Models\DataBreachLog;
use App\Models\DataRetentionPolicy;
use App\Models\DataSubjectRequest;
use App\Models\LegalHold;
use App\Models\PrivacyImpactAssessment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivacyReportController extends Controller
{
    /**
     * Display compliance report.
     */
    public function compliance(Request $request): Response
    {
        abort_unless($request->user()?->canDo('privacy.viewRequests'), 403);

        $period = $request->get('period', 'year');
        $startDate = $this->startDate($period);

        return Inertia::render('privacy/reports/compliance', [
            'period' => $period,
            'dsrStats' => [
                'total' => DataSubjectRequest::where('created_at', '>=', $startDate)->count(),
                'completed' => DataSubjectRequest::where('status', 'completed')
                    ->where('completed_at', '>=', $startDate)
                    ->count(),
                'average_response_days' => $this->calculateAverageResponseDays($startDate),
                'by_type' => DataSubjectRequest::where('created_at', '>=', $startDate)
                    ->selectRaw('request_type, count(*) as count')
                    ->groupBy('request_type')
                    ->pluck('count', 'request_type'),
            ],
            'breachStats' => [
                'total' => DataBreachLog::where('created_at', '>=', $startDate)->count(),
                'resolved' => DataBreachLog::where('status', 'resolved')
                    ->where('resolved_at', '>=', $startDate)
                    ->count(),
                // OPC (Office of the Privacy Commissioner) notifications under the
                // Privacy Act 2020 — formerly mis-named "ico_notifications".
                'opc_notifications' => DataBreachLog::whereNotNull('authority_notified_at')
                    ->where('authority_notified_at', '>=', $startDate)
                    ->count(),
            ],
            'dpiaStats' => [
                'total' => PrivacyImpactAssessment::where('created_at', '>=', $startDate)->count(),
                'approved' => PrivacyImpactAssessment::where('outcome', 'approved')
                    ->where('approved_at', '>=', $startDate)
                    ->count(),
                'high_risk' => PrivacyImpactAssessment::whereIn('overall_risk_level', ['high', 'very_high'])
                    ->where('created_at', '>=', $startDate)
                    ->count(),
            ],
            'retentionStats' => [
                'total_policies' => DataRetentionPolicy::count(),
                'active_policies' => DataRetentionPolicy::where('active', true)->count(),
            ],
            'legalHoldStats' => [
                'total' => LegalHold::where('created_at', '>=', $startDate)->count(),
                'active' => LegalHold::active()->count(),
            ],
        ]);
    }

    /**
     * Export a compliance report as CSV. `type` selects the report:
     * opc_register | sla | retention | full (default).
     */
    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->canDo('privacy.viewRequests'), 403);

        $type = in_array($request->get('type'), ['opc_register', 'sla', 'retention', 'full'], true)
            ? $request->get('type') : 'full';
        $startDate = $this->startDate($request->get('period', 'year'));
        $filename = 'privacy-'.str_replace('_', '-', $type).'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($type, $startDate) {
            $out = fopen('php://output', 'w');
            match ($type) {
                'opc_register' => $this->writeBreachRegister($out, $startDate),
                'sla' => $this->writeSlaReport($out, $startDate),
                'retention' => $this->writeRetentionReport($out),
                default => $this->writeFullReport($out, $startDate),
            };
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function startDate(string $period): Carbon
    {
        return match ($period) {
            'month' => now()->startOfMonth(),
            'quarter' => now()->startOfQuarter(),
            default => now()->startOfYear(),
        };
    }

    /**
     * @param  resource  $out
     */
    private function writeBreachRegister($out, Carbon $startDate): void
    {
        fputcsv($out, ['Reference', 'Discovered', 'Nature', 'Affected', 'Severity', 'Status', 'OPC required', 'OPC notified', 'Subjects notified', 'Resolved']);

        DataBreachLog::where('discovered_at', '>=', $startDate)->orderByDesc('discovered_at')
            ->each(function (DataBreachLog $b) use ($out) {
                fputcsv($out, [
                    $b->breach_reference,
                    optional($b->discovered_at)->toDateString(),
                    $b->nature_of_breach,
                    $b->approximate_individuals_affected,
                    $b->severity,
                    $b->status,
                    $b->requires_authority_notification ? 'Yes' : 'No',
                    optional($b->authority_notified_at)->toDateString() ?: '—',
                    optional($b->subjects_notified_at)->toDateString() ?: '—',
                    optional($b->resolved_at)->toDateString() ?: '—',
                ]);
            });
    }

    /**
     * @param  resource  $out
     */
    private function writeSlaReport($out, Carbon $startDate): void
    {
        fputcsv($out, ['Reference', 'Type', 'Received', 'Due', 'Completed', 'Status', 'Working days to complete', 'Overdue']);

        DataSubjectRequest::where('created_at', '>=', $startDate)->orderByDesc('received_at')
            ->each(function (DataSubjectRequest $r) use ($out) {
                $days = $r->received_at && $r->completed_at
                    ? (int) $r->received_at->diffInDays($r->completed_at)
                    : '';
                fputcsv($out, [
                    $r->reference_number,
                    $r->request_type,
                    optional($r->received_at)->toDateString(),
                    optional($r->extended_due_date ?: $r->due_date)->toDateString(),
                    optional($r->completed_at)->toDateString() ?: '—',
                    $r->status,
                    $days,
                    $r->isOverdue() ? 'Yes' : 'No',
                ]);
            });
    }

    /**
     * @param  resource  $out
     */
    private function writeRetentionReport($out): void
    {
        fputcsv($out, ['Policy', 'Applies to', 'Retention (years)', 'Archive after', 'Hard delete after', 'Active', 'Next review', 'Last applied', 'Legal basis']);

        DataRetentionPolicy::orderBy('model_type')->each(function (DataRetentionPolicy $p) use ($out) {
            fputcsv($out, [
                $p->policy_name,
                class_basename($p->model_type),
                $p->retention_period_years,
                $p->archive_after_years,
                $p->hard_delete_after_years,
                $p->active ? 'Yes' : 'No',
                optional($p->next_review_at)->toDateString() ?: '—',
                optional($p->last_applied_at)->toDateString() ?: '—',
                $p->legal_basis,
            ]);
        });
    }

    /**
     * @param  resource  $out
     */
    private function writeFullReport($out, Carbon $startDate): void
    {
        fputcsv($out, ['Privacy compliance report']);
        fputcsv($out, ['Generated', now()->toDayDateTimeString()]);
        fputcsv($out, ['Since', $startDate->toDateString()]);
        fputcsv($out, []);
        fputcsv($out, ['Section', 'Metric', 'Value']);

        $rows = [
            ['Access & correction requests', 'Total', DataSubjectRequest::where('created_at', '>=', $startDate)->count()],
            ['Access & correction requests', 'Completed', DataSubjectRequest::where('status', 'completed')->where('completed_at', '>=', $startDate)->count()],
            ['Access & correction requests', 'Overdue (open, past 20 working days)', DataSubjectRequest::overdue()->count()],
            ['Access & correction requests', 'Average response (days)', $this->calculateAverageResponseDays($startDate)],
            ['Notifiable breaches', 'Total', DataBreachLog::where('discovered_at', '>=', $startDate)->count()],
            ['Notifiable breaches', 'OPC-notified', DataBreachLog::whereNotNull('authority_notified_at')->where('authority_notified_at', '>=', $startDate)->count()],
            ['Notifiable breaches', 'Awaiting OPC notification', DataBreachLog::where('requires_authority_notification', true)->whereNull('authority_notified_at')->count()],
            ['Legal holds', 'Active', LegalHold::active()->count()],
            ['Retention policies', 'Active', DataRetentionPolicy::where('active', true)->count()],
            ['DPIAs', 'High / very-high risk (open)', PrivacyImpactAssessment::whereIn('overall_risk_level', ['high', 'very_high'])->whereNull('outcome')->count()],
        ];

        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
    }

    /**
     * Calculate average response days for DSRs.
     */
    private function calculateAverageResponseDays($startDate): float
    {
        $completedRequests = DataSubjectRequest::where('status', 'completed')
            ->where('completed_at', '>=', $startDate)
            ->whereNotNull('received_at')
            ->whereNotNull('completed_at')
            ->get();

        if ($completedRequests->isEmpty()) {
            return 0;
        }

        $totalDays = $completedRequests->sum(function ($request) {
            return $request->received_at->diffInDays($request->completed_at);
        });

        return round($totalDays / $completedRequests->count(), 1);
    }
}
