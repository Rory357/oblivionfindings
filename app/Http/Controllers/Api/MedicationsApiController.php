<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationAllergy;
use App\Models\MedicationDashboardAlert;
use App\Services\EnhancedMarService;
use App\Services\MedicationAlertService;
use App\Services\MedicationIncidentIntegrationService;
use App\Services\MedicationReportingService;
use App\Services\MedicationSafetyService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MedicationsApiController extends Controller
{
    public function __construct(
        protected EnhancedMarService $marService,
        protected MedicationSafetyService $safetyService,
        protected MedicationReportingService $reportingService,
        protected MedicationIncidentIntegrationService $incidentService,
        protected MedicationAlertService $alertService,
    ) {}

    /**
     * Get enhanced MAR data
     */
    public function getMar(Request $request, Client $client)
    {
        $this->authorize('viewMedications', $client);

        $date = $request->input('date') 
            ? Carbon::parse($request->input('date')) 
            : now();

        $activeShiftId = $request->input('shift_id');

        $marData = $this->marService->build($client, $date, now(), $activeShiftId);

        // Add permissions
        $user = $request->user();
        $marData['can'] = [
            'record' => $user->canDo('medications.administer.record') || $user->canDo('clients.update'),
            'correct' => $user->canDo('medications.administer.correct') || $user->canDo('clients.update'),
            'witness' => $user->canDo('medications.controlled.witness'),
        ];

        // Add active alerts for this client
        $marData['alerts'] = MedicationDashboardAlert::where('client_id', $client->id)
            ->where('status', 'active')
            ->orderByDesc('severity')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'alert_type' => $a->alert_type,
                'severity' => $a->severity,
                'message' => $a->message,
                'created_at' => $a->created_at?->toIso8601String(),
            ]);

        // Add controlled drug discrepancies
        $marData['controlled_discrepancies'] = \App\Models\ClientControlledDrugDiscrepancy::where('client_id', $client->id)
            ->where('status', 'open')
            ->with('medication:id,name')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'medication_name' => $d->medication?->name,
                'difference' => $d->difference,
                'reason' => $d->reason,
                'reported_at' => $d->reported_at?->toIso8601String(),
            ]);

        return response()->json($marData);
    }

    /**
     * Perform safety check
     */
    public function safetyCheck(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorize('viewMedications', $client);

        $check = $this->safetyService->performSafetyCheck($client, $medication);

        return response()->json($check);
    }

    /**
     * Get PRN history
     */
    public function getPrnHistory(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorize('viewMedications', $client);

        $hours = $request->input('hours', 24);
        $history = $this->safetyService->getPrnHistory($medication, $hours);

        return response()->json($history);
    }

    /**
     * Record medication administration
     */
    public function recordAdministration(
        Request $request,
        Client $client,
        ClientMedication $medication
    ) {
        $this->authorize('viewMedications', $client);
        $user = $request->user();
        
        abort_unless(
            $user->canDo('medications.administer.record') || $user->canDo('clients.update'),
            403,
            'You do not have permission to record medication administrations.'
        );

        $data = $request->validate([
            'status' => ['required', 'in:given,refused,missed,withheld'],
            'reason' => ['nullable', 'string', 'max:500'],
            'dose_given' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'scheduled_for' => ['nullable', 'date'],
            'administered_at' => ['nullable', 'date'],
            'witnessed_by' => ['nullable', 'integer', 'exists:users,id'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'outcome' => ['nullable', 'string', 'in:effective,ineffective,adverse_reaction'],
            'site' => ['nullable', 'string', 'max:100'],
            'override_safety' => ['nullable', 'boolean'],
            'override_window' => ['nullable', 'boolean'],
        ]);

        // Require reason for non-given
        if ($data['status'] !== 'given' && empty($data['reason'])) {
            return response()->json([
                'success' => false,
                'error' => 'Reason is required when medication is not given.',
            ], 422);
        }

        // Require reason for PRN given
        if ($medication->is_prn && $data['status'] === 'given' && empty($data['reason'])) {
            return response()->json([
                'success' => false,
                'error' => 'PRN indication (reason) is required for as-needed medication.',
            ], 422);
        }

        // Controlled drug witness validation
        if ($medication->requiresWitness() && $data['status'] === 'given') {
            if (empty($data['witnessed_by'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Witness is required for this medication.',
                ], 422);
            }

            if ($data['witnessed_by'] === $user->id) {
                return response()->json([
                    'success' => false,
                    'error' => 'Witness must be a different user.',
                ], 422);
            }

            $witness = \App\Models\User::find($data['witnessed_by']);
            if (!$witness || !$witness->canDo('medications.controlled.witness')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Selected witness is not authorized to witness controlled drug administrations.',
                ], 422);
            }
        }

        $result = $this->marService->recordAdministration(
            $client,
            $medication,
            $data,
            $user->id,
            $data['shift_id'] ?? null
        );

        if (!$result['success']) {
            return response()->json($result, 422);
        }

        $administration = $result['administration'];

        // Handle incident creation for specific outcomes
        if ($data['status'] === 'missed') {
            $this->incidentService->handleMissedDose($administration, $user->id);
        } elseif ($data['status'] === 'refused' && ($medication->high_risk || $medication->controlled_drug)) {
            $this->incidentService->handleRefusedDose($administration);
        }

        // Handle late dose incident
        if ($administration->late_minutes && $administration->late_minutes > 120) {
            $this->incidentService->handleLateDose($administration, $administration->late_minutes);
        }

        // Generate fresh alerts
        $this->alertService->generateClientAlerts($client);

        return response()->json([
            'success' => true,
            'administration' => [
                'id' => $administration->id,
                'status' => $administration->status,
                'administered_at' => $administration->administered_at?->toIso8601String(),
            ],
            'safety_check' => $result['safety_check'] ?? null,
        ]);
    }

    /**
     * Correct an administration
     */
    public function correctAdministration(
        Request $request,
        Client $client,
        ClientMedicationAdministration $administration
    ) {
        $this->authorize('viewMedications', $client);
        $user = $request->user();

        abort_unless(
            $user->canDo('medications.administer.correct') || $user->canDo('clients.update'),
            403,
            'You do not have permission to correct medication administrations.'
        );

        abort_unless($administration->client_id === $client->id, 404);

        $data = $request->validate([
            'status' => ['required', 'in:given,refused,missed,withheld'],
            'reason' => ['nullable', 'string', 'max:500'],
            'dose_given' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'administered_at' => ['nullable', 'date'],
            'correction_reason' => ['required', 'string', 'max:500'],
        ]);

        // Check correction time window
        $minutesSince = $administration->created_at->diffInMinutes(now());
        if ($minutesSince > 30 && empty($data['correction_reason'])) {
            return response()->json([
                'success' => false,
                'error' => 'Correction reason is required when correcting after 30 minutes.',
            ], 422);
        }

        // Create correction record
        $correction = $administration->replicate([
            'id',
            'created_at',
            'updated_at',
        ]);
        $correction->is_correction = true;
        $correction->corrected_of_id = $administration->id;
        $correction->correction_reason = $data['correction_reason'];
        $correction->status = $data['status'];
        $correction->reason = $data['reason'] ?? $administration->reason;
        $correction->dose_given = $data['dose_given'] ?? $administration->dose_given;
        $correction->notes = $data['notes'] ?? $administration->notes;
        $correction->administered_at = $data['administered_at'] ?? $administration->administered_at;
        $correction->administered_by = $user->id;
        $correction->save();

        // Handle incident for significant corrections
        if ($minutesSince > 240) { // 4 hours
            $this->incidentService->handleUnsafeCorrection($administration, $data, $user->id);
        }

        return response()->json([
            'success' => true,
            'correction' => [
                'id' => $correction->id,
                'status' => $correction->status,
                'is_correction' => true,
            ],
        ]);
    }

    /**
     * Get dashboard alerts
     */
    public function getDashboardAlerts(Request $request, ?Client $client = null)
    {
        $user = $request->user();

        if ($client) {
            $this->authorize('viewMedications', $client);
            $alerts = MedicationDashboardAlert::forClient($client->id)
                ->active()
                ->with(['medication:id,name', 'client:id,first_name,last_name'])
                ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")
                ->orderByDesc('created_at')
                ->get();
        } else {
            // Global alerts - check permissions
            abort_unless(
                $user->canDo('medications.view') || $user->canDo('clients.viewAny'),
                403
            );
            
            $alerts = MedicationDashboardAlert::active()
                ->with(['medication:id,name', 'client:id,first_name,last_name'])
                ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();
        }

        return response()->json([
            'alerts' => $alerts->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->alert_type,
                'type_label' => $a->alertTypeLabel,
                'severity' => $a->severity,
                'severity_info' => $a->severityInfo,
                'message' => $a->message,
                'client' => $a->client ? [
                    'id' => $a->client->id,
                    'name' => trim("{$a->client->first_name} {$a->client->last_name}"),
                ] : null,
                'medication' => $a->medication ? [
                    'id' => $a->medication->id,
                    'name' => $a->medication->name,
                ] : null,
                'created_at' => $a->created_at?->toIso8601String(),
                'status' => $a->status,
            ]),
        ]);
    }

    /**
     * Acknowledge alert
     */
    public function acknowledgeAlert(Request $request, int $alertId)
    {
        $user = $request->user();

        $alert = MedicationDashboardAlert::findOrFail($alertId);

        // Check if user can acknowledge for this client
        if (!$user->canDo('clients.viewAny')) {
            $clientIds = $user->assignedClients()->pluck('clients.id')->toArray();
            if (!in_array($alert->client_id, $clientIds)) {
                abort(403, 'You can only acknowledge alerts for your assigned clients.');
            }
        }

        $success = $this->alertService->acknowledgeAlert($alertId, $user->id);

        return response()->json([
            'success' => $success,
        ]);
    }

    /**
     * Resolve alert
     */
    public function resolveAlert(Request $request, int $alertId)
    {
        $user = $request->user();

        abort_unless(
            $user->canDo('medications.administer.correct') || $user->canDo('clients.update'),
            403
        );

        $alert = MedicationDashboardAlert::findOrFail($alertId);

        $data = $request->validate([
            'resolution_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $success = $this->alertService->resolveAlert($alertId, $data['resolution_notes'] ?? null);

        return response()->json([
            'success' => $success,
        ]);
    }

    /**
     * Get dashboard widgets
     */
    public function getDashboardWidgets(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user->canDo('medications.view') || $user->canDo('clients.viewAny'),
            403
        );

        $clientId = $request->input('client_id');

        // If not global view, restrict to assigned clients
        if (!$user->canDo('clients.viewAny') && $clientId) {
            $assignedIds = $user->assignedClients()->pluck('clients.id')->toArray();
            if (!in_array((int) $clientId, $assignedIds)) {
                abort(403);
            }
        }

        $widgets = $this->alertService->getGlobalDashboardWidgets($clientId);

        return response()->json($widgets);
    }

    /**
     * Get medication reports
     */
    public function getReports(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user->canDo('medications.reports.export') || $user->canDo('reports.viewAny'),
            403
        );

        $reportType = $request->input('type', 'mar');
        $clientId = $request->input('client_id');
        $dateFrom = $request->input('date_from') ? Carbon::parse($request->input('date_from')) : null;
        $dateTo = $request->input('date_to') ? Carbon::parse($request->input('date_to')) : null;

        $report = match ($reportType) {
            'mar' => $this->reportingService->exportMar($clientId, $dateFrom, $dateTo),
            'prn' => $this->reportingService->reportPrnUsage($clientId, $dateFrom, $dateTo),
            'missed' => $this->reportingService->reportMissedDoses($clientId, $dateFrom, $dateTo),
            'late' => $this->reportingService->reportLateDoses($clientId, $dateFrom, $dateTo),
            'controlled_balance' => $this->reportingService->reportControlledDrugBalance($clientId),
            'controlled_discrepancies' => $this->reportingService->reportControlledDiscrepancies($clientId, null, $dateFrom, $dateTo),
            'changes' => $this->reportingService->reportMedicationChanges($clientId, null, $dateFrom, $dateTo),
            'incidents' => $this->reportingService->reportMedicationIncidents($clientId, $dateFrom, $dateTo),
            'audit' => $this->reportingService->generateAuditReport($clientId, $dateFrom, $dateTo),
            default => throw new \InvalidArgumentException('Invalid report type'),
        };

        return response()->json($report);
    }

    /**
     * Export report to CSV
     */
    public function exportReportCsv(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user->canDo('medications.reports.export') || $user->canDo('reports.viewAny'),
            403
        );

        $reportType = $request->input('type', 'mar');
        $clientId = $request->input('client_id');
        $dateFrom = $request->input('date_from') ? Carbon::parse($request->input('date_from')) : null;
        $dateTo = $request->input('date_to') ? Carbon::parse($request->input('date_to')) : null;

        $report = match ($reportType) {
            'mar' => $this->reportingService->exportMar($clientId, $dateFrom, $dateTo),
            'prn' => $this->reportingService->reportPrnUsage($clientId, $dateFrom, $dateTo),
            'missed' => $this->reportingService->reportMissedDoses($clientId, $dateFrom, $dateTo),
            'late' => $this->reportingService->reportLateDoses($clientId, $dateFrom, $dateTo),
            'controlled_discrepancies' => $this->reportingService->reportControlledDiscrepancies($clientId, null, $dateFrom, $dateTo),
            'changes' => $this->reportingService->reportMedicationChanges($clientId, null, $dateFrom, $dateTo),
            default => throw new \InvalidArgumentException('Invalid report type'),
        };

        $filename = "medication_report_{$reportType}_" . now()->format('Ymd_His') . '.csv';

        return $this->reportingService->exportToCsv($report, $filename);
    }

    /**
     * Get shift summary
     */
    public function getShiftSummary(Request $request, int $shiftId)
    {
        $user = $request->user();

        $shift = \App\Models\Shift::findOrFail($shiftId);

        // Check permissions
        if (!$user->canDo('shifts.viewAny')) {
            if ($shift->staff_id !== $user->id) {
                abort(403, 'You can only view summaries for your own shifts.');
            }
        }

        $summary = $this->marService->getShiftSummary($shiftId);

        return response()->json($summary);
    }

    /**
     * Get medication allergies
     */
    public function getAllergies(Request $request, Client $client)
    {
        $this->authorize('viewMedications', $client);

        $allergies = MedicationAllergy::where('client_id', $client->id)
            ->whereNull('deleted_at')
            ->with('recordedBy:id,name')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'allergies' => $allergies->map(fn ($a) => [
                'id' => $a->id,
                'allergen' => $a->allergen,
                'reaction' => $a->reaction,
                'severity' => $a->severity,
                'is_severe' => $a->isSevere(),
                'notes' => $a->notes,
                'identified_date' => $a->identified_date?->toDateString(),
                'recorded_by' => $a->recordedBy?->name,
            ]),
        ]);
    }

    /**
     * Create allergy
     */
    public function createAllergy(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'allergen' => ['required', 'string', 'max:255'],
            'reaction' => ['nullable', 'string', 'max:500'],
            'severity' => ['nullable', 'in:mild,moderate,severe,life_threatening'],
            'notes' => ['nullable', 'string'],
            'identified_date' => ['nullable', 'date'],
        ]);

        $allergy = MedicationAllergy::create([
            'client_id' => $client->id,
            'allergen' => $data['allergen'],
            'reaction' => $data['reaction'] ?? null,
            'severity' => $data['severity'] ?? null,
            'notes' => $data['notes'] ?? null,
            'identified_date' => $data['identified_date'] ?? null,
            'recorded_by' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'allergy' => [
                'id' => $allergy->id,
                'allergen' => $allergy->allergen,
                'severity' => $allergy->severity,
            ],
        ]);
    }

    /**
     * Get medication version history
     */
    public function getMedicationVersions(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorize('viewMedications', $client);
        abort_unless($medication->client_id === $client->id, 404);

        $versions = \App\Models\MedicationOrderVersion::where('client_medication_id', $medication->id)
            ->orderByDesc('version_number')
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'name' => $v->name,
                'dosage' => $v->dosage,
                'frequency' => $v->frequency,
                'route' => $v->route,
                'instructions' => $v->instructions,
                'state' => $v->state,
                'change_reason' => $v->change_reason,
                'changed_by' => $v->changedBy?->name ?? 'System',
                'changed_at' => $v->changed_at?->toIso8601String(),
            ]);

        return response()->json([
            'medication' => [
                'id' => $medication->id,
                'name' => $medication->name,
                'current_version' => $medication->version ?? 1,
            ],
            'versions' => $versions,
        ]);
    }

    /**
     * Get scheduled stock counts for a medication
     */
    public function getScheduledStockCounts(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorize('viewMedications', $client);
        abort_unless($medication->client_id === $client->id, 404);

        $counts = \App\Models\MedicationScheduledStockCount::where('client_medication_id', $medication->id)
            ->orderByDesc('scheduled_date')
            ->orderByDesc('scheduled_time')
            ->limit(20)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'scheduled_date' => $c->scheduled_date?->toDateString(),
                'scheduled_time' => $c->scheduled_time?->format('H:i'),
                'status' => $c->status,
                'expected_quantity' => $c->expected_quantity,
                'actual_quantity' => $c->actual_quantity,
                'discrepancy' => $c->discrepancy,
                'notes' => $c->notes,
                'completed_by' => $c->completedBy?->name,
                'witnessed_by' => $c->witnessedBy?->name,
                'completed_at' => $c->completed_at?->toIso8601String(),
                'is_overdue' => $c->isOverdue(),
            ]);

        return response()->json([
            'medication' => [
                'id' => $medication->id,
                'name' => $medication->name,
                'on_hand' => $medication->stock?->on_hand,
            ],
            'counts' => $counts,
        ]);
    }

    /**
     * Create a scheduled stock count
     */
    public function createScheduledStockCount(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorize('viewMedications', $client);
        abort_unless($medication->client_id === $client->id, 404);

        $user = $request->user();
        abort_unless($user->canDo('medications.stock.update') || $user->canDo('clients.update'), 403);

        $data = $request->validate([
            'scheduled_date' => ['required', 'date'],
            'scheduled_time' => ['nullable', 'date_format:H:i'],
            'expected_quantity' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $count = \App\Models\MedicationScheduledStockCount::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'scheduled_date' => $data['scheduled_date'],
            'scheduled_time' => $data['scheduled_time'] ? $data['scheduled_date'] . ' ' . $data['scheduled_time'] : null,
            'expected_quantity' => $data['expected_quantity'] ?? $medication->stock?->on_hand,
            'status' => 'pending',
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'count' => [
                'id' => $count->id,
                'scheduled_date' => $count->scheduled_date->toDateString(),
                'status' => $count->status,
            ],
        ]);
    }

    /**
     * Complete a scheduled stock count
     */
    public function completeScheduledStockCount(Request $request, Client $client, \App\Models\MedicationScheduledStockCount $count)
    {
        $this->authorize('viewMedications', $client);
        abort_unless($count->client_id === $client->id, 404);

        $user = $request->user();
        abort_unless($user->canDo('medications.stock.update') || $user->canDo('clients.update'), 403);

        // Controlled drugs require witness
        $medication = $count->medication;
        $requiresWitness = $medication && $medication->controlled_drug;

        $data = $request->validate([
            'actual_quantity' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'witnessed_by' => $requiresWitness ? ['required', 'integer', 'exists:users,id'] : ['nullable'],
        ]);

        if ($requiresWitness && $data['witnessed_by'] === $user->id) {
            return response()->json([
                'success' => false,
                'error' => 'Witness must be a different user.',
            ], 422);
        }

        $count->complete(
            $data['actual_quantity'],
            $data['notes'] ?? null,
            $user->id,
            $data['witnessed_by'] ?? null
        );

        // Update actual stock if different
        if ($medication && $medication->stock && $data['actual_quantity'] !== $medication->stock->on_hand) {
            $medication->stock->on_hand = $data['actual_quantity'];
            $medication->stock->last_counted_at = now();
            $medication->stock->save();
        }

        return response()->json([
            'success' => true,
            'count' => [
                'id' => $count->id,
                'status' => 'completed',
                'discrepancy' => $count->discrepancy,
            ],
        ]);
    }

    /**
     * Get drug interactions list
     */
    public function getDrugInteractions(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canDo('medications.view') || $user->canDo('clients.viewAny'), 403);

        $interactions = \App\Models\MedicationInteraction::active()
            ->orderByRaw("FIELD(severity, 'contraindicated', 'major', 'moderate', 'minor')")
            ->orderBy('medication_a')
            ->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'medication_a' => $i->medication_a,
                'medication_b' => $i->medication_b,
                'severity' => $i->severity,
                'severity_info' => $i->severityInfo,
                'description' => $i->description,
                'clinical_effects' => $i->clinical_effects,
                'management' => $i->management,
                'active' => $i->active,
            ]);

        return response()->json([
            'interactions' => $interactions,
        ]);
    }

    /**
     * Create drug interaction
     */
    public function createDrugInteraction(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canDo('medications.administer.correct') || $user->canDo('clients.update'), 403);

        $data = $request->validate([
            'medication_a' => ['required', 'string', 'max:255'],
            'medication_b' => ['required', 'string', 'max:255'],
            'severity' => ['required', 'in:minor,moderate,major,contraindicated'],
            'description' => ['required', 'string'],
            'clinical_effects' => ['nullable', 'string'],
            'management' => ['nullable', 'string'],
        ]);

        $interaction = \App\Models\MedicationInteraction::create([
            'medication_a' => $data['medication_a'],
            'medication_b' => $data['medication_b'],
            'severity' => $data['severity'],
            'description' => $data['description'],
            'clinical_effects' => $data['clinical_effects'] ?? null,
            'management' => $data['management'] ?? null,
            'active' => true,
        ]);

        return response()->json([
            'success' => true,
            'interaction' => [
                'id' => $interaction->id,
                'medication_a' => $interaction->medication_a,
                'medication_b' => $interaction->medication_b,
                'severity' => $interaction->severity,
            ],
        ]);
    }
}
