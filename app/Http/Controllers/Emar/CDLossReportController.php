<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Concerns\HandlesMedicationSync;
use App\Http\Controllers\Controller;
use App\Models\ControlledDrugLossReport;
use App\Services\MedicationIncidentIntegrationService;
use Illuminate\Http\Request;

class CDLossReportController extends Controller
{
    use HandlesMedicationSync;

    public function index(Request $request)
    {
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
        $validated = $request->validate([
            'client_id' => ['nullable', 'exists:clients,id'],
            'client_medication_id' => ['nullable', 'exists:client_medications,id'],
            'medication_name' => ['required', 'string', 'max:255'],
            'quantity_lost' => ['required', 'numeric', 'min:0.01'],
            'unit' => ['nullable', 'string', 'max:50'],
            'circumstances' => ['required', 'string'],
            'reported_to_police' => ['boolean'],
            'police_reference' => ['nullable', 'string', 'max:100'],
            'reported_to_pharmacy' => ['boolean'],
            'pharmacy_name' => ['nullable', 'string', 'max:255'],
            'client_request_uuid' => ['nullable', 'uuid'],
            'captured_offline_at' => ['nullable', 'date'],
            'origin_device_id' => ['nullable', 'string', 'max:255'],
            'queued_offline' => ['nullable', 'boolean'],
        ]);

        $scope = 'emar-controlled-loss-report';

        if (
            $this->medicationSyncRequested($validated)
            && ($cached = $this->getCachedMedicationSyncResponse($scope, $validated))
        ) {
            return response()->json($cached);
        }

        $validated['discovered_by'] = $request->user()->id;
        $validated['discovered_at'] = now();

        if (!empty($validated['reported_to_police'])) {
            $validated['police_reported_at'] = now();
        }

        if (!empty($validated['reported_to_pharmacy'])) {
            $validated['pharmacy_notified_at'] = now();
        }

        $report = ControlledDrugLossReport::create($validated);

        app(MedicationIncidentIntegrationService::class)
            ->handleControlledLossReport($report, $request->user()->id);

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

    public function investigate(Request $request, ControlledDrugLossReport $report)
    {
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
