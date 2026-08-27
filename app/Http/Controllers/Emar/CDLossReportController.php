<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Concerns\HandlesMedicationSync;
use App\Http\Controllers\Controller;
use App\Models\ControlledDrugLossReport;
use App\Services\MedicationIncidentIntegrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CDLossReportController extends Controller
{
    use HandlesMedicationSync;

    public function index(Request $request)
    {
        abort_unless($request->user()?->canDo('medications.view'), 403);
        abort_unless($request->user()?->canDo('medications.controlled.view'), 403);

        $reports = ControlledDrugLossReport::with([
            'client:id,first_name,last_name',
            'discoveredBy:id,name',
        ])
            ->latest()
            ->get();

        return response()->json($reports);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()?->canDo('medications.controlled.record'), 403);

        $validated = $request->validate([
            'client_id' => ['nullable', 'exists:clients,id'],
            'client_medication_id' => ['nullable', 'exists:client_medications,id'],
            'medication_name' => ['required', 'string', 'max:255'],
            'quantity_lost' => ['required', 'numeric', 'min:0.01'],
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
            'client_request_uuid' => ['nullable', 'uuid'],
            'captured_offline_at' => ['nullable', 'date'],
            'origin_device_id' => ['nullable', 'string', 'max:255'],
            'queued_offline' => ['nullable', 'boolean'],
        ]);

        $scope = 'emar-controlled-loss-report';
        $idempotencyBinding = $this->lossReportSyncBinding(
            $validated,
            (int) $request->user()->id,
        );

        if (
            $this->medicationSyncRequested($validated)
            && ($cached = $this->getCachedMedicationSyncResponse($scope, $validated))
        ) {
            if (data_get($cached, 'idempotency_binding') !== $idempotencyBinding) {
                return response()->json(
                    $this->buildMedicationConflictPayload(
                        $validated,
                        'This controlled drug loss request was already used for a different reporter, target, or report. Please submit it again with a new request identifier.',
                    ),
                    409,
                );
            }

            return response()->json($cached);
        }

        $validated['discovered_by'] = $request->user()->id;
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

        $report = DB::transaction(function () use ($request, $validated): ControlledDrugLossReport {
            $report = ControlledDrugLossReport::create($validated);
            app(MedicationIncidentIntegrationService::class)
                ->handleControlledLossReport($report, $request->user()->id);

            return $report->fresh() ?? $report;
        }, 3);

        $payload = [
            'success' => true,
            'idempotency_binding' => $idempotencyBinding,
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
            return response()->json(
                $this->rememberMedicationSyncResponse(
                    $scope,
                    $validated,
                    $this->withMedicationSync(
                        $payload,
                        $validated,
                        $this->medicationProcessedStatus($validated),
                    ),
                ),
            );
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
            'quantity_lost' => number_format((float) $validated['quantity_lost'], 2, '.', ''),
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
            'request_fingerprint' => hash(
                'sha256',
                json_encode($semanticInputs, JSON_THROW_ON_ERROR),
            ),
        ];
    }

    public function investigate(Request $request, ControlledDrugLossReport $report)
    {
        abort_unless($request->user()?->canDo('medications.controlled.record'), 403);

        $validated = $request->validate([
            'investigation_notes' => ['required', 'string'],
        ]);

        $report->update([
            'investigation_status' => 'investigating',
            'investigation_notes' => $validated['investigation_notes'],
        ]);

        return redirect()->back()->with('success', 'Investigation notes updated.');
    }

    public function resolve(Request $request, ControlledDrugLossReport $report)
    {
        abort_unless($request->user()?->canDo('medications.controlled.record'), 403);

        $validated = $request->validate([
            'resolution_outcome' => ['required', 'string'],
        ]);

        $report->update([
            'investigation_status' => 'resolved',
            'resolution_outcome' => $validated['resolution_outcome'],
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        app(MedicationIncidentIntegrationService::class)->resolveControlledLossReport(
            $report,
            'Controlled drug loss report resolved.',
            $request->user()->id
        );

        return redirect()->back()->with('success', 'Loss report resolved.');
    }
}
