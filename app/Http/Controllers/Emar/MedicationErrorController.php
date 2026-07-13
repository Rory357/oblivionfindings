<?php

namespace App\Http\Controllers\Emar;

use App\Domain\Governance\Services\IncidentEscalationService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\MedicationError;
use App\Models\MedicationMarAttachment;
use App\Models\Site;
use App\Models\User;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\Medication\MedicationSignalService;
use App\Services\MedicationIncidentIntegrationService;
use App\Services\Timeline\TimelineEmitter;
use App\Support\EmarUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'ref' => $error->reference_number ?? 'ERR-'.str_pad((string) $error->id, 4, '0', STR_PAD_LEFT),
            'error_type' => $error->error_type,
            'severity' => $error->severity,
            'reached_client' => $error->reached_client,
            'harm_level' => $error->harm_level,
            'open_disclosure' => $error->open_disclosure,
            'description' => $error->description,
            'immediate_action' => $error->immediate_action,
            'contributing_factors' => $error->contributing_factors,
            'review_notes' => $error->review_notes,
            'outcome' => $error->outcome,
            'preventive_actions' => $error->preventive_actions,
            'close_note' => $error->close_note,
            'status' => $error->status,
            'reported_at' => $error->reported_at?->toIso8601String(),
            'reviewed_at' => $error->reviewed_at?->toIso8601String(),
            'closed_at' => $error->closed_at?->toIso8601String(),
            'client_id' => $error->client_id,
            'client' => $error->client ? [
                'id' => $error->client->id,
                'first_name' => $error->client->first_name,
                'last_name' => $error->client->last_name,
            ] : null,
            'site_id' => $error->client?->site_id,
            'site_name' => $error->client?->site?->name,
            'medication' => $error->medication ? [
                'id' => $error->medication->id,
                'name' => $error->medication->name,
            ] : null,
            'incident' => $error->incident ? [
                'id' => $error->incident->id,
                'ref' => $error->incident->reference_number ?? 'INC-'.str_pad((string) $error->incident->id, 4, '0', STR_PAD_LEFT),
            ] : null,
            'mar_url' => $error->client_id ? EmarUrl::mar($error->client_id) : null,
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
        $siteFilter = $request->integer('site_id') ?: null;
        $bySite = fn ($q) => $q->whereHas('client', fn ($c) => $c->where('site_id', $siteFilter));

        // Flat, client-side-filterable register — the redesigned page facets by
        // tab/search/severity/type/reporter with live counts (drops pagination).
        $models = MedicationError::query()
            ->with(['client:id,first_name,last_name,site_id', 'client.site:id,name', 'medication:id,name', 'incident:id,reference_number', 'reportedBy:id,name', 'reviewedBy:id,name', 'attachments.uploadedBy:id,name'])
            ->when($siteFilter, $bySite)
            ->orderByDesc('reported_at')
            ->limit(300)
            ->get();

        $errors = $models->map(fn (MedicationError $error) => $this->serializeError($error, $request))->values();

        // Aggregate stats over the whole (optionally site-scoped) register.
        $statRows = MedicationError::query()
            ->when($siteFilter, $bySite)
            ->get(['id', 'severity', 'error_type', 'status', 'reported_at', 'updated_at']);

        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $last30 = $now->copy()->subDays(30);
        $isOpen = fn ($r) => in_array($r->status, ['reported', 'investigating'], true);

        $trend = collect(range(7, 0))->map(function ($w) use ($statRows, $now) {
            $start = $now->copy()->startOfWeek()->subWeeks($w);
            $end = $start->copy()->endOfWeek();
            $inWeek = $statRows->filter(fn ($r) => $r->reported_at && $r->reported_at->betweenIncluded($start, $end));

            return [
                'week' => $start->format('d M'),
                'count' => $inWeek->count(),
                'near_miss' => $inWeek->where('severity', 'near_miss')->count(),
            ];
        })->values();

        return Inertia::render('emar/MedicationErrors', [
            'errors' => $errors,
            'stats' => [
                'total_open' => $statRows->filter($isOpen)->count(),
                'critical' => $statRows->filter(fn ($r) => $r->severity === 'critical' && $isOpen($r))->count(),
                'this_month' => $statRows->filter(fn ($r) => $r->reported_at && $r->reported_at->gte($startOfMonth))->count(),
                'resolved_this_month' => $statRows->filter(fn ($r) => in_array($r->status, ['resolved', 'closed'], true) && $r->updated_at && $r->updated_at->gte($startOfMonth))->count(),
                'near_miss' => $statRows->filter(fn ($r) => $r->severity === 'near_miss' && $r->reported_at && $r->reported_at->gte($last30))->count(),
                'trend' => $trend,
                'by_type' => $statRows->groupBy('error_type')->map->count()->sortDesc()->take(5),
                'by_severity' => collect(['near_miss', 'minor', 'moderate', 'major', 'critical'])->mapWithKeys(fn ($s) => [$s => $statRows->where('severity', $s)->count()]),
            ],
            'clients' => Client::orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'staff' => User::orderBy('name')->get(['id', 'name']),
            'sites' => Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'active_site' => $siteFilter ? Site::query()->whereKey($siteFilter)->first(['id', 'name']) : null,
            'site_brand_colour' => $siteFilter ? Site::query()->whereKey($siteFilter)->value('brand_colour') : null,
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
            'reached_client' => 'nullable|in:no,yes,unknown',
            'open_disclosure' => 'nullable|in:na,pending,done',
            'description' => 'required|string|max:5000',
            'immediate_action' => 'nullable|string|max:5000',
            'contributing_factors' => 'nullable|string|max:5000',
            'create_incident' => 'nullable|boolean',
        ]);

        $validated['reported_by'] = $request->user()->id;
        $validated['reported_at'] = now();
        $validated['status'] = 'reported';

        DB::transaction(function () use ($request, $validated): void {
            $incident = null;
            if ($request->boolean('create_incident')) {
                $incident = ClientIncident::withoutEvents(
                    fn () => ClientIncident::create([
                        'client_id' => $validated['client_id'],
                        'title' => 'Medication Error: '.str_replace('_', ' ', $validated['error_type']),
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
                    ]),
                );
                app(IncidentJourneyService::class)
                    ->ensureForSubmittedIncident($incident, $request->user());
            }

            $errorAttributes = $validated;
            unset($errorAttributes['create_incident']);
            $errorAttributes['client_incident_id'] = $incident?->id;
            $error = MedicationError::create($errorAttributes);

            app(MedicationSignalService::class)->emitError($error);
        }, 3);

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
            'harm_level' => 'nullable|in:a_c,d_e,f_g,h_i',
        ]);

        $error->update([
            'outcome' => $validated['outcome'],
            'preventive_actions' => $validated['preventive_actions'],
            'harm_level' => $validated['harm_level'] ?? $error->harm_level,
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

    /**
     * Close out a resolved error — the final governance sign-off. Closing keeps
     * the record (SoftDeletes is reserved for retraction); it just marks the
     * lifecycle complete with an optional close-out note.
     */
    public function close(Request $request, MedicationError $error)
    {
        $validated = $request->validate([
            'close_note' => 'nullable|string|max:5000',
        ]);

        if (! in_array($error->status, ['resolved', 'closed'], true)) {
            return redirect()->back()->withErrors(['status' => 'Only a resolved error can be closed out.']);
        }

        $error->update([
            'close_note' => $validated['close_note'] ?? $error->close_note,
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Error closed out.');
    }

    /**
     * Post-report "create & link incident". The report-time create_incident path
     * is only available at store(); this exposes the same incident-creation shape
     * as a standalone action on an already-reported error, links it via
     * client_incident_id, then navigates to the incidents module (incidents.show).
     * Idempotent — if an incident is already linked it just jumps to it. This does
     * NOT modify the incidents module. See docs/ERRORS_GAP_ANALYSIS.md (C1).
     */
    public function linkIncident(Request $request, MedicationError $error)
    {
        $incidentId = DB::transaction(function () use ($request, $error): int {
            $lockedError = MedicationError::query()
                ->whereKey($error->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedError->client_incident_id !== null) {
                return (int) $lockedError->client_incident_id;
            }

            $incident = ClientIncident::withoutEvents(fn () => ClientIncident::query()->create([
                'client_id' => $lockedError->client_id,
                'title' => 'Medication Error: '.str_replace('_', ' ', (string) $lockedError->error_type),
                'description' => $lockedError->description ?: 'Linked from medication error '.$lockedError->id.'.',
                'occurred_at' => $lockedError->reported_at ?? now(),
                'reported_by' => $request->user()->id,
                'severity' => match ($lockedError->severity) {
                    'critical' => 'critical',
                    'major' => 'high',
                    'moderate' => 'medium',
                    default => 'low',
                },
                'status' => 'submitted',
                'submitted_at' => now(),
                'type' => 'medication_error',
            ]));

            $lockedError->forceFill(['client_incident_id' => $incident->id])->save();
            $linkedError = $lockedError->fresh();
            $signals = app(MedicationSignalService::class);
            $signals->emitError($linkedError);
            $existingAlert = $signals->attachExistingErrorSignalToIncident($linkedError);
            $journeys = app(IncidentJourneyService::class);
            $journey = $existingAlert === null
                ? $journeys->ensureForSubmittedIncident($incident, $request->user())
                : $journeys->attachAlertToIncident($incident, $existingAlert, $request->user());
            app(TimelineEmitter::class)->project($journey->incident);

            $createdIncidentId = (int) $journey->incident->id;
            DB::afterCommit(function () use ($createdIncidentId): void {
                $committedIncident = ClientIncident::query()->find($createdIncidentId);
                if ($committedIncident !== null) {
                    app(IncidentEscalationService::class)->escalateClientIncident($committedIncident);
                }
            });

            return $createdIncidentId;
        }, 3);

        return redirect()->route('incidents.show', $incidentId);
    }
}
