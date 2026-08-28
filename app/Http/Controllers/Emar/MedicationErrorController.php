<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Jobs\Governance\RegisterIncidentGovernanceEscalationJob;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\MedicationError;
use App\Models\MedicationMarAttachment;
use App\Models\Site;
use App\Models\User;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationScopeDecision;
use App\Services\Medication\MedicationScopeDecisionService;
use App\Services\Medication\MedicationSignalService;
use App\Services\MedicationIncidentIntegrationService;
use App\Services\Timeline\TimelineEmitter;
use App\Support\EmarUrl;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class MedicationErrorController extends Controller
{
    public function __construct(
        private readonly MedicationScopeDecisionService $medicationScope,
        private readonly MedicationGovernanceScopeService $governanceScope,
    ) {}

    private function serializeAttachment(
        MedicationMarAttachment $attachment,
        Request $request,
        bool $controlledMedication,
    ): array {
        $actor = $request->user();

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
            'can_delete' => $actor->canDo('medications.administer.correct')
                && (! $controlledMedication
                    || $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY)),
        ];
    }

    private function serializeError(
        MedicationError $error,
        Request $request,
        bool $controlledMedication,
    ): array {
        $incident = $error->incident;
        if (
            $incident !== null
            && (
                (int) $incident->client_id !== (int) $error->client_id
                || (int) $incident->site_id !== (int) $error->client?->site_id
            )
        ) {
            $incident = null;
        }

        $attachments = $error->attachments
            ->filter(fn (MedicationMarAttachment $attachment): bool => (int) $attachment->client_id === (int) $error->client_id
                && $attachment->attachable_type === $error->getMorphClass()
                && (int) $attachment->attachable_id === (int) $error->getKey())
            ->map(fn (MedicationMarAttachment $attachment) => $this->serializeAttachment(
                $attachment,
                $request,
                $controlledMedication,
            ))
            ->values()
            ->all();

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
            'incident' => $incident ? [
                'id' => $incident->id,
                'ref' => $incident->reference_number ?? 'INC-'.str_pad((string) $incident->id, 4, '0', STR_PAD_LEFT),
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
            'attachments' => $attachments,
        ];
    }

    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor !== null, 403);
        $siteFilter = $request->integer('site_id') ?: null;
        $accessibleSiteIds = $this->governanceScope->readerSiteIds(
            $actor,
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            requestedSiteId: $siteFilter,
        );
        $readerSiteIds = $siteFilter !== null ? [$siteFilter] : $accessibleSiteIds;
        $readerClientIds = Client::query()
            ->whereIn('site_id', $readerSiteIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        // Flat, client-side-filterable register — the redesigned page facets by
        // tab/search/severity/type/reporter with live counts (drops pagination).
        $modelQuery = $this->governanceScope->scopeCanonicalClientMedicationRows(
            MedicationError::query(),
            $readerSiteIds,
        );
        if (! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($modelQuery);
        }

        $models = $modelQuery
            ->with([
                'client:id,first_name,last_name,site_id',
                'client.site:id,name',
                'medication:id,name',
                'incident' => fn ($query) => $query
                    ->whereIn('client_id', $readerClientIds)
                    ->whereIn('site_id', $readerSiteIds)
                    ->select(['id', 'client_id', 'site_id', 'reference_number']),
                'reportedBy:id,name',
                'reviewedBy:id,name',
                'attachments' => fn ($query) => $query
                    ->whereIn('client_id', $readerClientIds)
                    ->with('uploadedBy:id,name'),
            ])
            ->orderByDesc('reported_at')
            ->limit(300)
            ->get();

        $controlledMedicationIds = ClientMedication::withTrashed()
            ->whereIn('id', $models->pluck('client_medication_id')->filter())
            ->where('controlled_drug', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);
        $errors = $models
            ->map(fn (MedicationError $error) => $this->serializeError(
                $error,
                $request,
                $controlledMedicationIds->contains((int) $error->client_medication_id),
            ))
            ->values();

        // Aggregate stats over the whole (optionally site-scoped) register.
        $statQuery = $this->governanceScope->scopeCanonicalClientMedicationRows(
            MedicationError::query(),
            $readerSiteIds,
        );
        if (! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($statQuery);
        }
        $statRows = $statQuery->get(['id', 'severity', 'error_type', 'status', 'reported_at', 'updated_at']);

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
        $sites = $this->governanceScope->sitePicker($accessibleSiteIds);
        $activeSite = $siteFilter !== null ? $sites->firstWhere('id', $siteFilter) : null;

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
            'clients' => $this->governanceScope->clientPicker($readerSiteIds),
            'staff' => $this->governanceScope->staffPicker($readerSiteIds),
            'sites' => $sites
                ->map(fn (Site $site) => $site->only(['id', 'name']))
                ->values(),
            'active_site' => $activeSite?->only(['id', 'name']),
            'site_brand_colour' => $activeSite?->brand_colour,
            'can' => [
                'record' => $actor->canDo('medications.administer.record'),
                'correct' => $actor->canDo('medications.administer.correct'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $this->recordingActor($request);

        $validated = $request->validate([
            'client_id' => ['required', 'integer', 'min:1'],
            'client_medication_id' => ['nullable', 'integer', 'min:1'],
            'error_type' => 'required|in:wrong_medication,wrong_client,wrong_dose,wrong_time,wrong_route,omission,unauthorised,documentation,other',
            'severity' => 'required|in:near_miss,minor,moderate,major,critical',
            'reached_client' => 'nullable|in:no,yes,unknown',
            'open_disclosure' => 'nullable|in:na,pending,done',
            'description' => 'required|string|max:5000',
            'immediate_action' => [
                Rule::requiredIf(fn (): bool => $request->boolean('create_incident')
                    && in_array($request->input('severity'), ['major', 'critical'], true)),
                'nullable',
                'string',
                'max:5000',
            ],
            'contributing_factors' => 'nullable|string|max:5000',
            'create_incident' => 'nullable|boolean',
        ]);

        return $this->withAssignedClient(
            $user,
            (int) $validated['client_id'],
            function (MedicationScopeDecision $scope) use ($request, $validated, $user) {
                $attributes = $validated;
                $attributes['client_id'] = $scope->client->id;
                $attributes['reported_by'] = $user->id;
                $attributes['reported_at'] = now();
                $attributes['status'] = 'reported';

                if (($attributes['client_medication_id'] ?? null) !== null) {
                    $medication = ClientMedication::withTrashed()
                        ->whereKey($attributes['client_medication_id'])
                        ->where('client_id', $scope->client->id)
                        ->lockForUpdate()
                        ->first(['id', 'controlled_drug']);
                    abort_unless($medication !== null, 404);
                    abort_if(
                        (bool) $medication->controlled_drug
                        && ! $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
                        404,
                    );
                }

                $incident = null;
                if ($request->boolean('create_incident')) {
                    $incident = ClientIncident::withoutEvents(
                        fn () => ClientIncident::create([
                            'client_id' => $scope->client->id,
                            'site_id' => $scope->siteId,
                            'title' => 'Medication Error: '.str_replace('_', ' ', $attributes['error_type']),
                            'description' => $attributes['description'],
                            'immediate_action_taken' => $attributes['immediate_action'] ?? null,
                            'occurred_at' => now(),
                            'reported_by' => $user->id,
                            'severity' => match ($attributes['severity']) {
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
                        ->ensureForSubmittedIncident($incident, $user);
                }

                unset($attributes['create_incident']);
                $attributes['client_incident_id'] = $incident?->id;
                $error = MedicationError::create($attributes);

                app(MedicationSignalService::class)->emitError($error);

                if ($incident !== null) {
                    app(TimelineEmitter::class)->project($incident->fresh());

                    $incidentId = (int) $incident->id;
                    DB::afterCommit(function () use ($incidentId): void {
                        try {
                            RegisterIncidentGovernanceEscalationJob::dispatch($incidentId);
                        } catch (Throwable $exception) {
                            Log::error('Medication incident governance dispatch failed', [
                                'client_incident_id' => $incidentId,
                                'exception' => $exception::class,
                                'error' => $exception->getMessage(),
                            ]);
                        }
                    });
                }

                return redirect()->back()->with('success', 'Medication error reported successfully.');
            },
        );
    }

    public function update(Request $request, MedicationError $error)
    {
        $user = $this->correctionActor($request);

        return $this->withCanonicalError($user, $error, function (MedicationError $lockedError) use ($request) {
            abort_unless(in_array($lockedError->status, ['reported', 'investigating'], true), 409);
            $validated = $request->validate([
                // These fields determine the required incident/signal journey.
                // They are immutable after reporting; changing either needs a
                // separately governed correction workflow that synchronizes
                // every linked operational record atomically.
                'error_type' => ['prohibited'],
                'severity' => ['prohibited'],
                'description' => 'sometimes|string|max:5000',
                'immediate_action' => 'nullable|string|max:5000',
                'contributing_factors' => 'nullable|string|max:5000',
            ]);

            $lockedError->update($validated);

            return redirect()->back()->with('success', 'Medication error updated successfully.');
        });
    }

    public function review(Request $request, MedicationError $error)
    {
        $user = $this->correctionActor($request);

        return $this->withCanonicalError($user, $error, function (MedicationError $lockedError) use ($request, $user) {
            abort_unless(in_array($lockedError->status, ['reported', 'investigating'], true), 409);
            $validated = $request->validate([
                'review_notes' => 'required|string|max:5000',
            ]);

            $lockedError->update([
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'review_notes' => $validated['review_notes'],
                'status' => 'investigating',
            ]);

            return redirect()->back()->with('success', 'Error reviewed successfully.');
        });
    }

    public function resolve(Request $request, MedicationError $error)
    {
        $user = $this->correctionActor($request);

        return $this->withCanonicalError($user, $error, function (MedicationError $lockedError) use ($request, $user) {
            abort_unless(in_array($lockedError->status, ['reported', 'investigating'], true), 409);
            $validated = $request->validate([
                'outcome' => 'required|string|max:5000',
                'preventive_actions' => 'required|string|max:5000',
                'harm_level' => 'nullable|in:a_c,d_e,f_g,h_i',
            ]);

            $lockedError->update([
                'outcome' => $validated['outcome'],
                'preventive_actions' => $validated['preventive_actions'],
                'harm_level' => $validated['harm_level'] ?? $lockedError->harm_level,
                'status' => 'resolved',
                'reviewed_by' => $lockedError->reviewed_by ?? $user->id,
                'reviewed_at' => $lockedError->reviewed_at ?? now(),
            ]);

            app(MedicationIncidentIntegrationService::class)->resolveMedicationError(
                $lockedError,
                'Medication error resolved.',
                $user->id
            );

            return redirect()->back()->with('success', 'Error resolved successfully.');
        });
    }

    /**
     * Close out a resolved error — the final governance sign-off. Closing keeps
     * the record (SoftDeletes is reserved for retraction); it just marks the
     * lifecycle complete with an optional close-out note.
     */
    public function close(Request $request, MedicationError $error)
    {
        $user = $this->correctionActor($request);

        return $this->withCanonicalError($user, $error, function (MedicationError $lockedError) use ($request, $user) {
            $validated = $request->validate([
                'close_note' => 'nullable|string|max:5000',
            ]);

            if ($lockedError->status === 'closed') {
                return redirect()->back()->with('success', 'Error already closed out.');
            }

            if ($lockedError->status !== 'resolved') {
                return redirect()->back()->withErrors(['status' => 'Only a resolved error can be closed out.']);
            }

            $lockedError->update([
                'close_note' => $validated['close_note'] ?? $lockedError->close_note,
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $user->id,
            ]);

            return redirect()->back()->with('success', 'Error closed out.');
        });
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
        $user = $this->correctionActor($request);

        return $this->withCanonicalError($user, $error, function (MedicationError $lockedError, Client $client) use ($request, $user) {
            if ($lockedError->client_incident_id !== null) {
                return redirect()->route('incidents.show', (int) $lockedError->client_incident_id);
            }

            if (! in_array($lockedError->status, ['reported', 'investigating'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only a reported or investigating medication error can be linked to a new incident.',
                ]);
            }

            $validated = $request->validate([
                'immediate_action' => ['nullable', 'string', 'max:5000'],
            ]);

            $immediateAction = trim((string) ($validated['immediate_action'] ?? $lockedError->immediate_action));
            if (in_array($lockedError->severity, ['major', 'critical'], true)
                && $immediateAction === ''
            ) {
                throw ValidationException::withMessages([
                    'immediate_action' => 'Record the immediate action actually taken before creating a major or critical incident.',
                ]);
            }

            if ($immediateAction !== '' && $immediateAction !== $lockedError->immediate_action) {
                $lockedError->forceFill(['immediate_action' => $immediateAction])->save();
            }

            $incident = ClientIncident::withoutEvents(fn () => ClientIncident::query()->create([
                'client_id' => $lockedError->client_id,
                'site_id' => $client->site_id,
                'title' => 'Medication Error: '.str_replace('_', ' ', (string) $lockedError->error_type),
                'description' => $lockedError->description ?: 'Linked from medication error '.$lockedError->id.'.',
                'immediate_action_taken' => $immediateAction === '' ? null : $immediateAction,
                'occurred_at' => $lockedError->reported_at ?? now(),
                'reported_by' => $user->id,
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
                ? $journeys->ensureForSubmittedIncident($incident, $user)
                : $journeys->attachAlertToIncident($incident, $existingAlert, $user);
            app(TimelineEmitter::class)->project($journey->incident);

            $createdIncidentId = (int) $journey->incident->id;
            DB::afterCommit(function () use ($createdIncidentId): void {
                try {
                    RegisterIncidentGovernanceEscalationJob::dispatch($createdIncidentId);
                } catch (Throwable $exception) {
                    Log::error('Linked medication incident governance dispatch failed', [
                        'client_incident_id' => $createdIncidentId,
                        'exception' => $exception::class,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });

            return redirect()->route('incidents.show', $createdIncidentId);
        });
    }

    private function recordingActor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user?->canDo('medications.administer.record'), 403);

        return $user;
    }

    private function correctionActor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user?->canDo('medications.administer.correct'), 403);

        return $user;
    }

    private function withCanonicalError(User $user, MedicationError $submittedError, Closure $callback): mixed
    {
        $snapshot = MedicationError::query()
            ->whereKey($submittedError->getKey())
            ->first(['id', 'client_id', 'client_medication_id']);
        abort_unless($snapshot !== null, 404);

        $clientId = (int) $snapshot->client_id;
        abort_unless($clientId > 0, 404);

        return $this->governanceScope->forClient(
            $user,
            $clientId,
            'medications.administer.correct',
            function (Client $client) use ($user, $snapshot, $callback) {
                if ($snapshot->client_medication_id !== null) {
                    $medication = ClientMedication::withTrashed()
                        ->whereKey($snapshot->client_medication_id)
                        ->where('client_id', $client->id)
                        ->lockForUpdate()
                        ->first(['id', 'controlled_drug']);
                    abort_unless($medication !== null, 404);
                    abort_if(
                        (bool) $medication->controlled_drug
                        && ! $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
                        404,
                    );
                }

                $error = MedicationError::query()
                    ->whereKey($snapshot->getKey())
                    ->where('client_id', $client->id)
                    ->lockForUpdate()
                    ->first();
                abort_unless($error !== null, 404);
                abort_unless(
                    ($error->client_medication_id === null && $snapshot->client_medication_id === null)
                    || (int) $error->client_medication_id === (int) $snapshot->client_medication_id,
                    404,
                );
                $this->assertErrorOwnership($error, $client);

                return $callback($error, $client);
            },
        );
    }

    private function withAssignedClient(User $user, int $clientId, Closure $callback): mixed
    {
        $scopeEntered = false;

        try {
            return $this->medicationScope->forClient(
                $user,
                $clientId,
                now(),
                function (MedicationScopeDecision $scope) use ($callback, &$scopeEntered) {
                    $scopeEntered = true;

                    return $callback($scope);
                },
            );
        } catch (HttpExceptionInterface $exception) {
            if (! $scopeEntered && $exception->getStatusCode() === 403) {
                abort(404, 'The requested medication action is not available.');
            }

            throw $exception;
        }
    }

    private function assertErrorOwnership(MedicationError $error, Client $client): void
    {
        abort_unless((int) $error->client_id === (int) $client->id, 404);

        if ($error->client_incident_id !== null) {
            $incidentMatches = ClientIncident::query()
                ->whereKey($error->client_incident_id)
                ->where('client_id', $client->id)
                ->where('site_id', $client->site_id)
                ->exists();
            abort_unless($incidentMatches, 404);
        }
    }
}
