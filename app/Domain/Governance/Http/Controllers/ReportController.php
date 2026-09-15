<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Models\RiskTreatment;
use App\Domain\Governance\Services\AuditEvidencePackService;
use App\Domain\Governance\Services\ComplianceEngineService;
use App\Domain\Governance\Services\DashboardAggregatorService;
use App\Domain\Governance\Services\GovernanceWorkflowService;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Domain\Governance\Support\GovernancePresenter;
use App\Domain\Governance\Support\RiskCommitteeScope;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ReportController extends Controller
{
    private const NOT_AVAILABLE = 'Not available';

    public function __construct(
        protected DashboardAggregatorService $aggregator,
        protected AuditEvidencePackService $evidenceService,
        protected GovernanceWorkflowService $workflowService,
        protected GovernancePresenter $presenter,
    ) {}

    /**
     * The board's month so far. Each part is loaded on its own: when one
     * fails its figures say "Not available" instead of showing 0.
     */
    public function boardMonthly(Request $request)
    {
        $user = $request->user();
        $today = CarbonImmutable::now(self::timezone());
        $range = [
            'start' => now(self::timezone())->startOfMonth()->utc(),
            'end' => now(),
        ];

        $widgets = $this->loadWidgets([
            'top_risks' => fn () => $this->aggregator->getTopRisks(),
            'voided_risks' => fn () => $this->aggregator->getVoidedRisks($range),
            'risk_changes' => fn () => $this->aggregator->getRiskChanges($range),
            'client_safety' => fn () => $this->aggregator->getClientSafetyMetrics($range),
            'operational_safety' => fn () => $this->aggregator->getOperationalSafetyMetrics($range),
            'privacy_data' => fn () => $this->aggregator->getPrivacyMetrics($range),
            'workforce' => fn () => $this->aggregator->getWorkforceMetrics($range),
            'financial' => fn () => $this->aggregator->getFinancialMetrics($range, $user),
            'it_cyber' => fn () => $this->aggregator->getItCyberMetrics($range),
            'compliance_calendar' => fn () => $this->aggregator->getComplianceCalendar(),
            'decisions_required' => fn () => $this->aggregator->getDecisionsRequired($user),
            'roadmap' => fn () => $this->aggregator->getRoadmapMetrics(),
            'control_room' => fn () => $this->aggregator->getControlRoomMetrics($range),
            'incidents' => fn () => $this->aggregator->getIncidentMetrics($range),
            'safeguarding' => fn () => $this->aggregator->getSafeguardingMetrics($range),
        ]);
        $workflow = $this->workflowService->dashboardWorkflow($user);

        $report = $this->presenter->boardMonthly($widgets, [], $workflow, $user);
        $report['headline'] = $this->boardMonthlyHeadline($widgets, $workflow);
        $report['sections'] = $this->tidySections($report['sections'] ?? [], $widgets);

        return Inertia::render('Governance/Reports/BoardMonthly', [
            'report' => $report,
            'period' => [
                'start' => $today->startOfMonth()->toDateString(),
                'end' => $today->toDateString(),
                'label' => self::monthToDateLabel($today),
            ],
            'generatedAt' => now()->toIso8601String(),
        ]);
    }

    /**
     * A committee's report, driven by the real committee, its members and the
     * one shared category map (RiskCommitteeScope).
     */
    public function committeeReport(Request $request, string $committee)
    {
        abort_unless((bool) $request->user()?->canDo('governance.risks.view'), 403);

        $model = RiskCommitteeScope::resolve($committee);
        abort_if($model === null, 404);

        $today = CarbonImmutable::now(self::timezone());
        $range = ['start' => now()->subMonths(3), 'end' => now()];
        $risks = RiskCommitteeScope::currentRisks($model);

        $widgets = $this->loadWidgets(match ($model->committee_type) {
            'audit_risk' => [
                'top_risks' => fn () => $this->aggregator->getTopRisks(),
                'compliance_calendar' => fn () => $this->aggregator->getComplianceCalendar(),
                'it_cyber' => fn () => $this->aggregator->getItCyberMetrics($range),
                'privacy_data' => fn () => $this->aggregator->getPrivacyMetrics($range),
                'hs_backbone' => fn () => $this->aggregator->getHsBackboneMetrics($range, $request->user()),
            ],
            'people' => [
                'workforce' => fn () => $this->aggregator->getWorkforceMetrics($range),
                'client_safety' => fn () => $this->aggregator->getClientSafetyMetrics($range),
                'operational_safety' => fn () => $this->aggregator->getOperationalSafetyMetrics($range),
                'safeguarding' => fn () => $this->aggregator->getSafeguardingMetrics($range),
            ],
            'finance' => [
                'financial' => fn () => $this->aggregator->getFinancialMetrics($range, $request->user()),
                'roadmap' => fn () => $this->aggregator->getRoadmapMetrics(),
                'decisions_required' => fn () => $this->aggregator->getDecisionsRequired($request->user()),
            ],
            default => [],
        });

        $report = $this->presenter->committee((string) $model->committee_type, $model, $risks, $widgets);
        $riskViewHref = "/governance/risks/committee/{$model->id}";
        $highOrCritical = $risks->filter(fn (RiskRegisterEntry $risk) => (int) $risk->residual_score >= 15)->count();
        $critical = $risks->filter(fn (RiskRegisterEntry $risk) => (int) $risk->residual_score >= 20)->count();

        $report['committee'] = [
            ...($report['committee'] ?? []),
            'id' => (int) $model->id,
            'name' => (string) $model->name,
            'type' => (string) $model->committee_type,
            'members' => RiskCommitteeScope::members($model),
            'categories' => RiskCommitteeScope::categoryOptions($model),
            'risk_view_href' => $riskViewHref,
        ];
        $report['headline'] = [
            ['label' => 'Risks overseen', 'value' => (string) $risks->count(), 'tone' => 'default', 'href' => $riskViewHref],
            ['label' => 'High or critical', 'value' => (string) $highOrCritical, 'tone' => $critical > 0 ? 'critical' : ($highOrCritical > 0 ? 'warning' : 'default'), 'href' => $riskViewHref],
        ];
        $report['sections'] = $this->tidySections($report['sections'] ?? [], $widgets);
        $report['risks'] = $risks->map(fn (RiskRegisterEntry $risk) => [
            'id' => $risk->id,
            'reference' => $risk->risk_reference,
            'title' => $risk->title,
            'category' => GovernanceLabels::label('risk_category', $risk->category),
            'residual_score' => (int) $risk->residual_score,
            'owner' => $risk->riskOwner?->name,
            'within_appetite' => (bool) $risk->within_appetite,
            'status' => $risk->presentedStatus(),
        ])->values()->all();

        return Inertia::render('Governance/Reports/Committee', [
            'report' => $report,
            'committees' => RiskCommitteeScope::committeeOptions(),
            'period' => [
                'label' => sprintf('Last 3 months (%s to %s)', GovernanceLabels::date($today->subMonthsNoOverflow(3)->toDateString()), GovernanceLabels::date($today->toDateString())),
            ],
            'generatedAt' => now()->toIso8601String(),
        ]);
    }

    /**
     * Every requirement by where it comes from, with status worked out from
     * its due date (NZ) — right even before the nightly refresh runs.
     */
    public function complianceStatus(Request $request, ComplianceEngineService $engine)
    {
        abort_unless((bool) $request->user()?->canDo('governance.compliance.view'), 403);

        $today = ComplianceObligation::nzToday();
        $status = $engine->getComplianceStatus($today);
        $labels = ComplianceObligation::frameworkOptions();

        $frameworks = ComplianceObligation::query()
            ->with('owner:id,name')
            ->orderBy('due_date')
            ->get()
            ->groupBy('framework')
            ->map(function ($items, $framework) use ($labels, $status, $today) {
                $counts = $status['by_framework'][$framework] ?? null;

                return [
                    'key' => (string) $framework,
                    'title' => $labels[$framework] ?? GovernanceLabels::label('compliance_framework', (string) $framework),
                    'count' => $items->count(),
                    'counted' => (int) ($counts['counted'] ?? $items->count()),
                    'on_time' => (int) ($counts['on_time'] ?? 0),
                    'overdue' => (int) ($counts['overdue'] ?? 0),
                    'items' => $items->map(fn (ComplianceObligation $obligation) => [
                        'id' => $obligation->id,
                        'title' => $obligation->obligation_title,
                        'code' => filled($obligation->obligation_code) ? $obligation->obligation_code : null,
                        'owner' => $obligation->owner?->name,
                        'due_date' => $obligation->due_date?->toDateString(),
                        'status' => $obligation->currentStatus($today),
                        'days_until_due' => $obligation->daysUntilDue($today),
                    ])->values()->all(),
                ];
            })
            ->sortBy('title')
            ->values()
            ->all();

        return Inertia::render('Governance/Reports/ComplianceStatus', [
            'report' => [
                'summary' => $status['totals'],
                'due_soon_days' => $status['due_soon_days'],
                'frameworks' => $frameworks,
            ],
            'today' => $today,
            'generatedAt' => now()->toIso8601String(),
        ]);
    }

    /**
     * The 10 highest risks after controls, with the same counts the register
     * shows when each block is opened.
     */
    public function riskNarrative(Request $request)
    {
        abort_unless((bool) $request->user()?->canDo('governance.risks.view'), 403);

        $today = ComplianceObligation::nzToday();
        $risks = RiskRegisterEntry::query()
            ->current()
            ->with('riskOwner:id,name', 'treatments')
            ->orderByDesc('residual_score')
            ->orderBy('title')
            ->limit(10)
            ->get();

        $narrative = $risks->map(fn (RiskRegisterEntry $risk) => [
            'id' => $risk->id,
            'reference' => $risk->risk_reference,
            'title' => $risk->title,
            'category' => $risk->category,
            'category_label' => GovernanceLabels::label('risk_category', $risk->category),
            'description' => $risk->description,
            'inherent_score' => (int) $risk->inherent_score,
            'residual_score' => (int) $risk->residual_score,
            'appetite_threshold' => (int) $risk->appetite_threshold,
            'control_effectiveness' => $risk->control_effectiveness,
            'within_appetite' => (bool) $risk->within_appetite,
            'status' => $risk->presentedStatus(),
            // The owner's name, or null when nobody is named.
            'owner' => $risk->riskOwner?->name,
            'mitigation_strategy' => $risk->mitigation_strategy,
            'treatments_count' => $risk->treatments->count(),
            'open_treatments' => $risk->treatments
                ->reject(fn (RiskTreatment $treatment) => $treatment->isComplete() || $treatment->status === 'cancelled')
                ->count(),
            'overdue_treatments' => $risk->treatments
                ->filter(fn (RiskTreatment $treatment) => $treatment->isOverdue($today))
                ->count(),
            'next_review' => $risk->next_review_date?->toDateString(),
        ]);

        return Inertia::render('Governance/Reports/RiskNarrative', [
            'risks' => $narrative,
            'summary' => [
                'critical' => RiskRegisterEntry::query()->current()->severity('critical')->count(),
                'high' => RiskRegisterEntry::query()->current()->severity('high')->count(),
                'above_limit' => RiskRegisterEntry::query()->openStatus()->aboveAppetite()->count(),
                'current' => RiskRegisterEntry::query()->current()->count(),
            ],
            'generatedAt' => now()->toIso8601String(),
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
        return match (true) {
            str_starts_with($type, 'evidence-pack-') => $this->evidenceService->download((int) str_replace('evidence-pack-', '', $type)),
            $type === 'risks-csv' => $this->exportRisksCsv(),
            $type === 'compliance-csv' => $this->exportComplianceCsv(),
            default => abort(404),
        };
    }

    public static function timezone(): string
    {
        $timezone = config('app.worker_timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Pacific/Auckland';
    }

    /** "1–15 September 2026 (month to date)". */
    public static function monthToDateLabel(CarbonInterface $today): string
    {
        $days = $today->day === 1 ? '1' : '1–'.$today->day;

        return sprintf('%s %s (month to date)', $days, $today->format('F Y'));
    }

    /**
     * Load each widget on its own so one failure can't hide the rest.
     *
     * @param  array<string, callable(): mixed>  $loaders
     * @return array<string, mixed>
     */
    protected function loadWidgets(array $loaders): array
    {
        $widgets = [];

        foreach ($loaders as $key => $loader) {
            try {
                $widgets[$key] = $loader();
            } catch (\Throwable $e) {
                Log::warning("Governance report widget '{$key}' failed: ".$e->getMessage());
                $widgets[$key] = ['status' => 'unavailable', 'reason' => 'Widget data temporarily unavailable'];
            }
        }

        return $widgets;
    }

    protected static function isUnavailable(mixed $widget): bool
    {
        return ! is_array($widget) || ($widget['status'] ?? null) === 'unavailable';
    }

    /**
     * @param  array<string, mixed>  $widgets
     * @param  array<string, mixed>  $workflow
     * @return array<int, array{label: string, value: string, tone: string, href: string}>
     */
    protected function boardMonthlyHeadline(array $widgets, array $workflow): array
    {
        $decisions = $widgets['decisions_required'] ?? null;
        $financial = $widgets['financial'] ?? null;
        $variance = is_array($financial) ? ($financial['variance'] ?? null) : null;
        $overdueActions = $workflow['summary']['overdue'] ?? null;
        $criticalRisks = self::isUnavailable($widgets['top_risks'] ?? null)
            ? null
            : RiskRegisterEntry::query()->current()->severity('critical')->count();

        return [
            self::isUnavailable($decisions) || ! isset($decisions['count'])
                ? $this->notAvailable('Resolutions waiting', '/governance/resolutions')
                : ['label' => 'Resolutions waiting', 'value' => (string) (int) $decisions['count'], 'tone' => ($decisions['overdue'] ?? 0) > 0 ? 'critical' : 'default', 'href' => '/governance/resolutions'],
            $overdueActions === null
                ? $this->notAvailable('Overdue actions', '/governance/actions?status=overdue')
                : ['label' => 'Overdue actions', 'value' => (string) (int) $overdueActions, 'tone' => (int) $overdueActions > 0 ? 'critical' : 'default', 'href' => '/governance/actions?status=overdue'],
            $criticalRisks === null
                ? $this->notAvailable('Critical risks', '/governance/risks?severity=critical')
                : ['label' => 'Critical risks', 'value' => (string) $criticalRisks, 'tone' => $criticalRisks > 0 ? 'critical' : 'default', 'href' => '/governance/risks?severity=critical'],
            self::isUnavailable($financial) || ! is_numeric($variance)
                ? $this->notAvailable('Over or under budget', '/governance/budgets')
                : ['label' => 'Over or under budget', 'value' => number_format((float) $variance, 1).'%', 'tone' => abs((float) $variance) >= 5 ? 'warning' : 'default', 'href' => '/governance/budgets'],
        ];
    }

    /** @return array{label: string, value: string, tone: string, href: string} */
    protected function notAvailable(string $label, string $href): array
    {
        return ['label' => $label, 'value' => self::NOT_AVAILABLE, 'tone' => 'muted', 'href' => $href];
    }

    /**
     * Cards whose widget failed show "Not available" for every figure, and
     * highlights don't lead with reference codes.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @param  array<string, mixed>  $widgets
     * @return array<int, array<string, mixed>>
     */
    protected function tidySections(array $sections, array $widgets): array
    {
        return array_map(function (array $section) use ($widgets) {
            $section['cards'] = array_values(array_map(function (array $card) use ($widgets) {
                $key = (string) ($card['key'] ?? '');

                if (array_key_exists($key, $widgets) && self::isUnavailable($widgets[$key])) {
                    $card['status'] = 'unknown';
                    $card['metrics'] = array_map(
                        fn (array $metric) => [...$metric, 'value' => self::NOT_AVAILABLE, 'tone' => 'muted'],
                        $card['metrics'] ?? [],
                    );
                    $card['highlights'] = ["This information couldn't be loaded, so no figures are shown."];
                }

                $card['highlights'] = array_values(array_filter(array_map(
                    fn ($highlight) => trim((string) preg_replace('/^[A-Z]{1,6}-\d{4}-\d{1,6}\s*[·:–-]?\s*/u', '', (string) $highlight)),
                    $card['highlights'] ?? [],
                )));

                // No update-time badge: these figures are worked out when the page opens.
                unset($card['freshness']);

                return $card;
            }, $section['cards'] ?? []));

            return $section;
        }, $sections);
    }

    private function exportRisksCsv()
    {
        $risks = RiskRegisterEntry::active()->with('riskOwner')->orderByDesc('residual_score')->get();

        // Streamed through the shared sanitiser: titles and owner names are
        // user-entered, so every cell is neutralised against formula injection
        // and quoted properly.
        return $this->streamSanitizedCsv(
            'risk-register-'.now()->format('Y-m-d').'.csv',
            ['Title', 'Reference', 'Category', 'Likelihood', 'Impact', 'Risk before controls', 'How well controls work', 'Risk after controls', "Within the board's limit", 'Owner', 'Status', 'Next review'],
            $risks->map(fn (RiskRegisterEntry $r) => [
                $r->title,
                $r->risk_reference,
                GovernanceLabels::label('risk_category', $r->category),
                $r->likelihood_score,
                $r->impact_score,
                $r->inherent_score,
                GovernanceLabels::label('control_effectiveness', $r->control_effectiveness),
                $r->residual_score,
                $r->within_appetite ? 'Yes' : 'No',
                $r->riskOwner?->name ?? '',
                GovernanceLabels::label('risk_status', $r->status),
                $r->next_review_date ? GovernanceLabels::date($r->next_review_date) : '',
            ])->all(),
        );
    }

    private function exportComplianceCsv()
    {
        $obligations = ComplianceObligation::with('owner')->orderBy('due_date')->get();

        return $this->streamSanitizedCsv(
            'compliance-register-'.now()->format('Y-m-d').'.csv',
            ['Requirement', 'Framework', 'Owner', 'Due date', 'Status', 'How often'],
            $obligations->map(fn (ComplianceObligation $o) => [
                $o->obligation_title,
                $o->getFrameworkLabel(),
                $o->owner?->name ?? '',
                $o->due_date ? GovernanceLabels::date($o->due_date) : '',
                GovernanceLabels::label('compliance_status', $o->status),
                $o->review_frequency ? GovernanceLabels::label('frequency', $o->review_frequency) : '',
            ])->all(),
        );
    }
}
