<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\ClinicalGovernanceIndicator;
use App\Domain\Governance\Models\ClinicalGovernanceSnapshot;
use App\Domain\Governance\Services\ClinicalGovernanceAutomationService;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\Medication\Reporting\MedicationGovernanceReports;
use App\Services\Medication\Reporting\MedicationReportAccess;
use App\Services\Medication\MedicationGovernanceScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Care quality: medication errors, falls, skin injuries and infections.
 */
class ClinicalGovernanceController extends Controller
{
    private const STATUS_FILTERS = ['critical', 'warning', 'normal', 'no_data'];

    public function __construct(
        protected ClinicalGovernanceAutomationService $automationService,
    ) {}

    public function dashboard(Request $request)
    {
        $snapshot = $this->automationService->syncCurrentSnapshot();
        $indicators = $this->automationService->supportedIndicators();
        $status = (string) $request->query('status', '');

        return Inertia::render('Governance/Clinical/Dashboard', [
            'indicators' => $this->mapIndicators($indicators),
            'latestSnapshot' => $this->mapSnapshot($snapshot, $request, $this->automationService->sourcesInUse()),
            'sourceHint' => $this->automationService->sourceHint(),
            'filters' => array_filter([
                'status' => in_array($status, self::STATUS_FILTERS, true) ? $status : null,
                'site_id' => $request->integer('site_id') ?: null,
            ]),
            'medicationScope' => $this->medicationScope($request),
            'canConfigureMedicationTarget' => $request->user()->canDo('governance.clinical.manage'),
        ]);
    }

    public function storeIndicator(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => ['required', Rule::in(array_keys(ClinicalGovernanceIndicator::CATEGORIES))],
            'definition' => 'nullable|string',
            'description' => 'nullable|string',
            'target_value' => 'required|numeric',
            'target_direction' => 'nullable|in:above,below,equal',
            'unit' => 'required|string|max:50',
            'frequency' => 'nullable|in:monthly,quarterly',
            'reporting_frequency' => 'nullable|in:monthly,quarterly',
            'warning_threshold' => 'nullable|numeric',
            'critical_threshold' => 'nullable|numeric',
        ], [
            'name.required' => 'Give the measure a name.',
            'category.required' => 'Choose what the measure is about.',
            'category.in' => 'Choose what the measure is about from the list.',
            'target_value.required' => 'Enter the target.',
            'target_value.numeric' => 'Enter the target as a number.',
            'unit.required' => 'Say what is being counted.',
        ]);

        ClinicalGovernanceIndicator::create([
            'indicator_code' => $this->nextManualIndicatorCode(),
            'category' => $validated['category'],
            'name' => $validated['name'],
            'definition' => $validated['definition'] ?? $validated['description'] ?? null,
            'data_source' => 'Manual governance input',
            'unit' => $validated['unit'],
            'target_value' => $validated['target_value'],
            'warning_threshold' => $validated['warning_threshold'] ?? null,
            'critical_threshold' => $validated['critical_threshold'] ?? null,
            'frequency' => $validated['frequency'] ?? $validated['reporting_frequency'] ?? 'monthly',
            'is_automated' => false,
            'is_active' => true,
        ]);

        return redirect()->back()->with('success', 'Care quality measure added.');
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
        ], [
            'period_start.required' => 'Choose the first day of the period.',
            'period_end.required' => 'Choose the last day of the period.',
            'period_end.after' => 'The last day must be after the first day.',
            'indicator_values.required' => 'Enter at least one figure.',
            'indicator_values.*.value.required' => 'Enter a figure for each measure.',
            'indicator_values.*.value.numeric' => 'Enter each figure as a number.',
        ]);

        ClinicalGovernanceSnapshot::create([
            'period_start' => $validated['period_start'],
            'period_end' => $validated['period_end'],
            'indicator_values' => $validated['indicator_values'],
            'narrative' => $validated['narrative'] ?? null,
            'captured_by' => auth()->id(),
        ]);

        return redirect()->back()->with('success', 'Care quality figures recorded.');
    }

    public function trends(Request $request)
    {
        $this->automationService->syncCurrentSnapshot();
        $snapshots = $this->automationService->recentSnapshots();
        $indicators = $this->automationService->supportedIndicators();
        $sourcesInUse = $this->automationService->sourcesInUse();

        return Inertia::render('Governance/Clinical/Trends', [
            'snapshots' => $snapshots
                ->map(fn (ClinicalGovernanceSnapshot $snapshot, int $index) => $this->mapSnapshot($snapshot, $request, $index === 0 ? $sourcesInUse : null))
                ->values(),
            'indicators' => $this->mapIndicators($indicators),
            'sourceHint' => $this->automationService->sourceHint(),
            'medicationScope' => $this->medicationScope($request),
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
                'category_label' => GovernanceLabels::label('care_quality_category', $indicator->category),
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

    /**
     * @param  array<string, bool>|null  $sourcesInUse  Only for the current month.
     */
    protected function mapSnapshot(ClinicalGovernanceSnapshot $snapshot, Request $request, ?array $sourcesInUse = null): array
    {
        $request->validate(['site_id' => ['nullable', 'integer', 'min:1']]);
        $medication = $snapshot->period_start && $snapshot->period_end ? app(MedicationGovernanceReports::class)->values($request->user(), $snapshot->period_start->toDateString(), $snapshot->period_end->toDateString(), $request->integer('site_id') ?: null) : array_fill_keys(['HCG-001', 'HCG-005'], ['value' => null, 'status' => 'no_data', 'recorded' => false, 'source_href' => null]);
        $comparedWith = $snapshot->summary['compared_with'] ?? null;
        $compareStart = is_array($comparedWith) && ! empty($comparedWith['start']) ? CarbonImmutable::parse($comparedWith['start']) : null;
        $compareEnd = is_array($comparedWith) && ! empty($comparedWith['end']) ? CarbonImmutable::parse($comparedWith['end']) : null;
        $previousMedication = $compareStart && $compareEnd ? app(MedicationGovernanceReports::class)->values($request->user(), $compareStart->toDateString(), $compareEnd->toDateString(), $request->integer('site_id') ?: null) : [];
        $isComplete = $snapshot->period_start !== null && $snapshot->period_end !== null
            && ClinicalGovernanceAutomationService::coversWholeMonth($snapshot->period_start, $snapshot->period_end);

        return [
            'id' => $snapshot->id,
            'period_start' => $snapshot->period_start?->toDateString(),
            'period_end' => $snapshot->period_end?->toDateString(),
            'period_label' => ClinicalGovernanceAutomationService::periodLabel($snapshot->period_start, $snapshot->period_end),
            'short_label' => ClinicalGovernanceAutomationService::shortPeriodLabel($snapshot->period_start, $snapshot->period_end),
            'is_complete' => $isComplete,
            'compared_with_label' => $compareStart && $compareEnd
                ? ($isComplete ? $compareStart->format('F Y') : ClinicalGovernanceAutomationService::dayRange($compareStart, $compareEnd))
                : null,
            'indicator_values' => collect($snapshot->indicator_values ?? [])->map(function (array $value) use ($request, $sourcesInUse, $medication, $previousMedication) {
                $sourceHref = $value['source_href'] ?? null;
                $indicatorCode = $value['indicator_code'] ?? null;

                if (isset($medication[$indicatorCode])) {
                    $value = array_replace($value, $medication[$indicatorCode]);
                    $value['previous_value'] = $previousMedication[$indicatorCode]['value'] ?? null;
                    $value['trend'] = $value['value'] === null || $value['previous_value'] === null ? 'stable' : ($value['value'] > $value['previous_value'] ? 'up' : ($value['value'] < $value['previous_value'] ? 'down' : 'stable'));
                    $sourceHref = $value['source_href'];
                }

                if (in_array($indicatorCode, ['HCG-002', 'HCG-003', 'HCG-004'], true)
                    && ! $request->user()?->canDo('clinical.events.viewAny')) {
                    $sourceHref = null;
                }

                return [
                    'indicator_id' => (int) $value['indicator_id'],
                    'indicator_code' => $indicatorCode,
                    'value' => $value['value'] === null ? null : (float) $value['value'],
                    'status' => $value['status'] ?? 'normal',
                    'trend' => $value['trend'] ?? 'stable',
                    'previous_value' => isset($value['previous_value']) ? (float) $value['previous_value'] : null,
                    // False only when nobody has recorded anything in the source yet.
                    'recorded' => $value['recorded'] ?? ($sourcesInUse === null ? true : ($sourcesInUse[$indicatorCode] ?? true)),
                    'source_href' => $sourceHref,
                    'source_label' => $sourceHref ? ($value['source_label'] ?? 'Open the records') : null,
                ];
            })->values()->all(),
        ];
    }

    private function medicationScope(Request $request): array
    {
        $actor = $request->user();
        $access = app(MedicationReportAccess::class);
        $sites = $actor->canDo('medications.reports.view') && ! $access->financeOnly($actor) ? $access->siteIds($actor) : [];

        return ['site_id' => $request->integer('site_id') ?: null, 'sites' => app(MedicationGovernanceScopeService::class)->sitePicker($sites)->map->only(['id', 'name'])->values()];
    }

    public function medicationTarget(Request $request)
    {
        abort_unless($request->user()->canDo('governance.clinical.manage'), 403);
        $data = $request->validate(['target' => ['nullable', 'integer', 'min:0', 'max:2147483647']]);
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $data) {
            $actor = app(\App\Services\AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($request->user(), ['*']);
            abort_unless($actor->canDo('governance.clinical.manage'), 403);
            $sites = \App\Services\CurrentAuthorizationReads::within(fn ($reads) => app(\App\Services\UserSiteAccessService::class)->accessibleSiteIds($actor, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS, $reads));
            abort_if($sites === [], 422, 'An approved house is required to record this medication setting change.');
            $value = ['target' => isset($data['target']) ? (int) $data['target'] : null, 'reviewed_by' => $actor->id, 'reviewed_at' => now()->toIso8601String()];
            AppSetting::updateOrCreate(['key' => MedicationGovernanceReports::TARGET_KEY], ['value' => $value]);
            \App\Services\AuditLogger::logOrFail('medications.governance.target.updated', $actor, ['target' => $value['target']]);
            app(\App\Services\Medication\Audit\MedicationEventRecorder::class)->appendMany(array_map(fn ($siteId) => new \App\Services\Medication\Audit\MedicationEventData((int) $siteId, 'settings.governance_target.changed', 'organisation_setting', MedicationGovernanceReports::TARGET_KEY, $actor->id, CarbonImmutable::now('UTC'), 'Medication error governance target changed.', ['target' => $value['target']]), $sites));
        }, 5);

        return back()->with('success', 'Medication error target saved.');
    }

    protected function nextManualIndicatorCode(): string
    {
        $latest = ClinicalGovernanceIndicator::query()
            ->where('indicator_code', 'like', 'HCG-MANUAL-%')
            ->pluck('indicator_code')
            ->map(fn (string $code) => (int) Str::afterLast($code, '-'))
            ->max();

        return sprintf('HCG-MANUAL-%03d', ($latest ?? 0) + 1);
    }
}
