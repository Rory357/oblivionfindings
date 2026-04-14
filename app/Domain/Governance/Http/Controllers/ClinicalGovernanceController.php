<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\ClinicalGovernanceIndicator;
use App\Domain\Governance\Models\ClinicalGovernanceSnapshot;
use App\Domain\Governance\Services\ClinicalGovernanceAutomationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClinicalGovernanceController extends Controller
{
    public function __construct(
        protected ClinicalGovernanceAutomationService $automationService,
    ) {}

    public function dashboard(Request $request)
    {
        $snapshot = $this->automationService->syncCurrentSnapshot();
        $indicators = $this->automationService->supportedIndicators();

        return Inertia::render('Governance/Clinical/Dashboard', [
            'indicators' => $this->mapIndicators($indicators),
            'latestSnapshot' => $this->mapSnapshot($snapshot, $request),
            'sourceHint' => $this->automationService->sourceHint(),
        ]);
    }

    public function storeIndicator(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|in:medication_safety,incident_rates,restraint_usage,infection_control,falls,client_outcomes,other',
            'description' => 'nullable|string',
            'target_value' => 'required|numeric',
            'target_direction' => 'required|in:above,below,equal',
            'unit' => 'required|string|max:50',
            'reporting_frequency' => 'required|in:weekly,monthly,quarterly',
        ]);

        ClinicalGovernanceIndicator::create([
            ...$validated,
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);

        return redirect()->back()->with('success', 'Clinical indicator added.');
    }

    public function recordSnapshot(Request $request)
    {
        $validated = $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
            'indicator_values' => 'required|array',
            'indicator_values.*.indicator_id' => 'required|exists:clinical_governance_indicators,id',
            'indicator_values.*.value' => 'required|numeric',
            'narrative' => 'nullable|string',
        ]);

        ClinicalGovernanceSnapshot::create([
            'period_start' => $validated['period_start'],
            'period_end' => $validated['period_end'],
            'indicator_values' => $validated['indicator_values'],
            'narrative' => $validated['narrative'] ?? null,
            'captured_by' => auth()->id(),
        ]);

        return redirect()->back()->with('success', 'Clinical governance snapshot recorded.');
    }

    public function trends(Request $request)
    {
        $this->automationService->syncCurrentSnapshot();
        $snapshots = $this->automationService->recentSnapshots();
        $indicators = $this->automationService->supportedIndicators();

        return Inertia::render('Governance/Clinical/Trends', [
            'snapshots' => $snapshots->map(fn (ClinicalGovernanceSnapshot $snapshot) => $this->mapSnapshot($snapshot, $request))->values(),
            'indicators' => $this->mapIndicators($indicators),
            'sourceHint' => $this->automationService->sourceHint(),
        ]);
    }

    protected function mapIndicators($indicators): array
    {
        $meta = $this->automationService->definitionMeta();

        return $indicators->map(function (ClinicalGovernanceIndicator $indicator) use ($meta) {
            return [
                'id' => $indicator->id,
                'indicator_code' => $indicator->indicator_code,
                'name' => $indicator->name,
                'category' => $indicator->category,
                'category_label' => ClinicalGovernanceIndicator::CATEGORIES[$indicator->category] ?? str($indicator->category)->headline()->value(),
                'definition' => $indicator->definition,
                'data_source' => $indicator->data_source,
                'unit' => $indicator->unit,
                'target_value' => $indicator->target_value !== null ? (float) $indicator->target_value : null,
                'target_direction' => $meta[$indicator->indicator_code]['target_direction'] ?? 'below',
                'reporting_frequency' => $indicator->frequency,
                'is_active' => (bool) $indicator->is_active,
                'is_automated' => (bool) $indicator->is_automated,
            ];
        })->values()->all();
    }

    protected function mapSnapshot(ClinicalGovernanceSnapshot $snapshot, Request $request): array
    {
        return [
            'id' => $snapshot->id,
            'period_start' => $snapshot->period_start?->toDateString(),
            'period_end' => $snapshot->period_end?->toDateString(),
            'indicator_values' => collect($snapshot->indicator_values ?? [])->map(function (array $value) use ($request) {
                $sourceHref = $value['source_href'] ?? null;
                $indicatorCode = $value['indicator_code'] ?? null;

                if ($indicatorCode === 'HCG-001' && ! $request->user()?->canDo('medications.view')) {
                    $sourceHref = null;
                }

                if (in_array($indicatorCode, ['HCG-002', 'HCG-003', 'HCG-004'], true)
                    && ! $request->user()?->canDo('clinical.events.viewAny')) {
                    $sourceHref = null;
                }

                return [
                    'indicator_id' => (int) $value['indicator_id'],
                    'indicator_code' => $indicatorCode,
                    'value' => (float) ($value['value'] ?? 0),
                    'status' => $value['status'] ?? 'normal',
                    'trend' => $value['trend'] ?? 'stable',
                    'source_href' => $sourceHref,
                    'source_label' => $sourceHref ? ($value['source_label'] ?? 'View source') : null,
                ];
            })->values()->all(),
            'narrative' => $snapshot->narrative,
            'summary' => $snapshot->summary ?? [],
        ];
    }
}
