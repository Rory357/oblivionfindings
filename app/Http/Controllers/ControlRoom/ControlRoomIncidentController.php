<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\AlertQueue;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\MedicationError;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ControlRoom\AlertPriorityService;
use App\Services\ControlRoom\AlertWorklistPresenter;
use App\Services\ControlRoom\AlertWorkspaceService;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ControlRoomIncidentController extends Controller
{
    /**
     * Display the unified incident tracker feed.
     */
    public function index(
        Request $request,
        AlertPriorityService $priority,
        AlertWorklistPresenter $presenter,
    ) {
        return $this->canonicalIndex($request, $priority, $presenter);
    }

    private function canonicalIndex(
        Request $request,
        AlertPriorityService $priority,
        AlertWorklistPresenter $presenter,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        $filters = $request->only([
            'lens', 'severity', 'status', 'client_id', 'site_id',
            'date_from', 'date_to', 'search', 'source_type',
        ]);
        $filters['lens'] = in_array($filters['lens'] ?? null, $this->handoverLensKeys(), true)
            ? $filters['lens']
            : 'attention';

        $siteAccess = $this->siteAccess();
        $bypassPermissions = $this->alertBypassPermissions();
        if (! empty($filters['site_id'])) {
            $siteAccess->assertCanAccessSiteId($user, (int) $filters['site_id'], $bypassPermissions);
        }
        if (! empty($filters['client_id'])) {
            $siteAccess->assertCanAccessClientId($user, (int) $filters['client_id'], $bypassPermissions);
        }

        $baseQuery = $this->canonicalJourneyQuery($user, $filters);
        $lensCounts = collect($this->handoverLensKeys())
            ->mapWithKeys(fn (string $lens) => [
                $lens => $this->applyHandoverLens(clone $baseQuery, $lens)
                    ->count('control_room_alerts.id'),
            ]);

        $journeyQuery = $this->applyHandoverLens(clone $baseQuery, $filters['lens'])
            ->with([
                'site:id,name',
                'client:id,first_name,last_name,site_id,organization_id',
                'client.site:id,name,tenant_id',
                'assignedTo:id,name,email,organization_id',
                'queue:id,name,tier',
                'sla',
                'playbookRun:id,playbook_id,status,current_step,completed_steps,total_steps',
                'playbookRun.playbook:id,name',
                'clientIncident:id,reference_number,control_room_alert_id,hs_event_id,status,severity,site_id,client_id,title,type,occurred_at',
                'hsEvent:id,reference_number,control_room_alert_id,handover_status,status,severity,owner_user_id,accepted_by_user_id,accepted_at,site_id,client_id',
                'hsEvent.owner:id,name',
                'hsEvent.acceptedBy:id,name',
            ])
            ->select('control_room_alerts.*');
        $priority->apply($journeyQuery);

        $journeys = $journeyQuery->paginate(25)->withQueryString();
        $journeys->through(fn (ControlRoomAlert $alert) => $this->presentJourney(
            $alert,
            $user,
            $presenter,
        ));

        $sitesQuery = Site::query()->orderBy('name');
        $siteAccess->applySiteScope($sitesQuery, $user, $bypassPermissions);
        $sites = $sitesQuery->get(['id', 'name']);

        $clientsQuery = Client::query()->orderBy('first_name');
        $siteAccess->applyClientScope($clientsQuery, $user, $bypassPermissions);
        $clients = $clientsQuery->get(['id', 'first_name', 'last_name'])
            ->map(fn ($client) => [
                'id' => $client->id,
                'name' => trim($client->first_name.' '.$client->last_name),
            ]);

        $lenses = collect([
            ['key' => 'attention', 'label' => 'Needs attention', 'help' => 'Operational or governance work is still open.'],
            ['key' => 'needs_incident', 'label' => 'Needs incident', 'help' => 'Create the formal incident record and H&S handover.'],
            ['key' => 'awaiting_health_safety', 'label' => 'Awaiting H&S', 'help' => 'H&S has not accepted ownership yet.'],
            ['key' => 'accepted_in_progress', 'label' => 'Accepted / in progress', 'help' => 'H&S owns the governance work while operations continue.'],
            ['key' => 'operational_complete_governance_open', 'label' => 'Operations done / H&S open', 'help' => 'The alert is complete but governance work remains.'],
            ['key' => 'complete', 'label' => 'Complete', 'help' => 'Operational and H&S work are both closed.'],
        ])->map(fn (array $lens) => $lens + [
            'count' => (int) $lensCounts->get($lens['key'], 0),
        ])->values();

        return Inertia::render('control-room/incidents', [
            'journeys' => $this->paginatorPayload($journeys),
            'incidents' => $this->legacyIncidentFeed($request, $user, $filters),
            'filters' => $filters,
            'lenses' => $lenses,
            'stats' => [
                'total' => (int) $lensCounts->get('attention', 0),
                'needs_incident' => (int) $lensCounts->get('needs_incident', 0),
                'awaiting_health_safety' => (int) $lensCounts->get('awaiting_health_safety', 0),
                'accepted_in_progress' => (int) $lensCounts->get('accepted_in_progress', 0),
                'governance_open' => (int) $lensCounts->get('operational_complete_governance_open', 0),
                'complete' => (int) $lensCounts->get('complete', 0),
            ],
            'sites' => $sites,
            'clients' => $clients,
            'can' => [
                'createAlert' => $user->canDo('controlRoom.alerts.create'),
                'createIncident' => $user->canDo('controlRoom.alerts.manage') && $user->canDo('incidents.create'),
                'manageHealthSafety' => $user->canDo('hazards.manage'),
            ],
            'detail' => fn () => $request->filled('alert')
                ? app(AlertWorkspaceService::class)->build($user, (int) $request->input('alert'))
                : null,
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function canonicalJourneyQuery(User $user, array $filters): Builder
    {
        $query = ControlRoomAlert::query();
        $siteAccess = $this->siteAccess();
        $siteAccess->applyAlertScope($query, $user, $this->alertBypassPermissions());

        if (! empty($filters['site_id'])) {
            $siteAccess->applyAlertSiteScopeForSiteIds($query, [(int) $filters['site_id']]);
        }
        if (! empty($filters['client_id'])) {
            $query->where('control_room_alerts.client_id', (int) $filters['client_id']);
        }
        if (! empty($filters['severity'])) {
            $query->where('control_room_alerts.severity', $filters['severity']);
        }
        if (! empty($filters['status'])) {
            $query->where('control_room_alerts.status', $filters['status']);
        }
        if (! empty($filters['date_from'])) {
            $query->where('control_room_alerts.triggered_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (! empty($filters['date_to'])) {
            $query->where('control_room_alerts.triggered_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $searchQuery) use ($search) {
                $searchQuery->where('control_room_alerts.reference_number', 'like', "%{$search}%")
                    ->orWhere('control_room_alerts.alert_type', 'like', "%{$search}%")
                    ->orWhere('control_room_alerts.notes', 'like', "%{$search}%")
                    ->orWhereHas('clientIncident', fn (Builder $incident) => $incident
                        ->where('reference_number', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%"))
                    ->orWhereHas('hsEvent', fn (Builder $event) => $event
                        ->where('reference_number', 'like', "%{$search}%"));
            });
        }

        return $query;
    }

    private function applyHandoverLens(Builder $query, string $lens): Builder
    {
        $operationalComplete = [ControlRoomAlert::STATUS_RESOLVED, ControlRoomAlert::STATUS_CLOSED];

        return match ($lens) {
            'needs_incident' => $query->actionable()->notSnoozed()->doesntHave('clientIncident'),
            'awaiting_health_safety' => $query->whereHas('hsEvent', fn (Builder $event) => $event
                ->where('handover_status', HsEvent::HANDOVER_AWAITING_ACCEPTANCE)),
            'accepted_in_progress' => $query->actionable()->notSnoozed()
                ->whereHas('hsEvent', fn (Builder $event) => $event
                    ->where('handover_status', HsEvent::HANDOVER_ACCEPTED)
                    ->where('status', '!=', HsEvent::STATUS_CLOSED)),
            'operational_complete_governance_open' => $query
                ->whereIn('control_room_alerts.status', $operationalComplete)
                ->whereHas('hsEvent', fn (Builder $event) => $event
                    ->where('status', '!=', HsEvent::STATUS_CLOSED)),
            'complete' => $query
                ->whereIn('control_room_alerts.status', $operationalComplete)
                ->whereHas('hsEvent', fn (Builder $event) => $event
                    ->where('status', HsEvent::STATUS_CLOSED)),
            default => $query->where(function (Builder $attention) use ($operationalComplete) {
                $attention
                    ->where(function (Builder $needsIncident) {
                        $needsIncident->actionable()->notSnoozed()->doesntHave('clientIncident');
                    })
                    ->orWhereHas('hsEvent', fn (Builder $event) => $event
                        ->where('handover_status', HsEvent::HANDOVER_AWAITING_ACCEPTANCE))
                    ->orWhere(function (Builder $accepted) {
                        $accepted->actionable()->notSnoozed()
                            ->whereHas('hsEvent', fn (Builder $event) => $event
                                ->where('handover_status', HsEvent::HANDOVER_ACCEPTED)
                                ->where('status', '!=', HsEvent::STATUS_CLOSED));
                    })
                    ->orWhere(function (Builder $governance) use ($operationalComplete) {
                        $governance->whereIn('control_room_alerts.status', $operationalComplete)
                            ->whereHas('hsEvent', fn (Builder $event) => $event
                                ->where('status', '!=', HsEvent::STATUS_CLOSED));
                    });
            }),
        };
    }

    /** @return array<int, string> */
    private function handoverLensKeys(): array
    {
        return [
            'attention',
            'needs_incident',
            'awaiting_health_safety',
            'accepted_in_progress',
            'operational_complete_governance_open',
            'complete',
        ];
    }

    /** @return array<string, mixed> */
    private function presentJourney(
        ControlRoomAlert $alert,
        User $viewer,
        AlertWorklistPresenter $presenter,
    ): array {
        $worklist = $presenter->present($alert, $viewer);
        $incident = $alert->clientIncident;
        $event = $alert->hsEvent;
        $canViewIncident = $viewer->canDo('incidents.viewAny') || $viewer->canDo('incidents.viewAssigned');
        $canViewHealthSafety = $viewer->canDo('hazards.view');

        $stage = match (true) {
            $incident === null => 'needs_incident',
            $event?->handover_status === HsEvent::HANDOVER_AWAITING_ACCEPTANCE => 'awaiting_health_safety',
            $alert->isActionable() && $event?->status !== HsEvent::STATUS_CLOSED => 'accepted_in_progress',
            in_array($alert->status, [ControlRoomAlert::STATUS_RESOLVED, ControlRoomAlert::STATUS_CLOSED], true)
                && $event?->status !== HsEvent::STATUS_CLOSED => 'operational_complete_governance_open',
            default => 'complete',
        };

        $nextAction = match ($stage) {
            'needs_incident' => [
                'key' => 'create_incident',
                'label' => 'Create incident and hand over',
                'href' => '/control-room/alerts/'.$alert->id,
            ],
            'awaiting_health_safety' => [
                'key' => 'accept_health_safety',
                'label' => $viewer->canDo('hazards.manage') ? 'Accept H&S handover' : 'View H&S handover',
                'href' => $canViewHealthSafety && $event ? '/health-safety/events/'.$event->id : null,
            ],
            'accepted_in_progress' => [
                'key' => 'continue_health_safety',
                'label' => 'Continue H&S work',
                'href' => $canViewHealthSafety && $event ? '/health-safety/events/'.$event->id : null,
            ],
            'operational_complete_governance_open' => [
                'key' => 'finish_governance',
                'label' => 'Finish H&S governance',
                'href' => $canViewHealthSafety && $event ? '/health-safety/events/'.$event->id : null,
            ],
            default => [
                'key' => 'view_journey',
                'label' => 'View completed journey',
                'href' => $canViewIncident && $incident ? '/incidents?incident='.$incident->id : $worklist['href'],
            ],
        };

        return $worklist + [
            'alert' => [
                'id' => $alert->id,
                'reference_number' => $alert->reference_number ?: null,
                'status' => $alert->status,
                'severity' => $alert->severity,
                'summary' => $worklist['summary'],
                'href' => $worklist['href'],
            ],
            'incident' => $incident ? [
                'id' => $incident->id,
                'reference_number' => $incident->reference_number ?: null,
                'status' => $incident->status,
                'severity' => $incident->severity,
                'title' => $incident->title,
                'occurred_at' => $incident->occurred_at?->toIso8601String(),
                'href' => $canViewIncident ? '/incidents?incident='.$incident->id : null,
            ] : null,
            'health_safety' => $event ? [
                'id' => $event->id,
                'reference_number' => $event->reference_number ?: null,
                'status' => $event->status,
                'severity' => $event->severity,
                'handover_status' => $event->handover_status,
                'owner' => $event->owner ? ['id' => $event->owner->id, 'name' => $event->owner->name] : null,
                'accepted_by' => $event->acceptedBy ? ['id' => $event->acceptedBy->id, 'name' => $event->acceptedBy->name] : null,
                'accepted_at' => $event->accepted_at?->toIso8601String(),
                'href' => $canViewHealthSafety ? '/health-safety/events/'.$event->id : null,
            ] : null,
            'stage' => $stage,
            'next_action' => $nextAction,
        ];
    }

    /** @return array<string, mixed> */
    private function paginatorPayload(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * Keep the previous response contract while pagination happens in SQL.
     * The desktop page no longer reads this compatibility prop.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function legacyIncidentFeed(Request $request, User $user, array $filters): array
    {
        $dateFrom = ! empty($filters['date_from'])
            ? Carbon::parse($filters['date_from'])->startOfDay()
            : now()->subDays(30)->startOfDay();
        $dateTo = ! empty($filters['date_to'])
            ? Carbon::parse($filters['date_to'])->endOfDay()
            : now()->endOfDay();
        $rows = collect();

        if (empty($filters['source_type']) || $filters['source_type'] === 'client_incident') {
            $query = ClientIncident::query()
                ->with(['client.site', 'site:id,name', 'reporter:id,name'])
                ->where('status', '!=', 'draft')
                ->whereBetween('occurred_at', [$dateFrom, $dateTo]);
            $this->siteAccess()->applyClientIncidentScope($query, $user, $this->alertBypassPermissions());
            $this->applyLegacyFilters($query, $filters, 'occurred_at');
            $rows->push(...$query->limit(25)->get()->map(fn (ClientIncident $incident) => [
                'id' => 'ci_'.$incident->id,
                'source_type' => 'client_incident',
                'source_id' => $incident->id,
                'title' => $incident->title ?: 'Incident: '.ucfirst($incident->type ?? 'General'),
                'description' => $incident->description,
                'severity' => $incident->severity ?? 'medium',
                'status' => $incident->status,
                'client_name' => $incident->client?->full_name ?? 'Unknown',
                'site_name' => $incident->site?->name ?? $incident->client?->site?->name ?? 'Unknown',
                'occurred_at' => $incident->occurred_at?->toIso8601String(),
                'reporter_name' => $incident->reporter?->name ?? 'Unknown',
                'type_label' => ucfirst(str_replace('_', ' ', $incident->type ?? 'incident')),
                'immediate_action' => $incident->immediate_action_taken ?? $incident->immediate_action,
                'requires_followup' => (bool) $incident->requires_followup,
                'location' => $incident->location,
            ]));
        }

        if (empty($filters['source_type']) || $filters['source_type'] === 'medication_error') {
            $query = MedicationError::query()
                ->with(['client.site', 'reportedBy:id,name'])
                ->whereBetween('reported_at', [$dateFrom, $dateTo]);
            $this->applyMedicationErrorScope($query, $user);
            $this->applyLegacyFilters($query, $filters, 'reported_at');
            $rows->push(...$query->limit(25)->get()->map(fn (MedicationError $error) => [
                'id' => 'me_'.$error->id,
                'source_type' => 'medication_error',
                'source_id' => $error->id,
                'title' => 'Medication Error: '.ucfirst(str_replace('_', ' ', $error->error_type ?? 'Unknown')),
                'description' => $error->description,
                'severity' => $error->severity ?? 'medium',
                'status' => $error->status,
                'client_name' => $error->client?->full_name ?? 'Unknown',
                'site_name' => $error->client?->site?->name ?? 'Unknown',
                'occurred_at' => $error->reported_at?->toIso8601String(),
                'reporter_name' => $error->reportedBy?->name ?? 'Unknown',
                'type_label' => ucfirst(str_replace('_', ' ', $error->error_type ?? 'medication error')),
                'immediate_action' => $error->immediate_action,
                'requires_followup' => false,
                'location' => null,
            ]));
        }

        if (empty($filters['source_type']) || $filters['source_type'] === 'safeguarding') {
            $query = SafeguardingConcern::query()
                ->with(['reportedBy:id,name', 'site:id,name'])
                ->whereBetween('occurred_at', [$dateFrom, $dateTo]);
            $this->applySafeguardingScope($query, $user);
            $this->applyLegacyFilters($query, $filters, 'occurred_at');
            $rows->push(...$query->limit(25)->get()->map(fn (SafeguardingConcern $concern) => [
                'id' => 'sg_'.$concern->id,
                'source_type' => 'safeguarding',
                'source_id' => $concern->id,
                'title' => 'Safeguarding: '.ucfirst(str_replace('_', ' ', $concern->concern_type ?? 'Concern')),
                'description' => $concern->description,
                'severity' => $concern->severity ?? 'high',
                'status' => $concern->status,
                'client_name' => $concern->subject_name ?? 'Unknown',
                'site_name' => $concern->site?->name ?? 'Unknown',
                'occurred_at' => $concern->occurred_at?->toIso8601String(),
                'reporter_name' => $concern->reportedBy?->name ?? $concern->reported_by_name ?? 'Unknown',
                'type_label' => ucfirst(str_replace('_', ' ', $concern->abuse_category ?? $concern->concern_type ?? 'safeguarding')),
                'immediate_action' => $concern->immediate_actions,
                'requires_followup' => (bool) $concern->requires_external_referral,
                'location' => $concern->location,
            ]));
        }

        $sorted = $rows->sortBy([
            fn (array $left, array $right) => $this->severityRank($left['severity']) <=> $this->severityRank($right['severity']),
            fn (array $left, array $right) => strcmp($right['occurred_at'] ?? '', $left['occurred_at'] ?? ''),
        ])->values();
        $page = max(1, (int) $request->input('legacy_page', 1));
        $paginator = new LengthAwarePaginator(
            $sorted->forPage($page, 25)->values(),
            $sorted->count(),
            25,
            $page,
            ['path' => $request->url(), 'pageName' => 'legacy_page'],
        );

        return $paginator->toArray();
    }

    /** @param array<string, mixed> $filters */
    private function applyLegacyFilters(Builder $query, array $filters, string $dateColumn): void
    {
        if (! empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['client_id']) && $query->getModel() instanceof SafeguardingConcern === false) {
            $query->where('client_id', $filters['client_id']);
        }
        if (! empty($filters['site_id'])) {
            if ($query->getModel() instanceof SafeguardingConcern) {
                $query->where('site_id', $filters['site_id']);
            } else {
                $query->whereHas('client', fn (Builder $client) => $client->where('site_id', $filters['site_id']));
            }
        }
        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $model = $query->getModel();
            $query->where(function (Builder $nested) use ($search, $model) {
                $nested->where('description', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%");
                if ($model instanceof ClientIncident) {
                    $nested->orWhere('title', 'like', "%{$search}%");
                } elseif ($model instanceof MedicationError) {
                    $nested->orWhere('error_type', 'like', "%{$search}%");
                } elseif ($model instanceof SafeguardingConcern) {
                    $nested->orWhere('concern_type', 'like', "%{$search}%")
                        ->orWhere('subject_name', 'like', "%{$search}%");
                }
            });
        }
        $query->whereNotNull($dateColumn);
    }

    private function severityRank(?string $severity): int
    {
        return match ($severity) {
            'critical' => 0,
            'major', 'high' => 1,
            'moderate', 'medium' => 2,
            'minor', 'low' => 3,
            'near_miss' => 4,
            default => 5,
        };
    }

    /**
     * Create a ControlRoomAlert from an existing incident source.
     */
    public function createAlertFromIncident(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.create'), 403);

        $data = $request->validate([
            'source_type' => ['required', 'string', 'in:client_incident,medication_error,safeguarding'],
            'source_id' => ['required', 'integer'],
            'severity' => ['required', 'string', 'in:low,medium,high,critical'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $siteAccess = $this->siteAccess();
        $bypassPermissions = $this->alertBypassPermissions();

        // Resolve the source record for context
        $context = [
            'incident_source_type' => $data['source_type'],
            'incident_source_id' => $data['source_id'],
        ];

        $alertType = 'incident_escalation';
        $siteId = null;
        $clientId = null;

        switch ($data['source_type']) {
            case 'client_incident':
                $sourceQuery = ClientIncident::query()->with('client.site');
                $siteAccess->applyClientIncidentScope($sourceQuery, $user, $bypassPermissions);
                $source = $sourceQuery->find($data['source_id']);
                abort_unless($source, 403, 'You are not authorized to access that incident source.');
                $siteAccess->assertCanAccessClientIncident($user, $source, $bypassPermissions);
                $context['title'] = $source->title ?: ('Incident: '.ucfirst($source->type ?? 'General'));
                $context['description'] = $source->description;
                $alertType = 'client_incident';
                $siteId = $source->client?->site_id;
                $clientId = $source->client_id;
                break;

            case 'medication_error':
                $sourceQuery = MedicationError::query()->with('client.site');
                $this->applyMedicationErrorScope($sourceQuery, $user);
                $source = $sourceQuery->find($data['source_id']);
                abort_unless($source, 403, 'You are not authorized to access that incident source.');
                $context['title'] = 'Medication Error: '.ucfirst(str_replace('_', ' ', $source->error_type ?? 'Unknown'));
                $context['description'] = $source->description;
                $alertType = 'medication_error';
                $siteId = $source->client?->site_id;
                $clientId = $source->client_id;
                break;

            case 'safeguarding':
                $sourceQuery = SafeguardingConcern::query()->with('site');
                $this->applySafeguardingScope($sourceQuery, $user);
                $source = $sourceQuery->find($data['source_id']);
                abort_unless($source, 403, 'You are not authorized to access that incident source.');
                $context['title'] = 'Safeguarding: '.ucfirst(str_replace('_', ' ', $source->concern_type ?? 'Concern'));
                $context['description'] = $source->description;
                $alertType = 'safeguarding_concern';
                $siteId = $source->site_id;
                break;
        }

        if ($data['source_type'] === 'client_incident') {
            $journey = app(IncidentJourneyService::class)->ensureAlertForIncident(
                $source,
                $user,
                $data['notes'] ?? null,
                $data['severity'],
            );
            $alert = $journey->alert;

            if ($alert === null) {
                throw new \RuntimeException('The canonical incident journey did not return its operational alert.');
            }

            AuditLogger::log('controlRoom.alert.createFromIncident', $alert, [
                'alert_id' => $alert->id,
                'incident_source_type' => $data['source_type'],
                'incident_source_id' => $data['source_id'],
            ]);

            return back()
                ->with('success', 'Alert created from incident.')
                ->with('created_alert_id', $alert->id);
        }

        $alertData = [
            'source' => 'manual',
            'alert_type' => $alertType,
            'severity' => $data['severity'],
            'status' => 'open',
            'triggered_at' => now(),
            'created_by_user_id' => $user->id,
            'site_id' => $siteId,
            'client_id' => $clientId,
            'context' => $context,
            'notes' => $data['notes'] ?? null,
        ];

        $queue = TriageQueue::findForAlert($alertData['severity'], $alertData['source'], $alertData['alert_type']);
        $alertData['queue_id'] = $queue?->id;

        $alert = ControlRoomAlert::create($alertData);

        if ($queue) {
            AlertQueue::create([
                'alert_id' => $alert->id,
                'queue_id' => $queue->id,
                'entered_at' => now(),
            ]);
        }

        if (! $alert->sla) {
            $slaDefinition = SlaDefinition::findForAlert($alert->alert_type, $alert->severity, $alert->source);
            if ($slaDefinition) {
                AlertSla::createFromDefinition($alert, $slaDefinition);
            }
        }

        AuditLogger::log('controlRoom.alert.createFromIncident', $alert, [
            'alert_id' => $alert->id,
            'incident_source_type' => $data['source_type'],
            'incident_source_id' => $data['source_id'],
        ]);

        return back()
            ->with('success', 'Alert created from incident.')
            ->with('created_alert_id', $alert->id);
    }

    /**
     * Operator quick-flag (Gap A): create a ClientIncident (source=control_room) and a
     * ControlRoomAlert together, bidirectionally linked. The alert drives the real-time
     * operator response; the incident is the system of record that flows on to H&S.
     *
     * Wrapped in a transaction and attached through IncidentJourneyService so the
     * incident, alert, and H&S backlinks become visible together.
     */
    public function flagAsIncident(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.create'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'type' => ['required', 'string', 'max:120'],
            'severity' => ['required', 'string', 'in:low,medium,high,critical'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $clientQuery = Client::query()->with('site');
        $this->siteAccess()->applyClientScope(
            $clientQuery,
            $user,
            $this->alertBypassPermissions(),
        );
        $client = $clientQuery->find($data['client_id']);
        abort_unless($client, 403, 'You are not authorized to access that client.');
        $this->siteAccess()->assertCanAccessClientId(
            $user,
            $client->id,
            $this->alertBypassPermissions(),
        );

        // ClientIncident severity is low|medium|high; an alert may also be critical.
        $incidentSeverity = $data['severity'] === 'critical' ? 'high' : $data['severity'];

        $result = DB::transaction(function () use ($data, $client, $incidentSeverity, $user) {
            $incident = ClientIncident::withoutEvents(
                fn () => ClientIncident::create([
                    'client_id' => $client->id,
                    'reported_by' => $user->id,
                    'type' => $data['type'],
                    'source' => 'control_room',
                    'severity' => $incidentSeverity,
                    'status' => 'submitted',
                    'submitted_at' => now(),
                    'occurred_at' => now(),
                    'description' => $data['note'] ?? null,
                    'title' => $data['type'].' incident',
                ]),
            );

            $alertData = [
                'source' => 'control_room',
                'alert_type' => 'incident.'.$incident->type,
                'severity' => $data['severity'],
                'status' => 'open',
                'triggered_at' => now(),
                'created_by_user_id' => $user->id,
                'site_id' => $client->site_id,
                'client_id' => $client->id,
                'context' => [
                    'incident_id' => $incident->id,
                    'incident_type' => $incident->type,
                    'title' => $incident->title,
                    'description' => $incident->description,
                    'flagged_by' => $user->name,
                ],
                'notes' => $data['note'] ?? null,
            ];

            $queue = TriageQueue::findForAlert($alertData['severity'], $alertData['source'], $alertData['alert_type']);
            $alertData['queue_id'] = $queue?->id;

            $alert = ControlRoomAlert::create($alertData);

            if ($queue) {
                AlertQueue::create([
                    'alert_id' => $alert->id,
                    'queue_id' => $queue->id,
                    'entered_at' => now(),
                ]);
            }

            if (! $alert->sla) {
                $slaDefinition = SlaDefinition::findForAlert($alert->alert_type, $alert->severity, $alert->source);
                if ($slaDefinition) {
                    AlertSla::createFromDefinition($alert, $slaDefinition);
                }
            }

            $journey = app(IncidentJourneyService::class)
                ->attachAlertToIncident($incident, $alert, $user);

            return ['incident' => $journey->incident, 'alert' => $journey->alert];
        });

        AuditLogger::log('controlRoom.alert.flagAsIncident', $result['alert'], [
            'alert_id' => $result['alert']->id,
            'incident_id' => $result['incident']->id,
            'severity' => $data['severity'],
        ]);

        $incidentReference = $result['incident']->reference_number ?: 'the incident';

        return back()
            ->with('success', "Incident {$incidentReference} flagged and alert raised.")
            ->with('flagged_incident_id', $result['incident']->id)
            ->with('flagged_alert_id', $result['alert']->id);
    }

    protected function siteAccess(): UserSiteAccessService
    {
        return app(UserSiteAccessService::class);
    }

    /**
     * @return array<int, string>
     */
    protected function alertBypassPermissions(): array
    {
        return ['reports.viewAny'];
    }

    protected function applyMedicationErrorScope(Builder $query, User $user): Builder
    {
        return $query->whereHas('client', fn (Builder $clientQuery) => $this->siteAccess()->applyClientScope(
            $clientQuery,
            $user,
            $this->alertBypassPermissions(),
        ));
    }

    protected function applySafeguardingScope(Builder $query, User $user): Builder
    {
        $siteAccess = $this->siteAccess();
        $bypassPermissions = $this->alertBypassPermissions();

        if (! $siteAccess->isUnrestrictedPlatformUser($user)) {
            $siteIds = $siteAccess->accessibleSiteIds($user, $bypassPermissions);

            if ($siteIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('site_id', $siteIds);
            }
        }

        if (! $user->can('viewAny', SafeguardingConcern::class)) {
            $query->where(function (Builder $visibility) use ($user) {
                $visibility->where('assigned_to_user_id', $user->id)
                    ->orWhere('reported_by_user_id', $user->id);
            });
        }

        if (! $user->can('viewSensitive', SafeguardingConcern::class)) {
            $query->where(function (Builder $sensitivity) use ($user) {
                $sensitivity->where('is_sensitive', false)
                    ->orWhereNull('is_sensitive')
                    ->orWhere('assigned_to_user_id', $user->id)
                    ->orWhere('reported_by_user_id', $user->id);
            });
        }

        return $query;
    }
}
