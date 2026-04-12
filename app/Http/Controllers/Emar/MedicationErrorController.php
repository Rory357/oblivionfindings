<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\MedicationError;
use App\Models\MedicationMarAttachment;
use App\Models\User;
use App\Services\MedicationIncidentIntegrationService;
use App\Services\Medication\MedicationSignalService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MedicationErrorController extends Controller
{
    private function serializeAttachment(MedicationMarAttachment $attachment, Request $request): array
    {
        return [
            'id' => $attachment->id,
            'file_name' => $attachment->file_name,
            'mime_type' => $attachment->mime_type,
            'file_size' => $attachment->file_size,
            'formatted_size' => $attachment->formatted_size,
            'description' => $attachment->description,
            'uploaded_at' => $attachment->created_at?->toIso8601String(),
            'uploaded_by' => $attachment->uploadedBy?->name,
            'download_url' => route('api.medications.supporting_attachments.download', [
                'client' => $attachment->client_id,
                'attachment' => $attachment->id,
            ]),
            'can_delete' => $request->user()->canDo('medications.administer.correct')
                || $request->user()->canDo('clients.update')
                || (int) $attachment->uploaded_by === (int) $request->user()->id,
        ];
    }

    private function serializeError(MedicationError $error, Request $request): array
    {
        return [
            'id' => $error->id,
            'error_type' => $error->error_type,
            'severity' => $error->severity,
            'description' => $error->description,
            'immediate_action' => $error->immediate_action,
            'contributing_factors' => $error->contributing_factors,
            'review_notes' => $error->review_notes,
            'outcome' => $error->outcome,
            'preventive_actions' => $error->preventive_actions,
            'status' => $error->status,
            'reported_at' => $error->reported_at?->toIso8601String(),
            'reviewed_at' => $error->reviewed_at?->toIso8601String(),
            'client' => $error->client ? [
                'id' => $error->client->id,
                'first_name' => $error->client->first_name,
                'last_name' => $error->client->last_name,
            ] : null,
            'medication' => $error->medication ? [
                'id' => $error->medication->id,
                'name' => $error->medication->name,
            ] : null,
            'reported_by_user' => $error->reportedBy ? [
                'id' => $error->reportedBy->id,
                'name' => $error->reportedBy->name,
            ] : null,
            'reviewed_by_user' => $error->reviewedBy ? [
                'id' => $error->reviewedBy->id,
                'name' => $error->reviewedBy->name,
            ] : null,
            'attachments' => $error->attachments
                ->map(fn (MedicationMarAttachment $attachment) => $this->serializeAttachment($attachment, $request))
                ->values()
                ->all(),
        ];
    }

    public function index(Request $request)
    {
        $query = MedicationError::with([
            'client',
            'medication',
            'reportedBy',
            'reviewedBy',
            'attachments.uploadedBy:id,name',
        ]);

        // Filters
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }
        if ($request->filled('error_type')) {
            $query->where('error_type', $request->error_type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->where('reported_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('reported_at', '<=', $request->date_to . ' 23:59:59');
        }

        // Tab filtering
        if ($request->tab === 'open') {
            $query->open();
        } elseif ($request->tab === 'critical') {
            $query->critical();
        } elseif ($request->tab === 'resolved') {
            $query->whereIn('status', ['resolved', 'closed']);
        }

        $errors = $query->orderByDesc('reported_at')
            ->paginate(20)
            ->through(fn (MedicationError $error) => $this->serializeError($error, $request))
            ->withQueryString();

        // Stats
        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();

        $totalOpen = MedicationError::open()->count();
        $totalCritical = MedicationError::critical()->open()->count();
        $thisMonth = MedicationError::where('reported_at', '>=', $startOfMonth)->count();
        $resolvedThisMonth = MedicationError::whereIn('status', ['resolved', 'closed'])
            ->where('updated_at', '>=', $startOfMonth)
            ->count();

        return Inertia::render('emar/MedicationErrors', [
            'errors' => $errors,
            'stats' => [
                'total_open' => $totalOpen,
                'critical' => $totalCritical,
                'this_month' => $thisMonth,
                'resolved_this_month' => $resolvedThisMonth,
            ],
            'clients' => Client::orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'staff' => User::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['client_id', 'severity', 'error_type', 'status', 'date_from', 'date_to', 'tab']),
            'can' => [
                'manage_evidence' => $request->user()->canDo('medications.administer.record')
                    || $request->user()->canDo('medications.administer.correct')
                    || $request->user()->canDo('clients.update'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'client_medication_id' => 'nullable|exists:client_medications,id',
            'error_type' => 'required|in:wrong_medication,wrong_client,wrong_dose,wrong_time,wrong_route,omission,unauthorised,documentation,other',
            'severity' => 'required|in:near_miss,minor,moderate,major,critical',
            'description' => 'required|string|max:5000',
            'immediate_action' => 'nullable|string|max:5000',
            'contributing_factors' => 'nullable|string|max:5000',
            'create_incident' => 'nullable|boolean',
        ]);

        $validated['reported_by'] = $request->user()->id;
        $validated['reported_at'] = now();
        $validated['status'] = 'reported';

        // Optionally create a linked incident
        $incidentId = null;
        if ($request->boolean('create_incident')) {
            $incident = ClientIncident::create([
                'client_id' => $validated['client_id'],
                'title' => 'Medication Error: ' . str_replace('_', ' ', $validated['error_type']),
                'description' => $validated['description'],
                'occurred_at' => now(),
                'reported_by' => $request->user()->id,
                'severity' => match ($validated['severity']) {
                    'critical' => 'critical',
                    'major' => 'high',
                    'moderate' => 'medium',
                    default => 'low',
                },
                'status' => 'submitted',
                'submitted_at' => now(),
                'type' => 'medication_error',
            ]);
            $incidentId = $incident->id;
        }

        unset($validated['create_incident']);
        $validated['client_incident_id'] = $incidentId;

        $error = MedicationError::create($validated);

        // Emit canonical signal for major/critical medication errors → Control Room
        app(MedicationSignalService::class)->emitError($error);

        return redirect()->back()->with('success', 'Medication error reported successfully.');
    }

    public function update(Request $request, MedicationError $error)
    {
        $validated = $request->validate([
            'error_type' => 'sometimes|in:wrong_medication,wrong_client,wrong_dose,wrong_time,wrong_route,omission,unauthorised,documentation,other',
            'severity' => 'sometimes|in:near_miss,minor,moderate,major,critical',
            'description' => 'sometimes|string|max:5000',
            'immediate_action' => 'nullable|string|max:5000',
            'contributing_factors' => 'nullable|string|max:5000',
            'status' => 'sometimes|in:reported,investigating,resolved,closed',
        ]);

        $error->update($validated);

        return redirect()->back()->with('success', 'Medication error updated successfully.');
    }

    public function review(Request $request, MedicationError $error)
    {
        $validated = $request->validate([
            'review_notes' => 'required|string|max:5000',
            'status' => 'sometimes|in:reported,investigating,resolved,closed',
        ]);

        $error->update([
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => $validated['review_notes'],
            'status' => $validated['status'] ?? 'investigating',
        ]);

        return redirect()->back()->with('success', 'Error reviewed successfully.');
    }

    public function resolve(Request $request, MedicationError $error)
    {
        $validated = $request->validate([
            'outcome' => 'required|string|max:5000',
            'preventive_actions' => 'required|string|max:5000',
        ]);

        $error->update([
            'outcome' => $validated['outcome'],
            'preventive_actions' => $validated['preventive_actions'],
            'status' => 'resolved',
            'reviewed_by' => $error->reviewed_by ?? $request->user()->id,
            'reviewed_at' => $error->reviewed_at ?? now(),
        ]);

        app(MedicationIncidentIntegrationService::class)->resolveMedicationError(
            $error,
            'Medication error resolved.',
            $request->user()->id
        );

        return redirect()->back()->with('success', 'Error resolved successfully.');
    }
}
