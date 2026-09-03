<?php

namespace App\Http\Controllers;

use App\Models\DataBreachLog;
use App\Models\DataRetentionPolicy;
use App\Models\DataSubjectRequest;
use App\Models\LegalHold;
use App\Models\PrivacyImpactAssessment;
use App\Models\User;
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
        $user = $request->user();
        abort_unless($user && (
            $user->canDo('privacy.viewRequests') ||
            $user->canDo('privacy.processRequests') ||
            $user->canDo('privacy.reportBreaches') ||
            $user->canDo('privacy.conductDPIA') ||
            $user->canDo('privacy.manageRetention') ||
            $user->canDo('privacy.manageLegalHolds') ||
            $user->canDo('privacy.export')
        ), 403);

        $period = $request->get('period', 'year');
        $startDate = $this->startDate($period);

        $canViewDsr = (bool) ($user->canDo('privacy.viewRequests') || $user->canDo('privacy.processRequests') || $user->canDo('privacy.export'));
        $canViewBreaches = (bool) ($user->canDo('privacy.reportBreaches') || $user->canDo('privacy.breach.export'));
        $canViewDpia = (bool) $user->canDo('privacy.conductDPIA');
        $canViewRetention = (bool) ($user->canDo('privacy.manageRetention') || $user->canDo('privacy.retention.export'));
        $canViewLegalHolds = (bool) ($user->canDo('privacy.manageLegalHolds') || $user->canDo('privacy.legal_holds.export') || $user->canDo('privacy.legalHolds.export'));

        return Inertia::render('privacy/reports/compliance', [
            'period' => $period,
            'dsrStats' => $canViewDsr ? [
                'total' => DataSubjectRequest::where('created_at', '>=', $startDate)->count(),
                'completed' => DataSubjectRequest::where('status', 'completed')
                    ->where('completed_at', '>=', $startDate)
                    ->count(),
                'average_response_days' => $this->calculateAverageResponseDays($startDate),
                'by_type' => DataSubjectRequest::where('created_at', '>=', $startDate)
                    ->selectRaw('request_type, count(*) as count')
                    ->groupBy('request_type')
                    ->pluck('count', 'request_type'),
            ] : [
                'total' => 0,
                'completed' => 0,
                'average_response_days' => 0,
                'by_type' => [],
            ],
            'breachStats' => $canViewBreaches ? [
                'total' => DataBreachLog::where('created_at', '>=', $startDate)->count(),
                'resolved' => DataBreachLog::where('status', 'resolved')
                    ->where('resolved_at', '>=', $startDate)
                    ->count(),
                // OPC (Office of the Privacy Commissioner) notifications under the
                // Privacy Act 2020 — formerly mis-named "ico_notifications".
                'opc_notifications' => DataBreachLog::whereNotNull('authority_notified_at')
                    ->where('authority_notified_at', '>=', $startDate)
                    ->count(),
            ] : [
                'total' => 0,
                'resolved' => 0,
                'opc_notifications' => 0,
            ],
            'dpiaStats' => $canViewDpia ? [
                'total' => PrivacyImpactAssessment::where('created_at', '>=', $startDate)->count(),
                'approved' => PrivacyImpactAssessment::where('outcome', 'approved')
                    ->where('approved_at', '>=', $startDate)
                    ->count(),
                'high_risk' => PrivacyImpactAssessment::whereIn('overall_risk_level', ['high', 'very_high'])
                    ->where('created_at', '>=', $startDate)
                    ->count(),
            ] : [
                'total' => 0,
                'approved' => 0,
                'high_risk' => 0,
            ],
            'retentionStats' => $canViewRetention ? [
                'total_policies' => DataRetentionPolicy::count(),
                'active_policies' => DataRetentionPolicy::where('active', true)->count(),
            ] : [
                'total_policies' => 0,
                'active_policies' => 0,
            ],
            'legalHoldStats' => $canViewLegalHolds ? [
                'total' => LegalHold::where('created_at', '>=', $startDate)->count(),
                'active' => LegalHold::active()->count(),
            ] : [
                'total' => 0,
                'active' => 0,
            ],
        ]);
    }

    /**
     * Export a compliance report as CSV. `type` selects the report:
     * opc_register | sla | retention | legal_holds | full (default).
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $rawType = (string) $request->get('type', 'full');
        $type = match ($rawType) {
            'opc_register', 'breaches', 'breach' => 'opc_register',
            'sla', 'dsr', 'requests' => 'sla',
            'retention' => 'retention',
            'legal_holds', 'legal-holds', 'holds' => 'legal_holds',
            default => 'full',
        };

        // Enforce domain-specific export capabilities (PRIV-REPORT-DOMAIN-RBAC-01)
        match ($type) {
            'opc_register' => abort_unless($user->canDo('privacy.breach.export'), 403),
            'sla' => abort_unless($user->canDo('privacy.export'), 403),
            'retention' => abort_unless($user->canDo('privacy.retention.export'), 403),
            'legal_holds' => abort_unless($user->canDo('privacy.legal_holds.export') || $user->canDo('privacy.legalHolds.export'), 403),
            'full' => abort_unless($user->canDo('privacy.export'), 403),
        };

        $startDate = $this->startDate($request->get('period', 'year'));
        $filename = 'privacy-'.str_replace('_', '-', $type).'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($type, $startDate, $user) {
            $out = fopen('php://output', 'w');
            match ($type) {
                'opc_register' => $this->writeBreachRegister($out, $startDate),
                'sla' => $this->writeSlaReport($out, $startDate),
                'retention' => $this->writeRetentionReport($out),
                'legal_holds' => $this->writeLegalHoldsReport($out, $startDate),
                default => $this->writeFullReport($out, $startDate, $user),
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
        $this->putCsv($out, ['Reference', 'Discovered', 'Nature', 'Affected', 'Severity', 'Status', 'OPC required', 'OPC notified', 'Subjects notified', 'Resolved']);

        DataBreachLog::where('discovered_at', '>=', $startDate)->orderByDesc('discovered_at')
            ->each(function (DataBreachLog $b) use ($out) {
                $this->putCsv($out, [
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
        $this->putCsv($out, ['Reference', 'Type', 'Received', 'Due', 'Completed', 'Status', 'Working days to complete', 'Overdue']);

        DataSubjectRequest::where('created_at', '>=', $startDate)->orderByDesc('received_at')
            ->each(function (DataSubjectRequest $r) use ($out) {
                $days = $r->received_at && $r->completed_at
                    ? (int) $r->received_at->diffInDays($r->completed_at)
                    : '';
                $this->putCsv($out, [
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
        $this->putCsv($out, ['Policy', 'Applies to', 'Retention (years)', 'Archive after', 'Hard delete after', 'Active', 'Next review', 'Last applied', 'Legal basis']);

        DataRetentionPolicy::orderBy('model_type')->each(function (DataRetentionPolicy $p) use ($out) {
            $this->putCsv($out, [
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
    private function writeLegalHoldsReport($out, Carbon $startDate): void
    {
        $this->putCsv($out, ['Reference', 'Type', 'Reason', 'Legal authority', 'Status', 'Imposed at', 'Imposed by', 'Review date', 'Released at', 'Released by', 'Release reason']);

        LegalHold::with(['imposedBy', 'releasedBy'])
            ->where('created_at', '>=', $startDate)
            ->orderByDesc('imposed_at')
            ->each(function (LegalHold $h) use ($out) {
                $this->putCsv($out, [
                    $h->hold_reference,
                    $h->hold_type,
                    $h->reason,
                    $h->legal_authority ?: '—',
                    $h->status,
                    optional($h->imposed_at)->toDateString() ?: '—',
                    $h->imposedBy?->name ?: '—',
                    optional($h->review_date)->toDateString() ?: '—',
                    optional($h->released_at)->toDateString() ?: '—',
                    $h->releasedBy?->name ?: '—',
                    $h->release_reason ?: '—',
                ]);
            });
    }

    /**
     * @param  resource  $out
     */
    private function writeFullReport($out, Carbon $startDate, ?User $user = null): void
    {
        $this->putCsv($out, ['Privacy compliance report']);
        $this->putCsv($out, ['Generated', now()->toDayDateTimeString()]);
        $this->putCsv($out, ['Since', $startDate->toDateString()]);
        $this->putCsv($out, []);
        $this->putCsv($out, ['Section', 'Metric', 'Value']);

        $rows = [];

        // Cross-domain compliance report only exposes domains the actor is independently authorised to view
        if ($user?->canDo('privacy.viewRequests') || $user?->canDo('privacy.processRequests') || $user?->canDo('privacy.export')) {
            $rows[] = ['Access & correction requests', 'Total', DataSubjectRequest::where('created_at', '>=', $startDate)->count()];
            $rows[] = ['Access & correction requests', 'Completed', DataSubjectRequest::where('status', 'completed')->where('completed_at', '>=', $startDate)->count()];
            $rows[] = ['Access & correction requests', 'Overdue (open, past 20 working days)', DataSubjectRequest::overdue()->count()];
            $rows[] = ['Access & correction requests', 'Average response (days)', $this->calculateAverageResponseDays($startDate)];
        }

        if ($user?->canDo('privacy.reportBreaches') || $user?->canDo('privacy.breach.export')) {
            $rows[] = ['Notifiable breaches', 'Total', DataBreachLog::where('discovered_at', '>=', $startDate)->count()];
            $rows[] = ['Notifiable breaches', 'OPC-notified', DataBreachLog::whereNotNull('authority_notified_at')->where('authority_notified_at', '>=', $startDate)->count()];
            $rows[] = ['Notifiable breaches', 'Awaiting OPC notification', DataBreachLog::where('requires_authority_notification', true)->whereNull('authority_notified_at')->count()];
        }

        if ($user?->canDo('privacy.manageLegalHolds') || $user?->canDo('privacy.legal_holds.export') || $user?->canDo('privacy.legalHolds.export')) {
            $rows[] = ['Legal holds', 'Active', LegalHold::active()->count()];
        }

        if ($user?->canDo('privacy.manageRetention') || $user?->canDo('privacy.retention.export')) {
            $rows[] = ['Retention policies', 'Active', DataRetentionPolicy::where('active', true)->count()];
        }

        if ($user?->canDo('privacy.conductDPIA')) {
            $rows[] = ['DPIAs', 'High / very-high risk (open)', PrivacyImpactAssessment::whereIn('overall_risk_level', ['high', 'very_high'])->whereNull('outcome')->count()];
        }

        foreach ($rows as $row) {
            $this->putCsv($out, $row);
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
