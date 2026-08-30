<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Finance\Services\BudgetActualsService;
use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Services\AuditEvidencePackService;
use App\Domain\Governance\Services\DashboardAggregatorService;
use App\Domain\Governance\Services\GovernanceWorkflowService;
use App\Domain\Governance\Support\GovernancePresenter;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function __construct(
        protected DashboardAggregatorService $aggregator,
        protected AuditEvidencePackService $evidenceService,
        protected GovernanceWorkflowService $workflowService,
        protected GovernancePresenter $presenter,
        protected BudgetActualsService $budgetActualsService,
    ) {}

    public function boardMonthly(Request $request)
    {
        $range = ['start' => now()->startOfMonth(), 'end' => now()];
        $this->syncBudgetActuals($request);
        $widgets = [
            'top_risks' => $this->aggregator->getTopRisks(),
            'voided_risks' => $this->aggregator->getVoidedRisks($range),
            'risk_changes' => $this->aggregator->getRiskChanges($range),
            'client_safety' => $this->aggregator->getClientSafetyMetrics($range),
            'operational_safety' => $this->aggregator->getOperationalSafetyMetrics($range),
            'privacy_data' => $this->aggregator->getPrivacyMetrics($range),
            'workforce' => $this->aggregator->getWorkforceMetrics($range),
            'financial' => $this->aggregator->getFinancialMetrics($range),
            'it_cyber' => $this->aggregator->getItCyberMetrics($range),
            'compliance_calendar' => $this->aggregator->getComplianceCalendar(),
            'decisions_required' => $this->aggregator->getDecisionsRequired(),
            'roadmap' => $this->aggregator->getRoadmapMetrics(),
            'control_room' => $this->aggregator->getControlRoomMetrics($range),
            'incidents' => $this->aggregator->getIncidentMetrics($range),
            'safeguarding' => $this->aggregator->getSafeguardingMetrics($range),
        ];
        $workflow = $this->workflowService->dashboardWorkflow($request->user());

        return Inertia::render('Governance/Reports/BoardMonthly', [
            'report' => $this->presenter->boardMonthly($widgets, [], $workflow, $request->user()),
            'generatedAt' => now()->toIso8601String(),
        ]);
    }

    public function committeeReport(Request $request, string $committee)
    {
        $validCommittees = ['audit_risk', 'people', 'finance'];
        abort_unless(in_array($committee, $validCommittees), 404);

        $range = ['start' => now()->subMonths(3), 'end' => now()];
        $committeeModel = BoardCommittee::where('committee_type', $committee)->first();

        $categoryMap = [
            'audit_risk' => ['financial', 'legal_compliance', 'it_cyber'],
            'people' => ['workforce', 'client_safety'],
            'finance' => ['financial'],
        ];

        $risks = RiskRegisterEntry::active()
            ->with('riskOwner')
            ->whereIn('category', $categoryMap[$committee] ?? [])
            ->orderByDesc('residual_score')
            ->get();

        $this->syncBudgetActuals($request);
        $widgets = match ($committee) {
            'audit_risk' => [
                'top_risks' => $this->aggregator->getTopRisks(),
                'compliance_calendar' => $this->aggregator->getComplianceCalendar(),
                'it_cyber' => $this->aggregator->getItCyberMetrics($range),
                'privacy_data' => $this->aggregator->getPrivacyMetrics($range),
                'hs_backbone' => $this->aggregator->getHsBackboneMetrics($range, $request->user()),
            ],
            'people' => [
                'workforce' => $this->aggregator->getWorkforceMetrics($range),
                'client_safety' => $this->aggregator->getClientSafetyMetrics($range),
                'operational_safety' => $this->aggregator->getOperationalSafetyMetrics($range),
                'safeguarding' => $this->aggregator->getSafeguardingMetrics($range),
            ],
            'finance' => [
                'financial' => $this->aggregator->getFinancialMetrics($range),
                'roadmap' => $this->aggregator->getRoadmapMetrics(),
                'decisions_required' => $this->aggregator->getDecisionsRequired(),
            ],
            default => [],
        };

        return Inertia::render('Governance/Reports/Committee', [
            'report' => $this->presenter->committee($committee, $committeeModel, $risks, $widgets),
            'generatedAt' => now()->toIso8601String(),
        ]);
    }

    public function complianceStatus()
    {
        $obligations = ComplianceObligation::with('owner')
            ->orderBy('due_date')
            ->get()
            ->groupBy('framework');

        $summary = [
            'total' => ComplianceObligation::count(),
            'complete' => ComplianceObligation::where('status', 'complete')->count(),
            'overdue' => ComplianceObligation::where('status', 'overdue')->count(),
            'due_soon' => ComplianceObligation::where('status', 'due_soon')->count(),
        ];

        return Inertia::render('Governance/Reports/ComplianceStatus', [
            'report' => $this->presenter->complianceStatus($obligations, $summary),
        ]);
    }

    public function riskNarrative()
    {
        $risks = RiskRegisterEntry::active()
            ->with('riskOwner', 'treatments')
            ->orderByDesc('residual_score')
            ->limit(10)
            ->get();

        $narrative = $risks->map(fn ($r) => [
            'id' => $r->id,
            'reference' => $r->risk_reference,
            'title' => $r->title,
            'category' => $r->category,
            'description' => $r->description,
            'inherent_score' => $r->inherent_score,
            'residual_score' => $r->residual_score,
            'control_effectiveness' => $r->control_effectiveness,
            'within_appetite' => $r->within_appetite,
            'severity' => $r->getSeverityColor(),
            'owner' => $r->riskOwner?->name,
            'mitigation_strategy' => $r->mitigation_strategy,
            'treatments_count' => $r->treatments->count(),
            'active_treatments' => $r->treatments->where('status', 'in_progress')->count(),
            'next_review' => $r->next_review_date?->toDateString(),
        ]);

        return Inertia::render('Governance/Reports/RiskNarrative', [
            'risks' => $narrative,
            'summary' => [
                'critical' => RiskRegisterEntry::active()->critical()->count(),
                'high' => RiskRegisterEntry::active()->high()->count(),
                'above_appetite' => RiskRegisterEntry::active()->aboveAppetite()->count(),
                'total_active' => RiskRegisterEntry::active()->count(),
            ],
        ]);
    }

    public function evidencePack(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:compliance,risk,meeting,full_governance',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date',
            'framework' => 'nullable|string',
        ]);

        $pack = $this->evidenceService->generate(
            type: $data['type'],
            periodStart: $data['period_start'] ?? null,
            periodEnd: $data['period_end'] ?? null,
            framework: $data['framework'] ?? null,
        );

        return response()->json([
            'pack_id' => $pack->id,
            'download_url' => route('governance.reports.export', ['type' => 'evidence-pack-'.$pack->id]),
        ]);
    }

    public function export(Request $request, string $type)
    {
        $range = ['start' => now()->subMonths(3), 'end' => now()];

        return match (true) {
            str_starts_with($type, 'evidence-pack-') => $this->evidenceService->download((int) str_replace('evidence-pack-', '', $type)),
            $type === 'risks-csv' => $this->exportRisksCsv(),
            $type === 'compliance-csv' => $this->exportComplianceCsv(),
            default => abort(404),
        };
    }

    private function exportRisksCsv()
    {
        $risks = RiskRegisterEntry::active()->with('riskOwner')->orderByDesc('residual_score')->get();

        $csv = "Reference,Title,Category,Likelihood,Impact,Inherent Score,Control Effectiveness,Residual Score,Within Appetite,Owner,Status,Next Review\n";
        foreach ($risks as $r) {
            $csv .= sprintf(
                '"%s","%s","%s",%d,%d,%d,"%s",%d,%s,"%s","%s","%s"'."\n",
                $r->risk_reference, $r->title, $r->category,
                $r->likelihood_score, $r->impact_score, $r->inherent_score,
                $r->control_effectiveness, $r->residual_score,
                $r->within_appetite ? 'Yes' : 'No',
                $r->riskOwner?->name ?? '', $r->status,
                $r->next_review_date?->toDateString() ?? '',
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="risk-register-'.now()->format('Y-m-d').'.csv"',
        ]);
    }

    private function exportComplianceCsv()
    {
        $obligations = ComplianceObligation::with('owner')->orderBy('due_date')->get();

        $csv = "Framework,Title,Owner,Due Date,Status,Frequency\n";
        foreach ($obligations as $o) {
            $csv .= sprintf(
                '"%s","%s","%s","%s","%s","%s"'."\n",
                $o->getFrameworkLabel(), $o->obligation_title,
                $o->owner?->name ?? '', $o->due_date?->toDateString() ?? '',
                $o->status, $o->review_frequency ?? '',
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="compliance-register-'.now()->format('Y-m-d').'.csv"',
        ]);
    }

    protected function syncBudgetActuals(Request $request): void
    {
        try {
            $this->budgetActualsService->syncActuals($request->user()?->organization_id);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
