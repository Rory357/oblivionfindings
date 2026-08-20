<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Requests\HealthSafety\StoreHsCorrectiveActionRequest;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientIncidentAttachment;
use App\Models\ControlRoom\EvidenceItem;
use App\Models\HsEvent;
use App\Models\IncidentFollowup;
use App\Models\MedicationError;
use App\Models\SafeguardingConcern;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsCorrectiveActionService;
use App\Services\HealthSafety\NotifiableEventClassifier;
use App\Services\Incidents\IncidentAlertLifecycleSignalService;
use App\Services\Incidents\IncidentJourney;
use App\Services\Incidents\IncidentJourneyPresenter;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\NotificationService;
use App\Services\UserSiteAccessService;
use App\Support\Incidents\LinkedOperationalEvidencePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentController extends Controller
{
    use ServesPrivateAttachments;

    public function __construct(
        private readonly LinkedOperationalEvidencePresenter $linkedEvidence,
        private readonly IncidentJourneyService $journeys,
        private readonly IncidentJourneyPresenter $journeyPresenter,
        private readonly IncidentAlertLifecycleSignalService $incidentAlertSignals,
    ) {}

    /**
     * Unified Incidents register (redesign): hs-hero-kit hero with incident stat
     * clusters, an 8-tab TabStrip, Site/Client/Source filters, and right-click
     * rows. Near misses and the follow-ups worklist are first-class tabs.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('incidents.viewAny') || $user->canDo('incidents.viewAssigned')), 403);

        $q = trim((string) $request->get('q', ''));
        $tab = $request->get('tab', 'all'); // all|open|investigation|followups|worksafe|near_misses|review|closed
        // Back-compat: the legacy ?type=near_miss lens now lands on the tab.
        if ($request->get('type') === 'near_miss' && ! $request->filled('tab')) {
            $tab = 'near_misses';
        }
        $severity = $request->get('severity');
        $clientId = $request->get('client_id');
        $siteId = $request->get('site_id');
        $source = $request->get('source');
        $from = $request->get('from');
        $to = $request->get('to');

        // Shared filters (everything EXCEPT the tab), reusable on both the
        // ClientIncident query and the follow-ups whereHas('incident') subquery,
        // so the tab counts stay mutually consistent.
        $applyFilters = function ($query) use ($user, $q, $severity, $clientId, $siteId, $source, $from, $to) {
            return $query
                ->when($user->canDo('incidents.viewAssigned') && ! $user->canDo('incidents.viewAny'), function ($query) use ($user) {
                    $query->whereHas('client.supportWorkers', fn ($qq) => $qq->whereKey($user->id));
                })
                ->when($q !== '', function ($query) use ($q) {
                    $term = '%'.$q.'%';
                    $query->where(fn ($sub) => $sub->where('description', 'like', $term)
                        ->orWhere('type', 'like', $term)
                        ->orWhere('title', 'like', $term));
                })
                ->when($severity, fn ($query) => $query->where('severity', $severity))
                ->when($clientId, fn ($query) => $query->where('client_id', $clientId))
                ->when($siteId, fn (Builder $query) => $this->whereIncidentSite($query, (int) $siteId))
                ->when($source, fn ($query) => $query->where('source', $source))
                ->when($from, fn ($query) => $query->whereDate('occurred_at', '>=', $from))
                ->when($to, fn ($query) => $query->whereDate('occurred_at', '<=', $to));
        };

        $applyTab = fn ($query, string $t) => match ($t) {
            'open' => $query->where('status', '!=', 'closed'),
            'investigation' => $query->whereIn('investigation_status', ['pending', 'in_progress']),
            'worksafe' => $this->whereCanonicalWorksafeEvent($query),
            'near_misses' => $query->where('type', 'near_miss'),
            'review' => $query->where('status', 'submitted'),
            'closed' => $query->where('status', 'closed'),
            default => $query,
        };

        // Per-tab counts.
        $countFor = fn (string $t) => $applyTab($applyFilters(ClientIncident::query()), $t)->count();
        $openFollowupsQuery = fn () => IncidentFollowup::query()
            ->whereNull('completed_at')
            ->whereHas('incident', fn ($i) => $applyFilters($i));

        $tabCounts = [
            'all' => $countFor('all'),
            'open' => $countFor('open'),
            'investigation' => $countFor('investigation'),
            'followups' => $openFollowupsQuery()->count(),
            'worksafe' => $countFor('worksafe'),
            'near_misses' => $countFor('near_misses'),
            'review' => $countFor('review'),
            'closed' => $countFor('closed'),
        ];

        // Rows for the active tab. Follow-ups due is a worklist of follow-up rows;
        // every other tab is a list of incident rows.
        if ($tab === 'followups') {
            $rows = $openFollowupsQuery()
                ->with(['incident:id,type,client_id,reference_number', 'incident.client:id,first_name,last_name', 'assignedTo:id,name'])
                ->orderByRaw('due_at is null')
                ->orderBy('due_at')
                ->paginate(50)
                ->withQueryString()
                ->through(fn (IncidentFollowup $f) => [
                    'id' => $f->id,
                    'incident_id' => $f->client_incident_id,
                    'incident_ref' => $f->incident?->reference_number,
                    'incident_type' => $f->incident?->type,
                    'client_name' => $f->incident?->client
                        ? trim(($f->incident->client->first_name ?? '').' '.($f->incident->client->last_name ?? ''))
                        : null,
                    'assigned_to' => $f->assignedTo?->name,
                    'due_at' => $f->due_at,
                    'overdue' => $f->due_at ? $f->due_at->isPast() : false,
                    'notes' => $f->notes,
                ]);
            $rowsKind = 'followups';
        } else {
            $rows = $applyTab($applyFilters(ClientIncident::query()), $tab)
                ->with([
                    'client:id,first_name,last_name,site_id',
                    'client.site:id,name',
                    'site:id,name',
                    'reporter:id,name',
                    'hsEvent:id,source_type,source_id,event_category,idempotency_key,client_id,site_id,control_room_alert_id,worksafe_notifiable,worksafe_status,worksafe_reference,worksafe_notified_at,worksafe_acknowledged_at,worksafe_method,worksafe_site_preserved',
                    'hsEvent.site:id,name',
                ])
                ->withCount([
                    'attachments',
                    'followups as open_followups_count' => fn ($qq) => $qq->whereNull('completed_at'),
                ])
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString()
                ->through(function (ClientIncident $incident): array {
                    $directEvent = $incident->hsEvent;
                    $event = $directEvent !== null
                        && $this->journeys->hsEventIsCanonicalForIncident($incident, $directEvent)
                            ? $directEvent
                            : null;
                    $journeyRepairRequired = $incident->hs_event_id !== null && $event === null;

                    return [
                        'id' => $incident->id,
                        'ref' => $incident->reference_number,
                        'occurred_at' => $incident->occurred_at,
                        'type' => $incident->type,
                        'description' => $incident->description,
                        'severity' => $incident->severity,
                        'status' => $incident->status,
                        'source' => $incident->source,
                        'interactive' => $incident->interactive,
                        'is_notifiable' => $journeyRepairRequired
                            ? false
                            : ($event
                            ? (bool) $event->worksafe_notifiable
                            : (bool) $incident->is_notifiable),
                        'worksafe_notification_status' => $journeyRepairRequired
                            ? null
                            : ($event
                            ? $event->worksafe_status
                            : $incident->worksafe_notification_status),
                        'journey_repair_required' => $journeyRepairRequired,
                        'potential_severity' => $incident->potential_severity,
                        'investigation_status' => $incident->investigation_status,
                        'control_room_alert_id' => $incident->control_room_alert_id,
                        'requires_followup' => (bool) $incident->requires_followup,
                        'attachments_count' => $incident->attachments_count,
                        'open_followups_count' => $incident->open_followups_count,
                        'client' => $incident->client ? [
                            'id' => $incident->client->id,
                            'first_name' => $incident->client->first_name,
                            'last_name' => $incident->client->last_name,
                            'site' => $incident->site?->name
                                ?? $event?->site?->name
                                ?? $incident->client->site?->name,
                        ] : null,
                        'reporter' => $incident->reporter ? ['name' => $incident->reporter->name] : null,
                    ];
                });
            $rowsKind = 'incidents';
        }

        // Hero clusters. "This period" = 30-day flow (Reported/Closed carry a
        // delta vs the prior 30 days); Open / Under investigation are current
        // state. "Needs attention" = current worklists.
        $p30 = now()->subDays(30);
        $p60 = now()->subDays(60);
        $p90 = now()->subDays(90);

        $reported30 = $applyFilters(ClientIncident::query())->where('occurred_at', '>=', $p30)->count();
        $reportedPrev = $applyFilters(ClientIncident::query())->whereBetween('occurred_at', [$p60, $p30])->count();
        $closed30 = $applyFilters(ClientIncident::query())->where('status', 'closed')->where('closed_at', '>=', $p30)->count();
        $closedPrev = $applyFilters(ClientIncident::query())->where('status', 'closed')->whereBetween('closed_at', [$p60, $p30])->count();

        $overdueFollowups = $openFollowupsQuery()->where('due_at', '<', now())->count();
        $worksafeAwaiting = $this->whereCanonicalWorksafeEvent(
            $applyFilters(ClientIncident::query()),
            HsEvent::WORKSAFE_PENDING,
        )->count();
        $activeAlerts = $applyFilters(ClientIncident::query())
            ->whereNotNull('control_room_alert_id')
            ->whereHas('controlRoomAlert', fn ($a) => $a->actionable())
            ->count();

        $hero = [
            'period' => [
                'reported' => ['value' => $reported30, 'delta' => $reported30 - $reportedPrev],
                'open' => ['value' => $tabCounts['open']],
                'investigation' => ['value' => $tabCounts['investigation']],
                'closed' => ['value' => $closed30, 'delta' => $closed30 - $closedPrev],
            ],
            'attention' => [
                'followups' => ['value' => $tabCounts['followups'], 'overdue' => $overdueFollowups],
                'review' => ['value' => $tabCounts['review']],
                'worksafe' => ['value' => $worksafeAwaiting],
                'alerts' => ['value' => $activeAlerts],
            ],
        ];

        // Near-miss insights strip (leading indicator).
        $nm30 = $applyFilters(ClientIncident::query())->where('type', 'near_miss')->where('occurred_at', '>=', $p30)->count();
        $nmPrev = $applyFilters(ClientIncident::query())->where('type', 'near_miss')->whereBetween('occurred_at', [$p60, $p30])->count();
        $nm90 = $applyFilters(ClientIncident::query())->where('type', 'near_miss')->where('occurred_at', '>=', $p90)->count();
        $inc90 = $applyFilters(ClientIncident::query())->where('type', '!=', 'near_miss')->where('occurred_at', '>=', $p90)->count();
        $nmByPotential = $applyFilters(ClientIncident::query())
            ->where('type', 'near_miss')
            ->whereNotNull('potential_severity')
            ->selectRaw('potential_severity, count(*) as c')
            ->groupBy('potential_severity')
            ->orderByDesc('c')
            ->pluck('c', 'potential_severity');

        $nearMissInsights = [
            'trend_pct' => $nmPrev > 0 ? (int) round((($nm30 - $nmPrev) / $nmPrev) * 100) : null,
            'ratio' => $inc90 > 0 ? round($nm90 / $inc90, 1) : null,
            'by_potential' => $nmByPotential, // {low: n, medium: n, ...} — "what could have happened"
        ];

        $sites = null;
        $clients = null;
        if ($user->canDo('incidents.viewAny')) {
            $siteQuery = Site::query()->where('is_active', true);
            app(UserSiteAccessService::class)->applySiteScope(
                $siteQuery,
                $user,
                $this->incidentReportSiteBypassPermissions(),
            );
            $sites = $siteQuery->orderBy('name')->get(['id', 'name']);
            $clientQuery = Client::query();
            app(UserSiteAccessService::class)->applyClientScope(
                $clientQuery,
                $user,
                $this->incidentReportSiteBypassPermissions(),
            );
            $clients = $clientQuery->orderBy('first_name')->get(['id', 'first_name', 'last_name']);
        }

        // Report-wizard data for the modal-first "+ Report": clients the user may
        // report for (scoped like IncidentController@create) + follow-up owners.
        $reportClients = null;
        $reportStaff = null;
        if ($user->canDo('incidents.create')) {
            $reportClientQuery = Client::query();

            app(UserSiteAccessService::class)->applyClientScope(
                $reportClientQuery,
                $user,
                $this->incidentReportSiteBypassPermissions(),
            );

            if (! $this->canReportAcrossHealthSafety($user) && ! $user->canDo('clients.viewAny')) {
                $reportClientQuery->whereHas('supportWorkers', fn ($s) => $s->whereKey($user->id));
            }

            $reportClients = $reportClientQuery
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name', 'site_id']);
            $reportStaff = $user->canDo('incidents.followups.manage')
                ? User::staff()->orderBy('name')->get(['id', 'name'])
                : [];
        }

        return inertia('incidents/index', [
            'filters' => [
                'q' => $q,
                'tab' => $tab,
                'severity' => $severity,
                'client_id' => $clientId ? (int) $clientId : null,
                'site_id' => $siteId ? (int) $siteId : null,
                'source' => $source,
                'from' => $from,
                'to' => $to,
            ],
            'tab' => $tab,
            'tabCounts' => $tabCounts,
            'rows' => $rows,
            'rowsKind' => $rowsKind,
            'hero' => $hero,
            'nearMissInsights' => $nearMissInsights,
            'sites' => $sites,
            'clients' => $clients,
            'reportClients' => $reportClients,
            'reportStaff' => $reportStaff,
            // Auto-open the report wizard when arriving from /incidents/create
            // (redirected here with ?report= + optional prefill).
            'report' => in_array($request->get('report'), ['incident', 'near_miss'], true) ? $request->get('report') : null,
            'reportPrefill' => [
                'client_id' => $request->filled('report_client_id') ? (int) $request->get('report_client_id') : null,
                'shift_id' => $request->filled('report_shift_id') ? (int) $request->get('report_shift_id') : null,
            ],
            'can' => [
                'create' => $user->canDo('incidents.create'),
                'followupsManage' => $user->canDo('incidents.followups.manage'),
                'templatesManage' => $user->canDo('incidents.templates.manage'),
            ],
            // Detail-over-list: when ?incident= is present the dialog opens over
            // the register (Inertia partial-reloads only this prop). Null otherwise.
            'detail' => $request->filled('incident')
                ? $this->buildIncidentDetail($request, (int) $request->get('incident'))
                : null,
        ]);
    }

    private function whereCanonicalWorksafeEvent(
        Builder $query,
        ?string $status = null,
    ): Builder {
        $eventFilter = function (Builder $eventQuery) use ($status): void {
            $eventQuery->where('worksafe_notifiable', true)
                ->when($status, fn (Builder $statusQuery) => $statusQuery->where('worksafe_status', $status));
        };

        return $query->where(function (Builder $canonicalQuery) use ($eventFilter): void {
            $canonicalQuery->whereHas('hsEvent', $eventFilter);
        });
    }

    /**
     * Apply the immutable incident-site snapshot, with deterministic fallbacks
     * for rows that pre-date client_incidents.site_id. A linked H&S event wins
     * over the client's current placement so historic incidents do not move.
     */
    private function whereIncidentSite(Builder $query, int $siteId): Builder
    {
        return $query->where(function (Builder $siteQuery) use ($siteId): void {
            $siteQuery->where($siteQuery->qualifyColumn('site_id'), $siteId)
                ->orWhere(function (Builder $historicQuery) use ($siteId): void {
                    $historicQuery->whereNull($historicQuery->qualifyColumn('site_id'))
                        ->where(function (Builder $fallbackQuery) use ($siteId): void {
                            $fallbackQuery
                                ->whereHas('hsEvent', fn (Builder $eventQuery) => $eventQuery->where('site_id', $siteId))
                                ->orWhere(function (Builder $shiftQuery) use ($siteId): void {
                                    $shiftQuery
                                        ->whereDoesntHave('hsEvent', fn (Builder $eventQuery) => $eventQuery->whereNotNull('site_id'))
                                        ->whereHas('shift', fn (Builder $linkedShiftQuery) => $linkedShiftQuery->where('site_id', $siteId));
                                })
                                ->orWhere(function (Builder $clientQuery) use ($siteId): void {
                                    $clientQuery
                                        ->whereDoesntHave('hsEvent', fn (Builder $eventQuery) => $eventQuery->whereNotNull('site_id'))
                                        ->whereDoesntHave('shift', fn (Builder $linkedShiftQuery) => $linkedShiftQuery->whereNotNull('site_id'))
                                        ->whereHas('client', fn (Builder $linkedClientQuery) => $linkedClientQuery->where('site_id', $siteId));
                                });
                        });
                });
        });
    }

    private function canOpenHsEvent(User $user, HsEvent $event): bool
    {
        if (! $user->canDo('hazards.view')) {
            return false;
        }

        $query = HsEvent::query()->whereKey($event->id);
        app(UserSiteAccessService::class)->applyHsEventScope(
            $query,
            $user,
            $this->healthSafetySiteBypassPermissions(),
        );

        return $query->exists();
    }

    /**
     * The full, read-only detail payload behind the IncidentDetailDialog — shared
     * by the modal-over-list (index `?incident=`) and the `/incidents/{id}`
     * deep-link. Returns null if the incident is missing or not viewable.
     *
     * @return array<string, mixed>|null
     */
    private function buildIncidentDetail(Request $request, int $incidentId): ?array
    {
        $user = $request->user();

        $incident = ClientIncident::query()
            ->with([
                'client:id,first_name,last_name,site_id',
                'client.site:id,name',
                'site:id,name',
                'reporter:id,name,email',
                'shift:id,starts_at,ends_at,actual_ends_at',
                'attachments.uploader:id,name',
                'followups.assignedTo:id,name',
                'followups.creator:id,name',
                'investigator:id,name',
                'controlRoomAlert:id,status,severity,alert_type,triggered_at,resolved_at',
                'safeguardingConcerns',
                'fleetIncident:id,incident_type',
                'restraintEvents:id,reference_number,related_incident_id,restraint_type,severity,within_support_plan,injury_occurred,started_at',
                'firstAidRecords:id,reference_number,related_incident_id,treated_person_name,injury_illness_type,treatment_date,ambulance_called',
            ])
            ->find($incidentId);

        if (! $incident || ! $user || $user->cannot('view', $incident)) {
            return null;
        }

        $journeyRepairRequired = false;
        try {
            $journey = $this->journeys->journeyForIncident($incident);
            $hsEvent = $journey->hsEvent;
            $linkedControlRoomAlert = $journey->alert;
        } catch (\DomainException) {
            // A contradictory direct link is an integrity signal, not authority
            // to disclose another incident's linked records in this journey.
            $journeyRepairRequired = true;
            $hsEvent = null;
            $linkedControlRoomAlert = null;
        }

        $hsEvent?->loadMissing([
            'site:id,name',
            'latestInvestigation',
            'correctiveActions.assignedTo:id,name',
            'owner:id,name',
            'acceptedBy:id,name',
        ]);

        $inv = $hsEvent?->latestInvestigation;
        $canOpenHsEvent = $hsEvent && $this->canOpenHsEvent($user, $hsEvent);
        $canRaiseCorrectiveAction = $user->canDo('incidents.viewAny')
            || $user->canDo('compliance.view')
            || $user->canDo('hazards.view');
        $correctiveActionOwners = [];
        if ($hsEvent && $canRaiseCorrectiveAction) {
            $ownerQuery = User::query();
            app(UserSiteAccessService::class)->applyHsEventStaffScope(
                $ownerQuery,
                $hsEvent,
                $user,
                ['healthSafety.viewAllSites'],
            );
            $correctiveActionOwners = $ownerQuery
                ->orderBy('name')
                ->limit(200)
                ->get(['id', 'name'])
                ->filter(fn (User $candidate): bool => $candidate->canDo('hazards.manage'))
                ->map(fn (User $candidate): array => [
                    'id' => $candidate->id,
                    'name' => $candidate->name,
                ])
                ->values()
                ->all();
        }
        $linkedOperationalEvidence = $linkedControlRoomAlert
            ? $this->linkedEvidence->present(
                $linkedControlRoomAlert,
                $user,
                fn (EvidenceItem $item): string => "/incidents/{$incident->id}/control-room-evidence/{$item->id}/download",
            )
            : null;
        $closeGatePayload = $journeyRepairRequired
            ? [
                'allowed' => false,
                'requirements' => [[
                    'key' => 'journey_integrity',
                    'complete' => false,
                    'label' => 'Repair the linked incident journey before closing this incident.',
                    'href' => "/incidents/{$incident->id}",
                ]],
            ]
            : $this->journeys->closeGateForDisplay($incident)->toArray();
        if (! $request->user()?->canDo('hazards.view')) {
            $closeGatePayload['requirements'] = collect($closeGatePayload['requirements'])
                ->map(function (array $requirement): array {
                    if (str_starts_with($requirement['href'], '/health-safety/')) {
                        $requirement['href'] = null;
                    }

                    return $requirement;
                })
                ->values()
                ->all();
        }

        // Medication error that raised / was linked to this incident, so the
        // incident side carries a back-link into the eMAR error report.
        $medicationError = MedicationError::query()
            ->where('client_incident_id', $incident->id)
            ->with('medication:id,name')
            ->first();

        return [
            'id' => $incident->id,
            'ref' => $incident->reference_number,
            'type' => $incident->type,
            'source' => $incident->source,
            'interactive' => $incident->interactive,
            'severity' => $incident->severity,
            'status' => $incident->status,
            'occurred_at' => $incident->occurred_at,
            'description' => $incident->description,
            'immediate_action_taken' => $incident->immediate_action_taken,
            'witnesses' => $incident->witnesses,
            'is_notifiable' => $journeyRepairRequired
                ? false
                : ($hsEvent
                ? (bool) $hsEvent->worksafe_notifiable
                : (bool) $incident->is_notifiable),
            'worksafe_notification_status' => $journeyRepairRequired
                ? null
                : ($hsEvent
                ? $hsEvent->worksafe_status
                : $incident->worksafe_notification_status),
            'worksafe_notified_at' => $journeyRepairRequired
                ? null
                : ($hsEvent
                ? $hsEvent->worksafe_notified_at
                : $incident->worksafe_notified_at),
            'worksafe_reference' => $journeyRepairRequired
                ? null
                : ($hsEvent
                ? $hsEvent->worksafe_reference
                : $incident->worksafe_reference),
            'journey_repair_required' => $journeyRepairRequired,
            'potential_severity' => $incident->potential_severity,
            'potential_consequence' => $incident->potential_consequence,
            'investigation_status' => $incident->investigation_status,
            'submitted_at' => $incident->submitted_at,
            'reviewed_at' => $incident->reviewed_at,
            'review_notes' => $incident->review_notes,
            'closed_at' => $incident->closed_at,
            'closed_outcome' => $incident->closed_outcome,
            'closed_notes' => $incident->closed_notes,
            'reopened_at' => $incident->reopened_at,
            'reopened_reason' => $incident->reopened_reason,
            'control_room_alert_id' => $linkedControlRoomAlert?->id,
            'client' => $incident->client ? [
                'id' => $incident->client->id,
                'first_name' => $incident->client->first_name,
                'last_name' => $incident->client->last_name,
                'site' => $incident->site?->name
                    ?? $hsEvent?->site?->name
                    ?? $incident->client->site?->name,
            ] : null,
            'reporter' => $incident->reporter ? ['name' => $incident->reporter->name, 'email' => $incident->reporter->email] : null,
            'investigator' => $incident->investigator?->name,
            'attachments' => $incident->attachments->map(fn (ClientIncidentAttachment $att) => [
                'id' => $att->id,
                'name' => $att->original_name,
                'mime' => $att->mime ?? $att->mime_type,
                'size' => $att->size,
                'portal_visible' => (bool) $att->portal_visible,
                'notes' => $att->notes,
                'uploaded_by' => $att->uploader?->name,
                'created_at' => $att->created_at,
                'download_url' => "/incidents/{$incident->id}/attachments/{$att->id}/download",
            ])->values(),
            'followups' => $incident->followups->map(fn (IncidentFollowup $f) => [
                'id' => $f->id,
                'notes' => $f->notes,
                'assigned_to' => $f->assignedTo?->name,
                'due_at' => $f->due_at,
                'completed_at' => $f->completed_at,
                'created_by' => $f->creator?->name,
                'overdue' => $f->due_at && ! $f->completed_at ? $f->due_at->isPast() : false,
            ])->values(),
            'medication_error' => $medicationError ? [
                'id' => $medicationError->id,
                'error_type' => $medicationError->error_type,
                'severity' => $medicationError->severity,
                'status' => $medicationError->status,
                'medication' => $medicationError->medication?->name,
                'reported_at' => $medicationError->reported_at,
                'url' => '/emar/errors',
            ] : null,
            'control_room_alert' => $linkedControlRoomAlert ? [
                'id' => $linkedControlRoomAlert->id,
                'status' => $linkedControlRoomAlert->status,
                'severity' => $linkedControlRoomAlert->severity,
                'alert_type' => $linkedControlRoomAlert->alert_type,
                'triggered_at' => $linkedControlRoomAlert->triggered_at,
                'resolved_at' => $linkedControlRoomAlert->resolved_at,
                'url' => data_get($linkedOperationalEvidence, 'source.href'),
            ] : null,
            'linked_operational_evidence' => $linkedOperationalEvidence,
            'close_gate' => $closeGatePayload,
            'journey_state' => $journeyRepairRequired
                ? 'Journey repair required'
                : $this->journeyPresenter->journeyState(
                    $incident,
                    $linkedControlRoomAlert,
                    $hsEvent,
                ),
            'hs_event' => $hsEvent ? [
                'id' => $hsEvent->id,
                'reference_number' => $hsEvent->reference_number,
                'status' => $hsEvent->status,
                'url' => $canOpenHsEvent
                    ? "/health-safety/events/{$hsEvent->id}"
                    : null,
                'corrective_actions_url' => $canOpenHsEvent
                    ? "/health-safety/corrective-actions?event={$hsEvent->id}"
                    : null,
                'worksafe_notifiable' => (bool) $hsEvent->worksafe_notifiable,
                'worksafe_status' => $hsEvent->worksafe_status,
                'worksafe_reference' => $hsEvent->worksafe_reference,
                'worksafe_notified_at' => $hsEvent->worksafe_notified_at?->toIso8601String(),
                'worksafe_acknowledged_at' => $hsEvent->worksafe_acknowledged_at?->toIso8601String(),
                'handover' => [
                    'status' => $hsEvent->handover_status,
                    'owner' => $hsEvent->owner ? [
                        'id' => $hsEvent->owner->id,
                        'name' => $hsEvent->owner->name,
                    ] : null,
                    'accepted_by' => $hsEvent->acceptedBy ? [
                        'id' => $hsEvent->acceptedBy->id,
                        'name' => $hsEvent->acceptedBy->name,
                    ] : null,
                    'accepted_at' => $hsEvent->accepted_at?->toIso8601String(),
                    'notes' => $hsEvent->acceptance_notes,
                    'can_accept' => false,
                ],
                'investigation_required' => (bool) $hsEvent->investigation_required,
                'investigation' => $inv ? [
                    'reference_number' => $inv->reference_number,
                    'status' => $inv->status,
                    'methodology' => $inv->methodology,
                    'root_causes' => $inv->root_causes,
                    'contributing_factors' => $inv->contributing_factors,
                    'recommendations' => $inv->recommendations,
                    'lessons_learned' => $inv->lessons_learned,
                ] : null,
                'corrective_actions' => $hsEvent->correctiveActions->map(fn ($ca) => [
                    'id' => $ca->id,
                    'reference_number' => $ca->reference_number,
                    'title' => $ca->title,
                    'status' => $ca->status,
                    'priority' => $ca->priority,
                    'assigned_to' => $ca->assignedTo?->name,
                    'due_date' => $ca->due_date,
                ])->values(),
            ] : null,
            // X1: safeguarding concern(s) spawned from this incident (e.g. abuse/neglect
            // auto-escalation). Need-to-know — redacted unless the viewer can view the concern.
            'safeguarding_concerns' => $incident->safeguardingConcerns->map(function ($concern) use ($user) {
                $canView = $user->can('view', $concern);

                return [
                    'id' => $concern->id,
                    'reference_number' => $canView ? $concern->reference_number : null,
                    'status' => $canView ? $concern->status : null,
                    'severity' => $canView ? $concern->severity : null,
                    'can_view' => $canView,
                ];
            })->values()->all(),
            // F1: the originating fleet/asset incident, when this client incident came
            // from a transport-incident cascade (residents aboard). Reciprocal of the
            // fleet detail's "Linked records".
            'fleet_incident' => $incident->fleetIncident ? [
                'id' => $incident->fleetIncident->id,
                'reference' => $incident->fleetIncident->reference(),
                'type' => $incident->fleetIncident->incident_type,
            ] : null,
            // Reciprocal of the restraint→incident link: restraint events recorded
            // against this incident (RestraintEvent.related_incident_id).
            'restraint_events' => $incident->restraintEvents->map(fn ($e) => [
                'id' => $e->id,
                'reference' => $e->reference_number ?? 'RE-'.str_pad((string) $e->id, 3, '0', STR_PAD_LEFT),
                'restraint_type' => $e->restraint_type,
                'severity' => $e->severity,
                'within_support_plan' => (bool) $e->within_support_plan,
                'injury_occurred' => (bool) $e->injury_occurred,
            ])->values()->all(),
            // First-aid treatments escalated to / linked with this incident — reciprocal
            // of the first-aid register's incident link (FirstAidRecord.related_incident_id).
            'first_aid_records' => $incident->firstAidRecords->map(fn ($r) => [
                'id' => $r->id,
                'reference' => $r->reference_number ?? 'FA-'.str_pad((string) $r->id, 4, '0', STR_PAD_LEFT),
                'person' => $r->treated_person_name,
                'injury' => $r->injury_illness_type,
                'treatment_date' => $r->treatment_date?->toISOString(),
                'ambulance_called' => (bool) $r->ambulance_called,
            ])->values(),
            'can' => [
                'update' => $user->can('update', $incident),
                'submit' => $user->can('submit', $incident),
                'review' => $user->can('review', $incident),
                'close' => $user->can('close', $incident),
                'reopen' => $user->can('reopen', $incident),
                'followupsManage' => $user->canDo('incidents.followups.manage'),
                'followupsComplete' => $user->canDo('incidents.followups.complete') || $user->canDo('incidents.followups.manage'),
                'portalManage' => $user->canDo('incidents.portal.manage'),
                'raiseCorrectiveAction' => $canRaiseCorrectiveAction,
            ],
            // Follow-up assignees remain broader than the site-scoped H&S owner
            // list used by the corrective-action form.
            'assignable_staff' => ($user->canDo('incidents.followups.manage') || $user->canDo('incidents.viewAny') || $user->canDo('compliance.view') || $user->canDo('hazards.view'))
                ? User::staff()->orderBy('name')->get(['id', 'name'])
                : [],
            'corrective_action_owners' => $correctiveActionOwners,
        ];
    }

    /**
     * The report flow is now a modal-first wizard living over the register, so
     * /incidents/create redirects to the index with a `report=` param (plus any
     * prefill) that auto-opens the wizard. Resuming a draft is now editing it, so
     * `?incident=` opens the detail dialog. Keeps the /my-day + rostering deep
     * links (?shift_id / ?client_id) working.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user?->canDo('incidents.create'), 403);

        if ($request->filled('incident')) {
            return redirect()->route('incidents.index', ['incident' => (int) $request->query('incident')]);
        }

        $params = ['report' => $request->query('type') === 'near_miss' ? 'near_miss' : 'incident'];

        if ($request->filled('shift_id')) {
            $shiftQuery = Shift::query()->with('client:id,site_id');
            app(UserSiteAccessService::class)->applyShiftScope(
                $shiftQuery,
                $user,
                $this->incidentReportSiteBypassPermissions(),
            );
            $shift = $shiftQuery->find((int) $request->query('shift_id'));

            if (
                $shift
                && (
                    (int) $shift->user_id === (int) $user->id
                    || $user->canDo('incidents.viewAny')
                    || $this->canReportAcrossHealthSafety($user)
                )
            ) {
                $params['report_shift_id'] = $shift->id;
                if ($shift->client_id) {
                    $params['report_client_id'] = (int) $shift->client_id;
                }
            }
        }
        if ($request->filled('client_id') && ! isset($params['report_shift_id'])) {
            $clientQuery = Client::query();
            app(UserSiteAccessService::class)->applyClientScope(
                $clientQuery,
                $user,
                $this->incidentReportSiteBypassPermissions(),
            );
            $client = $clientQuery->find((int) $request->query('client_id'));
            if ($client
                && ($user->can('view', $client) || $this->canReportAcrossHealthSafety($user))
            ) {
                $params['report_client_id'] = (int) $client->id;
            }
        }

        return redirect()->route('incidents.index', $params);
    }

    public function store(
        Request $request,
        NotifiableEventClassifier $classifier,
        IncidentJourneyService $journeys,
    ) {
        $actor = $request->user();
        abort_unless($actor?->canDo('incidents.create'), 403);
        $canManageFollowups = $actor->canDo('incidents.followups.manage');

        $data = $request->validate([
            'intent' => ['required', 'in:draft,submit'],
            'report_request_uuid' => ['nullable', 'uuid'],
            'incident_id' => [
                'nullable',
                'integer',
                Rule::prohibitedIf($request->filled('report_request_uuid')),
            ],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'template_id' => ['nullable', 'integer', 'exists:incident_templates,id'],
            'type' => ['required', 'string', 'max:120'],
            'severity' => ['required', 'in:low,medium,high'],
            'reported_severity' => ['nullable', 'in:critical'],
            'occurred_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'requires_followup' => ['sometimes', 'boolean'],
            'immediate_action_taken' => [
                Rule::requiredIf(fn () => $request->input('intent') === 'submit'
                    && (
                        $request->input('severity') === 'high'
                        || $request->input('reported_severity') === 'critical'
                    )
                ),
                'nullable',
                'string',
                'max:5000',
            ],
            'witnesses' => ['nullable', 'string'],
            'harm_or_injury' => ['nullable', 'string', 'max:2000'],
            'consequence' => ['nullable', 'string', 'max:2000'],

            // Near-miss fields
            'potential_severity' => ['nullable', 'in:low,medium,high,critical'],
            'potential_consequence' => ['nullable', 'string'],

            // Injury details
            'injured_person_name' => ['nullable', 'string', 'max:255'],
            'injured_person_role' => ['nullable', 'in:staff,client,visitor,contractor'],
            'injured_person_age' => ['nullable', 'integer', 'min:0', 'max:150'],
            'injury_body_part' => ['nullable', 'string', 'max:255'],
            'injury_nature' => ['nullable', 'in:fracture,burn,laceration,sprain,bruising,concussion,poisoning,other'],
            'injury_classification' => ['nullable', 'in:minor,moderate,serious,notifiable'],
            'medical_treatment_type' => ['nullable', 'in:none,first_aid,medical_centre,hospital,ambulance'],

            // WorkSafe
            'is_notifiable' => ['sometimes', 'boolean'],
            'site_preserved' => ['sometimes', 'boolean'],
            'worksafe_reference' => ['nullable', 'string', 'max:255'],
            'worksafe_notification_status' => ['nullable', 'in:pending,notified,acknowledged'],

            // Report-wizard extras
            'hazard' => ['nullable', 'string'],
            'followups' => ['nullable', 'array'],
            'followups.*.notes' => ['required_with:followups', 'string'],
            'followups.*.assigned_to_user_id' => $canManageFollowups
                ? ['nullable', 'integer', 'exists:users,id']
                : ['prohibited'],
            'followups.*.due_at' => ['nullable', 'date'],
        ]);

        $client = Client::query()->findOrFail($data['client_id']);
        $healthSafetyFallback = $this->canReportAcrossHealthSafety($actor);
        abort_unless($actor->can('view', $client) || $healthSafetyFallback, 403);

        app(UserSiteAccessService::class)->assertCanAccessClientId(
            $actor,
            $client->id,
            $this->incidentReportSiteBypassPermissions(),
        );

        [$shift, $siteId] = $this->validatedIncidentContext(
            $actor,
            $client,
            $data,
        );

        // Server-side notifiable enforcement (NZ HSWA, G2) is escalate-only.
        $reportedHarm = trim((string) ($data['harm_or_injury'] ?? ''));
        $normalisedHarm = strtolower(str_replace([' ', '-'], '_', $reportedHarm));
        $reportedTreatment = match ($normalisedHarm) {
            'first_aid', 'first_aid_only' => 'first_aid',
            'medical', 'medical_treatment', 'medical_centre' => 'medical_centre',
            'hospital', 'hospitalisation', 'death' => 'hospital',
            default => null,
        };
        $medicalTreatmentType = $data['medical_treatment_type'] ?? $reportedTreatment;
        $injuryClassification = $data['injury_classification'] ?? (
            in_array($normalisedHarm, ['hospital', 'hospitalisation', 'death'], true)
                || ($data['reported_severity'] ?? null) === 'critical'
                ? 'notifiable'
                : null
        );
        $harmProxy = in_array($medicalTreatmentType, ['hospital', 'ambulance'], true)
            ? NotifiableEventClassifier::HARM_HOSPITALISATION
            : null;
        $severityProxy = $injuryClassification === 'notifiable'
            ? NotifiableEventClassifier::SEVERITY_CRITICAL
            : null;
        $isNotifiable = $classifier->isNotifiable($harmProxy, $severityProxy)
            || (bool) ($data['is_notifiable'] ?? false);
        $isSubmit = $data['intent'] === 'submit';
        $worksafeStatus = $isNotifiable
            ? ($data['worksafe_notification_status'] ?? HsEvent::WORKSAFE_PENDING)
            : null;
        $metadata = $this->incidentReportMetadata($data);
        $storedSeverity = ($data['reported_severity'] ?? null) === HsEvent::SEVERITY_CRITICAL
            ? 'high'
            : $data['severity'];

        $attributes = [
            'report_request_uuid' => $data['report_request_uuid'] ?? null,
            'client_id' => $client->id,
            'site_id' => $siteId,
            'reported_by' => $actor->id,
            'shift_id' => $shift?->id,
            'template_id' => $data['template_id'] ?? null,
            'type' => $data['type'],
            'severity' => $storedSeverity,
            'status' => $isSubmit ? 'submitted' : 'draft',
            'submitted_at' => $isSubmit ? now() : null,
            'source' => 'manual',
            'occurred_at' => $data['occurred_at'] ?? now(),
            'description' => $data['description'] ?? null,
            'requires_followup' => (bool) ($data['requires_followup'] ?? false) || ! empty($data['followups']),
            'immediate_action_taken' => filled($data['immediate_action_taken'] ?? null)
                ? trim((string) $data['immediate_action_taken'])
                : null,
            'witnesses' => $data['witnesses'] ?? null,
            'title' => $data['type'].' incident',
            'metadata' => $metadata,
            'potential_severity' => $data['potential_severity'] ?? null,
            'potential_consequence' => $data['consequence'] ?? $data['potential_consequence'] ?? null,
            'injured_person_name' => $data['injured_person_name'] ?? null,
            'injured_person_role' => $data['injured_person_role'] ?? null,
            'injured_person_age' => $data['injured_person_age'] ?? null,
            'injury_body_part' => $data['injury_body_part'] ?? null,
            'injury_nature' => $data['injury_nature'] ?? null,
            'injury_classification' => $injuryClassification,
            'medical_treatment_type' => $medicalTreatmentType,
            'is_notifiable' => $isNotifiable,
            'site_preserved' => (bool) ($data['site_preserved'] ?? false),
            'worksafe_reference' => $data['worksafe_reference'] ?? null,
            'worksafe_notification_status' => $worksafeStatus,
            'worksafe_notified_at' => in_array($worksafeStatus, [HsEvent::WORKSAFE_NOTIFIED, HsEvent::WORKSAFE_ACKNOWLEDGED], true)
                ? now()
                : null,
        ];

        [$incident, $journey, $created, $submittedNow] = DB::transaction(
            function () use ($actor, $attributes, $client, $data, $isSubmit, $journeys, $shift, $siteId): array {
                $created = false;
                $submittedNow = false;
                $journey = null;

                if (! empty($data['report_request_uuid'])) {
                    $requestUuid = (string) $data['report_request_uuid'];
                    $incident = ClientIncident::query()
                        ->where('report_request_uuid', $requestUuid)
                        ->lockForUpdate()
                        ->first();

                    if (! $incident) {
                        $createAttributes = $attributes;
                        unset($createAttributes['report_request_uuid']);
                        $incident = ClientIncident::query()->createOrFirst(
                            ['report_request_uuid' => $requestUuid],
                            $createAttributes,
                        );
                        $created = $incident->wasRecentlyCreated;

                        if (! $created) {
                            $incident = ClientIncident::query()
                                ->where('report_request_uuid', $requestUuid)
                                ->lockForUpdate()
                                ->firstOrFail();
                        }
                    }

                    if (! $created) {
                        $this->assertCanReuseIncidentReport($actor, $incident);
                        $this->assertIncidentIdentity($incident, $client, $shift, $siteId);
                    }
                } elseif (! empty($data['incident_id'])) {
                    $incident = ClientIncident::query()
                        ->whereKey((int) $data['incident_id'])
                        ->lockForUpdate()
                        ->first();

                    if (! $incident) {
                        throw ValidationException::withMessages([
                            'incident_id' => 'The saved incident is not available.',
                        ]);
                    }

                    $this->assertCanReuseIncidentReport($actor, $incident);
                    $this->assertIncidentIdentity($incident, $client, $shift, $siteId);
                } else {
                    $incident = ClientIncident::query()->create($attributes);
                    $created = true;
                }

                if (! $created) {
                    if ($incident->status === 'submitted' && $isSubmit) {
                        $this->recordMissingImmediateActionForSubmission($incident, $data);
                        $journey = $journeys->ensureForSubmittedIncident($incident, $actor);

                        return [$journey->incident, $journey, false, false];
                    }

                    if ($incident->status !== 'draft') {
                        throw ValidationException::withMessages([
                            (! empty($data['report_request_uuid']) ? 'report_request_uuid' : 'incident_id') => 'Only a saved draft can be reused for this action.',
                        ]);
                    }

                    $updateAttributes = $attributes;
                    unset($updateAttributes['report_request_uuid']);
                    $incident->fill($updateAttributes);
                    $incident->save();
                    $submittedNow = $isSubmit;
                } else {
                    $submittedNow = $isSubmit;
                }

                if ($submittedNow && preg_match('/abuse|neglect/i', $incident->type)) {
                    SafeguardingConcern::query()->firstOrCreate([
                        'related_incident_id' => $incident->id,
                        'concern_type' => 'incident_escalation',
                    ], [
                        'subject_type' => Client::class,
                        'subject_id' => $client->id,
                        'subject_name' => $client->first_name.' '.$client->last_name,
                        'severity' => $incident->severity,
                        'description' => $incident->description,
                        'occurred_at' => $incident->occurred_at,
                        'reported_by_user_id' => $actor->id,
                        'reported_by_name' => $actor->name,
                        'status' => 'reported',
                        'requires_external_referral' => true,
                        'site_id' => $siteId,
                        'created_by' => $actor->id,
                    ]);
                }

                if ($created) {
                    foreach ($data['followups'] ?? [] as $followup) {
                        $incident->followups()->create([
                            'notes' => $followup['notes'],
                            'assigned_to_user_id' => $followup['assigned_to_user_id'] ?? null,
                            'due_at' => $followup['due_at'] ?? null,
                            'created_by' => $actor->id,
                        ]);
                    }
                }

                if ($isSubmit) {
                    $journey = $journeys->ensureForSubmittedIncident($incident, $actor);
                    $incident = $journey->incident;
                }

                return [$incident->fresh(), $journey, $created, $submittedNow];
            },
            3,
        );

        if ($submittedNow) {
            $this->notifyIncidentSubmitted($request, $incident);
        }

        $result = $this->incidentReportResult($incident, $journey);
        $message = $isSubmit ? 'Incident submitted.' : 'Draft saved.';

        if ($request->boolean('stay')) {
            return back()->with('success', $message)->with('incident_report_result', $result);
        }

        if ($request->boolean('continue_wizard') && ! $isSubmit) {
            return redirect()
                ->route('incidents.create', ['incident' => $incident->id])
                ->with('success', 'Draft saved. Add any extra detail below.')
                ->with('incident_report_result', $result);
        }

        return redirect()
            ->route('incidents.show', $incident)
            ->with('success', $message)
            ->with('incident_report_result', $result);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Shift|null, 1: int|null}
     */
    private function validatedIncidentContext(
        User $actor,
        Client $client,
        array $data,
    ): array {
        $siteAccess = app(UserSiteAccessService::class);
        $bypassPermissions = $this->incidentReportSiteBypassPermissions();
        $clientSiteId = $client->site_id ? (int) $client->site_id : null;
        $suppliedSiteId = isset($data['site_id']) ? (int) $data['site_id'] : null;

        if ($suppliedSiteId) {
            $siteAccess->assertCanAccessSiteId($actor, $suppliedSiteId, $bypassPermissions);
        }

        $shift = isset($data['shift_id'])
            ? Shift::query()->with('client:id,site_id')->find((int) $data['shift_id'])
            : null;

        if (isset($data['shift_id']) && ! $shift) {
            throw ValidationException::withMessages([
                'shift_id' => 'The selected shift is not available for this incident.',
            ]);
        }

        if ($shift) {
            $siteAccess->assertCanAccessShift($actor, $shift, $bypassPermissions);

            if (
                (int) $shift->client_id !== (int) $client->id
                || (int) $shift->user_id !== (int) $actor->id
            ) {
                throw ValidationException::withMessages([
                    'shift_id' => 'The selected shift is not available for this incident.',
                ]);
            }
        }

        $shiftSiteId = $shift ? $siteAccess->shiftSiteId($shift) : null;
        if ($shift && $clientSiteId !== null && $shiftSiteId !== null && $clientSiteId !== $shiftSiteId) {
            throw ValidationException::withMessages([
                'shift_id' => 'The selected shift is not available for this incident.',
            ]);
        }

        $resolvedSiteId = $shift ? $shiftSiteId : $clientSiteId;
        if ($suppliedSiteId !== null && $suppliedSiteId !== $resolvedSiteId) {
            throw ValidationException::withMessages([
                'site_id' => 'The selected site does not match this incident.',
            ]);
        }

        $siteAccess->assertCanAccessSiteId($actor, $resolvedSiteId, $bypassPermissions);

        return [$shift, $resolvedSiteId];
    }

    private function assertIncidentIdentity(
        ClientIncident $incident,
        Client $client,
        ?Shift $shift,
        ?int $siteId,
    ): void {
        if ((int) $incident->client_id !== (int) $client->id) {
            throw ValidationException::withMessages([
                'client_id' => 'The saved incident belongs to a different client.',
            ]);
        }

        if (($incident->shift_id ? (int) $incident->shift_id : null) !== ($shift?->id ? (int) $shift->id : null)) {
            throw ValidationException::withMessages([
                'shift_id' => 'The saved incident belongs to a different shift.',
            ]);
        }

        if (($incident->site_id ? (int) $incident->site_id : null) !== $siteId) {
            throw ValidationException::withMessages([
                'site_id' => 'The saved incident belongs to a different site.',
            ]);
        }
    }

    private function assertCanReuseIncidentReport(
        User $actor,
        ClientIncident $incident,
    ): void {
        abort_unless(
            (int) $incident->reported_by === (int) $actor->id
            && ($actor->can('view', $incident) || $this->canReportAcrossHealthSafety($actor)),
            403,
        );
    }

    /** @param array<string, mixed> $data */
    private function incidentReportMetadata(array $data): ?array
    {
        $metadata = [];

        if (filled($data['hazard'] ?? null)) {
            $metadata['hazard'] = $data['hazard'];
        }

        if (filled($data['harm_or_injury'] ?? null)) {
            $metadata['reported_harm_or_injury'] = trim((string) $data['harm_or_injury']);
        }

        if (($data['reported_severity'] ?? null) === HsEvent::SEVERITY_CRITICAL) {
            $metadata['journey']['original_alert_severity'] = HsEvent::SEVERITY_CRITICAL;
        }

        return $metadata === [] ? null : $metadata;
    }

    /**
     * A serious submission must carry the reporter's actual immediate response.
     * A retry may repair a missing historical value, but must never replace one.
     *
     * @param  array<string, mixed>  $input
     */
    private function recordMissingImmediateActionForSubmission(
        ClientIncident $incident,
        array $input = [],
    ): void {
        if (blank($incident->immediate_action_taken)) {
            $providedAction = trim((string) ($input['immediate_action_taken'] ?? ''));

            if ($providedAction !== '') {
                $incident->forceFill([
                    'immediate_action_taken' => $providedAction,
                ])->save();
            }
        }

        if (
            in_array($incident->severity, ['high', 'critical'], true)
            && blank($incident->immediate_action_taken)
        ) {
            throw ValidationException::withMessages([
                'immediate_action_taken' => 'Record the immediate action taken before submitting a high or critical incident.',
            ]);
        }
    }

    /** @return array<string, string> */
    private function incidentReportResult(
        ClientIncident $incident,
        ?IncidentJourney $journey,
    ): array {
        $result = [
            'result' => $incident->status === 'draft' ? 'draft' : 'submitted',
            'incident_reference' => (string) $incident->reference_number,
            'incident_url' => route('incidents.show', $incident),
        ];

        if (! $journey?->hsEvent) {
            return $result;
        }

        $result['hs_reference'] = (string) $journey->hsEvent->reference_number;
        $result['handover_state'] = match ($journey->hsEvent->handover_status) {
            HsEvent::HANDOVER_AWAITING_ACCEPTANCE => 'awaiting_hs_acceptance',
            HsEvent::HANDOVER_ACCEPTED => 'accepted_by_hs',
            default => (string) $journey->hsEvent->handover_status,
        };

        if ($journey->alert?->reference_number) {
            $result['alert_reference'] = (string) $journey->alert->reference_number;
        }

        return $result;
    }

    private function notifyIncidentSubmitted(Request $request, ClientIncident $incident): void
    {
        $incident->loadMissing(['client:id,first_name,last_name']);
        $client = $incident->client;

        app(NotificationService::class)->notifyCrud(
            $request->user(),
            'submitted',
            'incident',
            $incident,
            $client,
            [
                'event_key' => 'incidents.submitted',
                'severity' => $incident->severity,
                'title' => 'Incident submitted for review',
                'body' => "Client: {$client->first_name} {$client->last_name}\nType: {$incident->type}\nSeverity: {$incident->severity}",
                'url' => url("/incidents/{$incident->id}"),
                'include_entity_user' => false,
            ],
        );

        if ($incident->severity !== 'high') {
            return;
        }

        app(NotificationService::class)->notifyCrud(
            $request->user(),
            'submitted',
            'incident',
            $incident,
            $client,
            [
                'event_key' => 'incidents.high_severity_alert',
                'severity' => $incident->severity,
                'title' => 'High severity incident submitted',
                'body' => "Client: {$client->first_name} {$client->last_name}\nType: {$incident->type}\nSeverity: {$incident->severity}",
                'url' => url("/incidents/{$incident->id}"),
                'include_assigned_workers' => false,
                'include_entity_user' => false,
                'include_managers' => false,
            ],
        );
    }

    private function canReportAcrossHealthSafety(User $user): bool
    {
        return $user->canDo('incidents.create')
            && $user->canDo('healthSafety.viewAllSites');
    }

    /** @return array<int, string> */
    private function healthSafetySiteBypassPermissions(): array
    {
        return ['healthSafety.viewAllSites'];
    }

    /** @return array<int, string> */
    private function incidentReportSiteBypassPermissions(): array
    {
        return ['healthSafety.viewAllSites', 'reports.viewAny'];
    }

    /**
     * Deep-link / shareable view of a single incident. The full editable surface is
     * the IncidentDetailDialog; this is now a thin shell rendering the same modal
     * content (no navigate-away page). Reuses the shared detail payload.
     */
    public function show(Request $request, ClientIncident $incident)
    {
        $this->authorize('view', $incident);

        return inertia('incidents/show', [
            'detail' => $this->buildIncidentDetail($request, $incident->id),
        ]);
    }

    public function update(Request $request, ClientIncident $incident)
    {
        $this->authorize('update', $incident);

        $user = $request->user();

        // The detail dialog mixes full edits with smaller partial updates (review
        // notes, portal sharing), so preserve the existing core values when those
        // fields are omitted from non-empty partial requests. A truly empty update
        // should still surface required-field validation.
        if ($request->except(['_token', '_method']) !== []) {
            $request->merge([
                'type' => $request->input('type', $incident->type),
                'severity' => $request->input('severity', $incident->severity),
            ]);
        }

        // Audit guardrail: once submitted/reviewed, lock core incident fields.
        // Managers can still add review notes and manage portal visibility.
        $coreLocked = in_array($incident->status, ['submitted', 'reviewed'], true);

        $data = $request->validate([
            'type' => ['required', 'string', 'max:120'],
            'severity' => ['required', 'in:low,medium,high'],
            'occurred_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'requires_followup' => ['sometimes', 'boolean'],
            'immediate_action_taken' => ['nullable', 'string'],
            'witnesses' => ['nullable', 'string'],

            // review fields (manager/admin)
            'review_notes' => ['nullable', 'string'],

            // portal sharing (manager/admin)
            'portal_visible' => ['sometimes', 'boolean'],

            // Near-miss fields
            'potential_severity' => ['nullable', 'in:low,medium,high,critical'],
            'potential_consequence' => ['nullable', 'string'],

            // Injury details
            'injured_person_name' => ['nullable', 'string', 'max:255'],
            'injured_person_role' => ['nullable', 'in:staff,client,visitor,contractor'],
            'injured_person_age' => ['nullable', 'integer', 'min:0', 'max:150'],
            'injury_body_part' => ['nullable', 'string', 'max:255'],
            'injury_nature' => ['nullable', 'in:fracture,burn,laceration,sprain,bruising,concussion,poisoning,other'],
            'injury_classification' => ['nullable', 'in:minor,moderate,serious,notifiable'],
            'medical_treatment_type' => ['nullable', 'in:none,first_aid,medical_centre,hospital,ambulance'],

            // WorkSafe
            'is_notifiable' => ['sometimes', 'boolean'],

            // NOTE (Option B): investigation + corrective-action / root-cause /
            // lessons-learned editing has moved to the Health & Safety register
            // (HsInvestigation / HsCorrectiveAction). The incident no longer accepts
            // those fields — they are surfaced read-only on the detail from H&S.
        ]);

        // If reporter is editing, do not allow review fields / portal visibility to be overwritten
        if ($user && $incident->isEditableByReporter($user) && ! $user->canDo('incidents.viewAny')) {
            unset($data['review_notes']);
            unset($data['portal_visible']);
        }

        if ($user && ! $user->canDo('incidents.portal.manage')) {
            unset($data['portal_visible']);
        }

        if ($coreLocked) {
            // After submission only manager-only fields (review notes / portal) stay
            // editable; core, injury and near-miss details are locked for audit.
            foreach ([
                'type', 'severity', 'occurred_at', 'description', 'requires_followup', 'immediate_action_taken', 'witnesses',
                'potential_severity', 'potential_consequence',
                'injured_person_name', 'injured_person_role', 'injured_person_age',
                'injury_body_part', 'injury_nature', 'injury_classification', 'medical_treatment_type',
                'is_notifiable',
            ] as $field) {
                unset($data[$field]);
            }
        }

        $incident->update([
            ...$data,
            'title' => ($data['type'] ?? $incident->type).' incident',
        ]);

        return back()->with('success', 'Incident updated.');
    }

    public function updateAttachment(Request $request, ClientIncident $incident, ClientIncidentAttachment $attachment)
    {
        $this->authorize('view', $incident);
        abort_unless($request->user()?->canDo('incidents.portal.manage'), 403);

        abort_unless((int) $attachment->incident_id === (int) $incident->id, 404);

        $data = $request->validate([
            'portal_visible' => ['required', 'boolean'],
        ]);

        $attachment->update([
            'portal_visible' => (bool) $data['portal_visible'],
        ]);

        return back()->with('success', 'Attachment sharing updated.');
    }

    public function submit(
        Request $request,
        ClientIncident $incident,
        IncidentJourneyService $journeys,
    ) {
        $actor = $request->user();
        abort_unless($actor, 403);

        if ($incident->status === 'submitted') {
            abort_unless(
                $actor->canDo('incidents.submit')
                && (int) $incident->reported_by === (int) $actor->id
                && $actor->can('view', $incident),
                403,
            );
        } else {
            $this->authorize('submit', $incident);
        }

        $submission = $request->validate([
            'immediate_action_taken' => ['nullable', 'string', 'max:5000'],
        ]);

        $siteAccess = app(UserSiteAccessService::class);
        $siteBypassPermissions = $this->incidentReportSiteBypassPermissions();
        $siteAccess->assertCanAccessClientIncident($actor, $incident, $siteBypassPermissions);

        [$incident, $journey, $submittedNow] = DB::transaction(
            function () use ($actor, $incident, $journeys, $siteAccess, $siteBypassPermissions, $submission): array {
                $locked = ClientIncident::query()
                    ->whereKey($incident->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless(
                    $actor->canDo('incidents.submit')
                    && (int) $locked->reported_by === (int) $actor->id
                    && $actor->can('view', $locked),
                    403,
                );

                $siteAccess->assertCanAccessClientIncident($actor, $locked, $siteBypassPermissions);

                if ($locked->status === 'submitted') {
                    $this->recordMissingImmediateActionForSubmission($locked, $submission);
                    $journey = $journeys->ensureForSubmittedIncident($locked, $actor);

                    return [$journey->incident, $journey, false];
                }

                abort_unless(
                    $locked->status === 'draft'
                    && empty($locked->submitted_at)
                    && ! $locked->isShiftLinked(),
                    403,
                );

                $locked->loadMissing(['client:id,site_id', 'shift.client:id,site_id']);
                $siteId = $locked->shift
                    ? app(UserSiteAccessService::class)->shiftSiteId($locked->shift)
                    : ($locked->client?->site_id ? (int) $locked->client->site_id : null);

                $locked->fill([
                    'site_id' => $locked->site_id ?: $siteId,
                    'status' => 'submitted',
                    'submitted_at' => now(),
                ])->save();

                $this->recordMissingImmediateActionForSubmission($locked, $submission);
                $journey = $journeys->ensureForSubmittedIncident($locked, $actor);

                return [$journey->incident, $journey, true];
            },
            3,
        );

        if ($submittedNow) {
            $this->notifyIncidentSubmitted($request, $incident);
        }

        return back()
            ->with('success', 'Incident submitted.')
            ->with('incident_report_result', $this->incidentReportResult($incident, $journey));
    }

    public function review(Request $request, ClientIncident $incident)
    {
        $this->authorize('review', $incident);

        $data = $request->validate([
            'review_notes' => ['nullable', 'string'],
        ]);

        $actor = $request->user();
        abort_unless($actor, 403);
        $incident = DB::transaction(function () use ($actor, $data, $incident): ClientIncident {
            $lockedIncident = ClientIncident::query()
                ->whereKey($incident->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Re-authorize the locked record so a concurrent state change cannot
            // move a different lifecycle snapshot through this action.
            $this->authorize('review', $lockedIncident);
            abort_unless($lockedIncident->status === 'submitted', 403);

            $lockedIncident->update([
                'status' => 'reviewed',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_notes' => $data['review_notes'] ?? $lockedIncident->review_notes,
            ]);

            return $this->journeys
                ->ensureForSubmittedIncident($lockedIncident, $actor)
                ->incident;
        }, 3);

        $incident->load(['client:id,first_name,last_name']);
        $client = $incident->client;

        // Notify reporter + assigned workers that the incident has been reviewed.
        $targets = [];
        if (! empty($incident->reported_by)) {
            $targets[] = (int) $incident->reported_by;
        }

        app(NotificationService::class)->notifyCrud(
            $request->user(),
            'reviewed',
            'incident',
            $incident,
            $client,
            [
                'event_key' => 'incidents.reviewed',
                'severity' => $incident->severity,
                'title' => 'Incident reviewed',
                'url' => url("/incidents/{$incident->id}"),
                'target_user_ids' => $targets,
                'include_entity_user' => false,
            ]
        );

        return back()->with('success', 'Incident reviewed.');
    }

    public function close(Request $request, ClientIncident $incident)
    {
        $this->authorize('close', $incident);

        $data = $request->validate([
            'closed_outcome' => ['required', 'string', 'max:120'],
            'closed_notes' => ['nullable', 'string'],
        ]);

        $actor = $request->user();
        abort_unless($actor, 403);
        $siteAccess = app(UserSiteAccessService::class);
        $siteBypassPermissions = $this->incidentReportSiteBypassPermissions();
        $siteAccess->assertCanAccessClientIncident($actor, $incident, $siteBypassPermissions);
        [$incident, $closeError, $outboxId] = DB::transaction(function () use (
            $actor,
            $incident,
            $data,
            $siteAccess,
            $siteBypassPermissions,
        ): array {
            $lockedIncident = ClientIncident::query()
                ->whereKey($incident->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Every close/follow-up writer locks this parent first. Whichever
            // operation wins is visible to the other before it can continue.
            $this->authorize('close', $lockedIncident);
            $siteAccess->assertCanAccessClientIncident($actor, $lockedIncident, $siteBypassPermissions);
            abort_unless($lockedIncident->status === 'reviewed', 403);

            try {
                // Lock and canonicalise the H&S/alert parents before evaluating
                // closure. The gate is then protected from a concurrent H&S
                // transition until the source signal has been recorded.
                $journey = $this->journeys->ensureForSubmittedIncident(
                    $lockedIncident,
                    $actor,
                );
                $lockedIncident = $journey->incident;
                $gate = $this->journeys->closeGate($lockedIncident);
            } catch (\DomainException) {
                abort(404);
            }
            if (! $gate->allowed) {
                return [
                    $lockedIncident,
                    implode(' ', $gate->blockers()),
                    null,
                ];
            }

            $at = now()->startOfSecond();
            $lockedIncident->update([
                'status' => 'closed',
                'closed_by' => $actor->id,
                'closed_at' => $at,
                'closed_outcome' => $data['closed_outcome'],
                'closed_notes' => $data['closed_notes'] ?? null,
            ]);

            $outbox = $this->incidentAlertSignals->recordClose(
                $lockedIncident,
                $journey,
                $actor,
                $at,
                $data,
            );

            return [$lockedIncident, null, $outbox->id];
        }, 3);

        if ($closeError !== null) {
            return back()->with('error', $closeError);
        }

        $this->incidentAlertSignals->dispatch($outboxId);

        $incident->load(['client:id,first_name,last_name']);
        $client = $incident->client;

        // Notify reporter + assigned workers that the incident has been closed.
        $targets = [];
        if (! empty($incident->reported_by)) {
            $targets[] = (int) $incident->reported_by;
        }

        app(NotificationService::class)->notifyCrud(
            $request->user(),
            'closed',
            'incident',
            $incident,
            $client,
            [
                'event_key' => 'incidents.closed',
                'severity' => $incident->severity,
                'title' => 'Incident closed',
                'url' => url("/incidents/{$incident->id}"),
                'target_user_ids' => $targets,
                'include_entity_user' => false,
            ]
        );

        return back()->with('success', 'Incident closed. Linked journey closure can now continue.');
    }

    public function reopen(Request $request, ClientIncident $incident)
    {
        $this->authorize('reopen', $incident);

        $data = $request->validate([
            'reopened_reason' => ['required', 'string', 'max:2000'],
        ]);

        $actor = $request->user();
        abort_unless($actor, 403);
        $siteAccess = app(UserSiteAccessService::class);
        $siteBypassPermissions = $this->incidentReportSiteBypassPermissions();
        $siteAccess->assertCanAccessClientIncident($actor, $incident, $siteBypassPermissions);

        [$incident, $outboxId] = DB::transaction(function () use (
            $incident,
            $data,
            $actor,
            $siteAccess,
            $siteBypassPermissions,
        ): array {
            // The incident is the source-authoritative parent. Alert state is
            // requested through a durable signal and changed only by Control Room.
            $lockedIncident = ClientIncident::query()
                ->whereKey($incident->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($actor->can('reopen', $lockedIncident), 403);
            $siteAccess->assertCanAccessClientIncident($actor, $lockedIncident, $siteBypassPermissions);
            abort_unless($lockedIncident->status === 'closed', 403);

            $at = now()->startOfSecond();
            $lockedIncident->update([
                'status' => 'reviewed',
                'reopened_by' => $actor->id,
                'reopened_at' => $at,
                'reopened_reason' => $data['reopened_reason'],

                // Clear closure fields so the factual incident review is open.
                'closed_by' => null,
                'closed_at' => null,
                'closed_outcome' => null,
                'closed_notes' => null,
            ]);

            try {
                $journey = $this->journeys->ensureForSubmittedIncident($lockedIncident, $actor);
            } catch (\DomainException) {
                abort(404);
            }
            $outbox = $this->incidentAlertSignals->recordReopen(
                $journey->incident,
                $journey,
                $actor,
                $at,
                $data['reopened_reason'],
            );

            return [$journey->incident, $outbox->id];
        }, 3);

        $this->incidentAlertSignals->dispatch($outboxId);

        $incident->load(['client:id,first_name,last_name']);
        $client = $incident->client;

        // Notify reporter that the incident has been reopened.
        $targets = [];
        if (! empty($incident->reported_by)) {
            $targets[] = (int) $incident->reported_by;
        }

        app(NotificationService::class)->notifyCrud(
            $request->user(),
            'reopened',
            'incident',
            $incident,
            $client,
            [
                'event_key' => 'incidents.reopened',
                'severity' => $incident->severity,
                'title' => 'Incident reopened',
                'url' => url("/incidents/{$incident->id}"),
                'target_user_ids' => $targets,
                'include_entity_user' => false,
            ]
        );

        return back()->with('success', 'Incident reopened.');
    }

    /**
     * Raise a corrective action from the incident (Option B): creates an
     * HsCorrectiveAction in the H&S Corrective Actions register, linked to this
     * incident's HsEvent. No copy is stored on the incident — it is surfaced
     * read-only on the detail from the H&S register.
     */
    public function raiseCorrectiveAction(
        StoreHsCorrectiveActionRequest $request,
        ClientIncident $incident,
        HsCorrectiveActionService $service,
    ) {
        $this->authorize('view', $incident);
        $user = $request->user();
        abort_unless($user && ($user->canDo('incidents.viewAny') || $user->canDo('compliance.view') || $user->canDo('hazards.view')), 403);

        $data = $request->validated();

        $hsEvent = HsEvent::query()
            ->where('source_type', ClientIncident::class)
            ->where('source_id', $incident->id)
            ->first();

        if (! $hsEvent) {
            return back()->with('error', 'No Health & Safety event exists for this incident yet.');
        }
        if (! $hsEvent->isOpen()) {
            return back()->with('error', 'The Health & Safety event is closed; corrective actions can no longer be added.');
        }

        try {
            $service->createStandalone($hsEvent, [
                ...$data,
                'action_type' => 'corrective',
            ], $user);
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Corrective action raised in the Health & Safety register.');
    }

    public function uploadAttachment(Request $request, ClientIncident $incident)
    {
        $this->authorize('update', $incident);

        // Audit guardrail: attachments are only mutable while the incident is in draft.
        abort_unless($incident->status === 'draft', 403);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $file = $request->file('file');
        $disk = 'private';
        $path = $file->store('incident_attachments', $disk);

        ClientIncidentAttachment::create([
            'incident_id' => $incident->id,
            'uploaded_by' => $request->user()?->id,
            'disk' => $disk,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        return back()->with('success', 'Attachment uploaded.');
    }

    public function removeAttachment(Request $request, ClientIncident $incident, ClientIncidentAttachment $attachment)
    {
        $this->authorize('update', $incident);

        // Audit guardrail: attachments are only mutable while the incident is in draft.
        abort_unless($incident->status === 'draft', 403);

        abort_unless((int) $attachment->incident_id === (int) $incident->id, 404);

        // Attachments may be removed only while incident is editable by reporter (admins/managers can also remove if they can update)
        $user = $request->user();
        if ($user && ! $user->canDo('incidents.viewAny')) {
            abort_unless($incident->isEditableByReporter($user), 403);
        }

        $disk = $attachment->disk ?: 'private';
        if ($attachment->path && Storage::disk($disk)->exists($attachment->path)) {
            Storage::disk($disk)->delete($attachment->path);
        }

        $attachment->delete();

        return back()->with('success', 'Attachment removed.');
    }

    public function downloadAttachment(Request $request, ClientIncident $incident, ClientIncidentAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $incident);
        abort_unless((int) $attachment->incident_id === (int) $incident->id, 404);

        // Private disk + nosniff + CSP sandbox — see ServesPrivateAttachments.
        return $this->streamPrivateAttachment(
            $attachment->disk,
            $attachment->path,
            $attachment->original_name,
            $attachment->mime,
        );
    }

    public function downloadControlRoomEvidence(
        Request $request,
        ClientIncident $incident,
        EvidenceItem $item,
    ): StreamedResponse {
        $this->authorize('view', $incident);
        $alert = $this->journeys->journeyForIncident($incident)->alert;
        $belongsToJourney = $alert !== null
            && $item->evidencePack()
                ->where('alert_id', $alert->id)
                ->exists();

        abort_unless($belongsToJourney && filled($item->storage_path), 404);

        return $this->streamPrivateAttachment(
            'local',
            $item->storage_path,
            data_get($item->metadata, 'original_name') ?: basename($item->storage_path),
            $item->mime_type,
        );
    }
}
