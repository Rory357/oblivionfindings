<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Concerns\HandlesMedicationSync;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ControlledDrugLossReport;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\MedicationIncidentIntegrationService;
use App\Support\Medication\MedicationStockQuantity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CDLossReportController extends Controller
{
    use HandlesMedicationSync;

    private const REPLAY_CONFLICT = 'This controlled drug loss request was already used for a different reporter, target, or report. Please submit it again with a new request identifier.';

    public function __construct(private readonly MedicationGovernanceScopeService $governanceScope) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $siteFilter = $request->integer('site_id') ?: null;
        $clientFilter = $request->integer('client_id') ?: null;
        $siteIds = $this->governanceScope->readerSiteIds(
            $actor,
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
            $siteFilter,
            $clientFilter,
        );
        $readerSiteIds = $siteFilter !== null ? [$siteFilter] : $siteIds;

        $reports = $this->governanceScope->scopeCanonicalClientMedicationRows(
            ControlledDrugLossReport::query(),
            $readerSiteIds,
        )->when($clientFilter, fn ($query) => $query->where('client_id', $clientFilter))
            ->with([
                'client:id,first_name,last_name',
                'discoveredBy:id,name',
            ])
            ->latest()
            ->get();

        return response()->json($reports);
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor?->canDo('medications.controlled.record'), 403);

        $validated = $request->validate([
            'client_id' => ['required', 'integer', 'min:1'],
            'client_medication_id' => ['nullable', 'integer', 'min:1'],
            'medication_name' => ['required', 'string', 'max:255'],
            'quantity_lost' => ['required', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0.01', MedicationStockQuantity::DECIMAL_10_2_MAX_RULE],
            'unit' => ['nullable', 'string', 'max:50'],
            'circumstances' => ['required', 'string'],
            'immediate_action_taken' => ['required', 'string', 'max:5000'],
            'accountable_officer_name' => ['nullable', 'string', 'max:255'],
            'reported_to_police' => ['boolean'],
            'police_reference' => ['nullable', 'string', 'max:100'],
            'reported_to_pharmacy' => ['boolean'],
            'pharmacy_name' => ['nullable', 'string', 'max:255'],
            'reported_to_regulator' => ['boolean'],
            'regulator_name' => ['nullable', 'string', 'max:255'],
            'regulator_reference' => ['nullable', 'string', 'max:100'],
            ...$this->medicationOfflineSubmissionRules($request),
        ]);
        $validated['quantity_lost'] = MedicationStockQuantity::normalizeMovement($validated['quantity_lost']);
        $expectsJson = $request->expectsJson();

        try {
            $clientId = (int) $validated['client_id'];
            if (isset($validated['client_medication_id'])) {
                return $this->governanceScope->forMedication(
                    $actor,
                    (int) $validated['client_medication_id'],
                    MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
                    function (Client $client, ClientMedication $medication) use ($validated, $actor, $expectsJson) {
                        abort_unless($medication->controlled_drug, 404);

                        return $this->storeScoped($validated, $actor, $client, $medication, $expectsJson);
                    },
                    expectedClientId: $clientId,
                );
            }

            return $this->governanceScope->forClient(
                $actor,
                $clientId,
                MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
                fn (Client $client) => $this->storeScoped($validated, $actor, $client, null, $expectsJson),
            );
        } catch (ValidationException $exception) {
            if (($exception->errors()['client_request_uuid'][0] ?? null) !== self::REPLAY_CONFLICT) {
                throw $exception;
            }

            if (! $expectsJson) {
                throw $exception;
            }

            return response()->json(
                $this->buildMedicationConflictPayload($validated, self::REPLAY_CONFLICT),
                409,
            );
        }
    }

    private function storeScoped(
        array $validated,
        User $actor,
        Client $client,
        ?ClientMedication $medication = null,
        bool $expectsJson = false,
    ) {
        $validated['client_id'] = $client->id;
        $validated['client_medication_id'] = $medication?->id;
        if ($medication !== null) {
            $validated['medication_name'] = $medication->name;
        }

        // Keep the namespace stable for this actor and action so the same UUID
        // cannot be reused against another canonical Client or medication.
        // Target identity remains bound below and produces the generic 409.
        $scope = 'emar-controlled-loss-report';
        $idempotencyBinding = $this->lossReportSyncBinding(
            $validated,
            (int) $actor->id,
        );
        $requestFingerprint = hash('sha256', json_encode(
            $idempotencyBinding,
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        if (
            $this->medicationSyncRequested($validated)
            && ($cached = $this->governanceScope->idempotencyResult(
                $scope,
                $validated,
                $requestFingerprint,
                self::REPLAY_CONFLICT,
                durable: true,
            ))
        ) {
            if (! $expectsJson) {
                return redirect()->back()->with('success', 'Controlled drug loss report was already submitted.');
            }

            return response()->json($this->withMedicationSync(
                $cached,
                $validated,
                'duplicate',
                true,
                'This controlled drug loss request was already processed.',
            ));
        }

        $validated['discovered_by'] = $actor->id;
        $validated['discovered_at'] = now();

        if (! empty($validated['reported_to_police'])) {
            $validated['police_reported_at'] = now();
        }

        if (! empty($validated['reported_to_pharmacy'])) {
            $validated['pharmacy_notified_at'] = now();
        }

        if (! empty($validated['reported_to_regulator'])) {
            $validated['regulator_notified_at'] = now();
        }

        $report = ControlledDrugLossReport::create($validated);
        app(MedicationIncidentIntegrationService::class)
            ->handleControlledLossReport($report, $actor->id, [
                'client_request_uuid' => $validated['client_request_uuid'] ?? null,
                'captured_offline_at' => $validated['captured_offline_at'] ?? null,
                'origin_device_id' => $validated['origin_device_id'] ?? null,
                'queued_offline' => (bool) ($validated['queued_offline'] ?? false),
            ]);
        $report = $report->fresh() ?? $report;

        AuditLogger::logOrFail('medications.controlled.loss.report', $report, [
            'actor_id' => $actor->id,
            'client_id' => $client->id,
            'client_medication_id' => $medication?->id,
            'controlled_drug_loss_report_id' => $report->id,
            'incident_id' => $report->incident_id,
            'client_request_uuid' => $validated['client_request_uuid'] ?? null,
            'captured_offline_at' => $validated['captured_offline_at'] ?? null,
            'origin_device_id' => $validated['origin_device_id'] ?? null,
            'queued_offline' => (bool) ($validated['queued_offline'] ?? false),
        ]);

        $payload = [
            'success' => true,
            'report' => [
                'id' => $report->id,
                'client_id' => $report->client_id,
                'client_medication_id' => $report->client_medication_id,
                'medication_name' => $report->medication_name,
                'quantity_lost' => $report->quantity_lost,
                'unit' => $report->unit,
                'investigation_status' => $report->investigation_status,
                'discovered_at' => $report->discovered_at?->toIso8601String(),
            ],
        ];

        if ($this->medicationSyncRequested($validated)) {
            $payload = $this->governanceScope->rememberIdempotencyResult(
                $scope,
                $validated,
                $this->withMedicationSync(
                    $payload,
                    $validated,
                    $this->medicationProcessedStatus($validated),
                ),
                $requestFingerprint,
                self::REPLAY_CONFLICT,
                durable: true,
            );
        }

        if ($expectsJson) {
            return response()->json($payload);
        }

        return redirect()->back()->with('success', 'Controlled drug loss report submitted.');
    }

    /**
     * Bind an offline retry to the accepted reporter, supplied target, and
     * complete persisted report semantics. Canonical ownership reconciliation
     * remains the responsibility of the medication scope workflow.
     */
    private function lossReportSyncBinding(array $validated, int $actorId): array
    {
        $semanticInputs = [
            'quantity_lost' => $validated['quantity_lost'],
            'unit' => Str::lower(trim((string) ($validated['unit'] ?? 'tablets'))),
            'circumstances' => (string) $validated['circumstances'],
            'immediate_action_taken' => (string) $validated['immediate_action_taken'],
            'accountable_officer_name' => trim((string) ($validated['accountable_officer_name'] ?? '')),
            'reported_to_police' => (bool) ($validated['reported_to_police'] ?? false),
            'police_reference' => trim((string) ($validated['police_reference'] ?? '')),
            'reported_to_pharmacy' => (bool) ($validated['reported_to_pharmacy'] ?? false),
            'pharmacy_name' => trim((string) ($validated['pharmacy_name'] ?? '')),
            'reported_to_regulator' => (bool) ($validated['reported_to_regulator'] ?? false),
            'regulator_name' => trim((string) ($validated['regulator_name'] ?? '')),
            'regulator_reference' => trim((string) ($validated['regulator_reference'] ?? '')),
        ];

        return [
            'version' => 1,
            'operation' => 'controlled_loss_report',
            'actor_id' => $actorId,
            'client_id' => isset($validated['client_id']) ? (int) $validated['client_id'] : null,
            'client_medication_id' => isset($validated['client_medication_id'])
                ? (int) $validated['client_medication_id']
                : null,
            'medication_name' => Str::lower(trim((string) $validated['medication_name'])),
            'captured_offline_at' => $validated['captured_offline_at'] ?? null,
            'origin_device_id' => $validated['origin_device_id'] ?? null,
            'queued_offline' => (bool) ($validated['queued_offline'] ?? false),
            'request_fingerprint' => hash(
                'sha256',
                json_encode($semanticInputs, JSON_THROW_ON_ERROR),
            ),
        ];
    }

    public function investigate(Request $request, ControlledDrugLossReport $report)
    {
        $actor = $request->user();
        abort_unless($actor?->canDo('medications.controlled.record'), 403);

        return $this->withCanonicalReport($actor, $report, function (ControlledDrugLossReport $lockedReport) use ($request) {
            abort_unless(in_array($lockedReport->investigation_status, ['reported', 'investigating'], true), 409);
            $validated = $request->validate([
                'investigation_notes' => ['required', 'string'],
            ]);

            $lockedReport->update([
                'investigation_status' => 'investigating',
                'investigation_notes' => $validated['investigation_notes'],
            ]);

            return redirect()->back()->with('success', 'Investigation notes updated.');
        });
    }

    public function resolve(Request $request, ControlledDrugLossReport $report)
    {
        $actor = $request->user();
        abort_unless($actor?->canDo('medications.controlled.record'), 403);

        return $this->withCanonicalReport($actor, $report, function (ControlledDrugLossReport $lockedReport) use ($request, $actor) {
            abort_unless(in_array($lockedReport->investigation_status, ['reported', 'investigating'], true), 409);
            $validated = $request->validate([
                'resolution_outcome' => ['required', 'string'],
            ]);

            $lockedReport->update([
                'investigation_status' => 'resolved',
                'resolution_outcome' => $validated['resolution_outcome'],
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ]);

            app(MedicationIncidentIntegrationService::class)->resolveControlledLossReport(
                $lockedReport,
                'Controlled drug loss report resolved.',
                $actor->id
            );

            return redirect()->back()->with('success', 'Loss report resolved.');
        });
    }

    private function withCanonicalReport(
        User $actor,
        ControlledDrugLossReport $submittedReport,
        Closure $callback,
    ): mixed {
        $clientId = (int) $submittedReport->client_id;
        abort_unless($clientId > 0, 404);

        if ($submittedReport->client_medication_id !== null) {
            return $this->governanceScope->forMedication(
                $actor,
                (int) $submittedReport->client_medication_id,
                MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
                function (Client $client, ClientMedication $medication) use ($submittedReport, $callback) {
                    abort_unless($medication->controlled_drug, 404);
                    $report = ControlledDrugLossReport::query()
                        ->whereKey($submittedReport->getKey())
                        ->where('client_id', $client->id)
                        ->where('client_medication_id', $medication->id)
                        ->lockForUpdate()
                        ->first();
                    abort_unless($report !== null, 404);

                    return $callback($report);
                },
                expectedClientId: $clientId,
            );
        }

        return $this->governanceScope->forClient(
            $actor,
            $clientId,
            MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
            function (Client $client) use ($submittedReport, $callback) {
                $report = ControlledDrugLossReport::query()
                    ->whereKey($submittedReport->getKey())
                    ->where('client_id', $client->id)
                    ->whereNull('client_medication_id')
                    ->lockForUpdate()
                    ->first();
                abort_unless($report !== null, 404);

                return $callback($report);
            },
        );
    }
}
