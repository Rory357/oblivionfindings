<?php

namespace App\Services\Incidents;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\IncidentFollowup;
use App\Models\Site;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use App\Support\Incidents\IncidentOccurrencePeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use App\Support\Database\LockConflictRetry;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Faithful detached register composition; legacy CR Site/report-staff gaps remain explicitly unaccepted. */
final class IncidentRegisterReadService
{
    public const PERMISSIONS = [...IncidentOwnerEligibility::PERMISSIONS,
        'incidents.create', 'incidents.followups.manage', 'incidents.followups.complete', 'incidents.templates.manage',
        'incidents.portal.manage', 'incidents.update', 'incidents.submit', 'incidents.approve', 'incidents.reopen',
        'safeguarding.create', 'safeguarding.viewAny', 'clients.viewAny', 'clients.viewAssigned',
        'reports.viewAny', 'hazards.view', 'hazards.manage', 'compliance.view',
        'controlRoom.viewAny', 'controlRoom.alerts.view', 'controlRoom.alerts.manage', 'controlRoom.alerts.assign',
        'controlRoom.alerts.escalate', 'controlRoom.alerts.create', 'medications.controlled.view',
        'sites.update', 'sites.viewAll', 'sites.type.head_office', 'sites.type.house', 'sites.type.facility'];

    public function __construct(private readonly IncidentOwnershipService $owners,
        private readonly IncidentJourneyService $journeys, private readonly IncidentAccessService $access,
        private readonly UserSiteAccessService $sites, private readonly AuthorizationEvidenceLockService $authorization,
        private readonly IncidentIndependenceIntakeService $independence) {}

    /** The existing optional detail consumer executes before callback exit; no actor/resolver/model escapes. */
    public function index(Request $request, ?callable $detailConsumer = null): array
    {
        $viewer = $request->user();
        abort_unless($viewer instanceof User, 403);
        // A save holding the viewer's evidence rows makes this no-wait read fail at once; try again a moment later.
        return LockConflictRetry::read(fn () => DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($viewer, $request, $detailConsumer): array {
            $user = $this->authorization->lockForUserWithoutWaiting($viewer, self::PERMISSIONS);
            $working = clone $request;
            $resolver = $working->getUserResolver();
            $working->setUserResolver(fn () => $user);
            try {
                $result = $this->compose($working, $user, $reads, $detailConsumer);
                IncidentRoutingSettings::detached($result);
                $reads->assertActive();
                return $result;
            } finally { $working->setUserResolver($resolver); }
        })));
    }

    /** Permanent source read, with the existing detail consumer materialised inside current evidence. */
    public function show(Request $request, int $incidentId, callable $existingDetailConsumer): array
    {
        return LockConflictRetry::read(fn () => $this->consumeSource($request, $incidentId, function ($working, $id, $reads) use ($existingDetailConsumer): array {
            $detail = $existingDetailConsumer($working, $id, $reads);
            abort_if($detail === null, 404);
            if (! is_array($detail)) { throw new LogicException('Incident detail consumer must return a detached detail array.'); }
            return ['viewer_id' => (int) $working->user()->id, 'detail' => $detail];
        }));
    }

    /** Additional source operations materialise in one current callback; the fourth argument is a lifetime-bound parent query. */
    public function consumeSource(Request $request, int $incidentId, callable $consumer): array
    {
        abort_unless($incidentId > 0, 404);
        $viewer = $request->user();
        abort_unless($viewer instanceof User, 403);
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($viewer, $request, $incidentId, $consumer): array {
            $current = $this->authorization->lockForUserWithoutWaiting($viewer, self::PERMISSIONS);
            abort_unless($current->canDo('incidents.viewAny') || $current->canDo('incidents.viewAssigned'), 403);
            $working = clone $request;
            $resolver = $working->getUserResolver();
            $working->setUserResolver(fn () => $current);
            try {
                $source = $this->access->apply(ClientIncident::query()->whereKey($incidentId), $current, $reads);
                $source = $this->independence->applySourceScopeInReads($source, $current, $reads);
                abort_unless($source->exists(), 404);
                $value = $consumer($working, $incidentId, $reads, $source);
                if (! is_array($value)) { throw new LogicException('Incident source consumer must return a detached array.'); }
                $result = $this->scalarize($value);
                IncidentRoutingSettings::detached($result);
                $reads->assertActive();
                return $result;
            } finally { $working->setUserResolver($resolver); }
        }));
    }
    private function compose(Request $request, User $user, CurrentAuthorizationReads $reads, ?callable $detailConsumer): array
    {

        abort_unless($user->canDo('incidents.viewAny') || $user->canDo('incidents.viewAssigned'), 403);

        $owner = $request->validate(['owner' => ['nullable', 'in:all,me,needs_owner']])['owner'] ?? 'all';
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
        $period = $request->validate([
            'kind' => ['nullable', 'in:all,incidents,near_misses'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);
        $from = $period['from'] ?? null;
        $to = $period['to'] ?? null;
        $kind = $period['kind'] ?? 'all';

        // Shared parent refinements (everything EXCEPT the tab), applied once before
        // owner qualification; followups reuse the qualified parent-ID subquery,
        // so the tab counts stay mutually consistent.
        $applyFilters = function ($query) use ($user, $reads, $q, $severity, $clientId, $siteId, $source, $from, $to, $kind) {
            return $query
                ->tap(fn (Builder $query) => app(IncidentAccessService::class)->apply($query, $user, $reads))
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
                ->when($kind === 'incidents', fn ($query) => $query->where('type', '!=', 'near_miss'))
                ->when($kind === 'near_misses', fn ($query) => $query->where('type', 'near_miss'))
                ->tap(fn (Builder $query) => IncidentOccurrencePeriod::apply($query, $from, $to));
        };

        // Parent Site/privacy/search/refinements are complete before the one ownership qualification.
        // One canonical independent-handling qualification precedes every count/body and historic people picker.
        // The ordinary read/Site/HR/assignment gate remains mandatory before this additional boundary.
        $readableIncidents = $this->independence->applySourceScopeInReads($this->access->apply(ClientIncident::query(), $user, $reads), $user, $reads);
        $base = $applyFilters(clone $readableIncidents);
        if ($owner !== 'all') { $base = $this->owners->applyOwnerFilter($base, $user, $owner, $reads); }
        $base = $reads->query($base);

        $p30 = now()->subDays(30);
        $p60 = now()->subDays(60);
        $p90 = now()->subDays(90);
        $applyTab = fn ($query, string $t) => match ($t) {
            'open' => $query->where('status', '!=', 'closed'),
            'investigation' => $query->whereIn('investigation_status', ['pending', 'in_progress']),
            'worksafe' => $this->whereCanonicalWorksafeEvent($query),
            'worksafe_pending' => $this->whereCanonicalWorksafeEvent($query, HsEvent::WORKSAFE_PENDING),
            'reported_recent' => $query->where('occurred_at', '>=', $p30),
            'closed_recent' => $query->where('status', 'closed')->where('closed_at', '>=', $p30),
            'near_misses' => $query->where('type', 'near_miss'),
            'review' => $query->where('status', 'submitted'),
            'closed' => $query->where('status', 'closed'),
            default => $query,
        };

        // Per-tab counts.
        $countFor = fn (string $t) => $applyTab((clone $base), $t)->count();
        $openFollowupsQuery = fn () => $reads->query(IncidentFollowup::query()
            ->whereNull('completed_at')
            ->whereIn('client_incident_id', (clone $base)->reorder()->select('client_incidents.id')));

        $tabCounts = [
            'all' => $countFor('all'),
            'open' => $countFor('open'),
            'investigation' => $countFor('investigation'),
            'followups' => $openFollowupsQuery()->count(),
            'worksafe' => $countFor('worksafe'),
            'near_misses' => $countFor('near_misses'),
            'review' => $countFor('review'),
            'closed' => $countFor('closed'),
            'reported_recent' => $countFor('reported_recent'),
            'closed_recent' => $countFor('closed_recent'),
            'worksafe_pending' => $countFor('worksafe_pending'),
        ];

        // Rows for the active tab. Follow-ups due is a worklist of follow-up rows;
        // every other tab is a list of incident rows.
        if ($tab === 'followups') {
            $rows = $openFollowupsQuery()
                ->with($this->currentRelations(['incident:id,type,client_id,reference_number', 'incident.client:id,first_name,last_name', 'assignedTo:id,name'], $reads))
                ->orderByRaw('due_at is null')
                ->orderBy('due_at')
                ->paginate(50, ['*'], 'page', max(1, (int) $request->query('page', 1)))
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
            $rows = $applyTab((clone $base), $tab)
                ->with($this->currentRelations([
                    'client:id,first_name,last_name,site_id',
                    'client.site:id,name',
                    'site:id,name',
                    'reporter:id,name',
                    'hsEvent:id,source_type,source_id,event_category,idempotency_key,client_id,site_id,control_room_alert_id,worksafe_notifiable,worksafe_status,worksafe_reference,worksafe_notified_at,worksafe_acknowledged_at,worksafe_method,worksafe_site_preserved',
                    'hsEvent.site:id,name',
                ], $reads))
                ->withCount([
                    'attachments' => function ($query) use ($reads): void { $reads->query($query instanceof \Illuminate\Database\Eloquent\Relations\Relation ? $query->getQuery() : $query); },
                    'followups as open_followups_count' => function ($query) use ($reads): void { $query->whereNull('completed_at'); $reads->query($query instanceof \Illuminate\Database\Eloquent\Relations\Relation ? $query->getQuery() : $query); },
                ])
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->paginate(50, ['*'], 'page', max(1, (int) $request->query('page', 1)))
                ->withQueryString()
                ->through(function (ClientIncident $incident) use ($user, $reads): array {
                    $directEvent = $incident->hsEvent;
                    $event = $directEvent !== null
                        && $this->journeys->hsEventIsCanonicalForIncident($incident, $directEvent)
                            ? $directEvent
                            : null;
                    $journeyRepairRequired = $incident->hs_event_id !== null && $event === null;

                    return [
                        'id' => $incident->id,
                        'ref' => $incident->reference_number,
                        'ownership' => $this->owners->summaryInCurrentReads($user, $incident, $reads),
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
        $reported30 = $tabCounts['reported_recent'];
        $reportedPrev = (clone $base)->whereBetween('occurred_at', [$p60, $p30])->count();
        $closed30 = $tabCounts['closed_recent'];
        $closedPrev = (clone $base)->where('status', 'closed')->whereBetween('closed_at', [$p60, $p30])->count();

        $overdueFollowups = $openFollowupsQuery()->where('due_at', '<', now())->count();
        $worksafeAwaiting = $tabCounts['worksafe_pending'];
        $alertAccess = app(ControlRoomAlertAccessService::class);
        $activeAlerts = $alertAccess->canRead($user)
            ? (clone $base)
                ->whereHas('controlRoomAlert', fn (Builder $alerts) => $alertAccess
                    ->applyReadableScope($alerts, $user)->actionable())
                ->count()
            : null;

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
        $nm30 = (clone $base)->where('type', 'near_miss')->where('occurred_at', '>=', $p30)->count();
        $nmPrev = (clone $base)->where('type', 'near_miss')->whereBetween('occurred_at', [$p60, $p30])->count();
        $nm90 = (clone $base)->where('type', 'near_miss')->where('occurred_at', '>=', $p90)->count();
        $inc90 = (clone $base)->where('type', '!=', 'near_miss')->where('occurred_at', '>=', $p90)->count();
        $nmByPotential = (clone $base)
            ->where('type', 'near_miss')
            ->whereNotNull('potential_severity')
            ->selectRaw('potential_severity, count(*) as c')
            ->groupBy('potential_severity')
            ->orderByDesc('c')
            ->pluck('c', 'potential_severity');

        $nearMissInsights = [
            'trend_pct' => $nmPrev > 0 ? (int) round((($nm30 - $nmPrev) / $nmPrev) * 100) : null,
            'ratio' => $inc90 > 0 ? round($nm90 / $inc90, 1) : null,
            'by_potential' => $nmByPotential->all(), // {low: n, medium: n, ...} — "what could have happened"
        ];

        $sites = null;
        $clients = null;
        if ($user->canDo('incidents.viewAny')) {
            $scopeIds = $this->sites->accessibleSiteIds($user, $this->healthSafetySiteBypassPermissions(), $reads);
            $siteQuery = $reads->query(Site::query()->where('is_active', true)->whereIn('id', $scopeIds));
            $sites = $siteQuery->orderBy('name')->get(['id', 'name']);
            $clientQuery = $this->peopleForFilters($user, $reads, $readableIncidents);
            $clients = $clientQuery->orderBy('first_name')->get(['id', 'first_name', 'last_name']);
        }

        // Report-wizard data for the modal-first "+ Report": clients the user may
        // report for (scoped like IncidentController@create) + follow-up owners.
        $reportClients = null;
        $reportStaff = null;
        if ($user->canDo('incidents.create')) {
            $reportClientQuery = Client::query();

            $reportSiteIds = $this->sites->accessibleSiteIds($user, $this->incidentReportSiteBypassPermissions(), $reads);
            $reportClientQuery = $reads->query($reportClientQuery->whereIn('site_id', $reportSiteIds));

            if (! $this->canReportAcrossHealthSafety($user) && ! $user->canDo('clients.viewAny')) {
                $reportClientQuery->whereHas('supportWorkers', fn ($s) => $s->whereKey($user->id));
            }

            $reportClients = $reportClientQuery
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name', 'site_id']);
            $reportStaff = $user->canDo('incidents.followups.manage')
                ? $reads->query(app(HrCurrentStaffService::class)->currentUsersQuery()->orderBy('name')->orderBy('id'))->get(['id', 'name'])
                : [];
        }

        return $this->scalarize([
            'viewer_id' => (int) $user->id,
            'filters' => [
                'owner' => $owner,
                'q' => $q,
                'tab' => $tab,
                'severity' => $severity,
                'client_id' => $clientId ? (int) $clientId : null,
                'site_id' => $siteId ? (int) $siteId : null,
                'source' => $source,
                'kind' => $kind,
                'from' => $from,
                'to' => $to,
            ],
            'tab' => $tab,
            'tabCounts' => $tabCounts,
            'rows' => $rows->toArray(),
            'rowsKind' => $rowsKind,
            'hero' => $hero,
            'nearMissInsights' => $nearMissInsights,
            'sites' => $sites?->toArray(),
            'clients' => $clients?->toArray(),
            'reportClients' => $reportClients?->toArray(),
            'reportStaff' => $reportStaff instanceof \Illuminate\Support\Collection ? $reportStaff->toArray() : $reportStaff,
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
                'reportSafeguarding' => $user->canDo('safeguarding.create'),
            ],
            // Detail-over-list: when ?incident= is present the dialog opens over
            // the register (Inertia partial-reloads only this prop). Null otherwise.
            'detail' => $request->filled('incident') && (clone $readableIncidents)->whereKey((int) $request->get('incident'))->exists()
                ? ($detailConsumer === null ? throw new \LogicException('The existing incident detail consumer is required.')
                    : $detailConsumer($request, (int) $request->get('incident'), $reads))
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
        return app(IncidentAccessService::class)->forSiteIds($query, [$siteId]);
    }


    private function peopleForFilters(User $viewer, CurrentAuthorizationReads $reads, Builder $readableIncidents): Builder
    {
        $ids = $this->sites->accessibleSiteIds($viewer, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
        // Same peopleForFilters rule: current approved Site OR a readable historic incident, then current assignment.
        $query = Client::query()->where(fn (Builder $people) => $people->whereIn('site_id', $ids)
            ->orWhereIn('id', (clone $readableIncidents)->reorder()->select('client_incidents.client_id')));
        if (! $viewer->canDo('incidents.viewAny')) {
            $query->whereHas('supportWorkers', fn (Builder $staff) => $staff->whereKey($viewer->id));
        }
        return $reads->query($query);
    }

    private function currentRelations(array $relations, CurrentAuthorizationReads $reads): array
    {
        $current = [];
        foreach ($relations as $relation) {
            // "name:col,col" is only parsed by Laravel for plain strings, not keys with a callback,
            // so split it here and select the columns inside the callback.
            [$name, $columns] = array_pad(explode(':', $relation, 2), 2, null);
            // Eager callbacks receive a Relation, not invariably an Eloquent Builder.
            $current[$name] = function ($relationQuery) use ($reads, $columns): void {
                if ($columns !== null) {
                    $relationQuery->select(array_map(
                        fn (string $column) => $relationQuery instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany
                            ? $relationQuery->getRelated()->qualifyColumn($column) : $column,
                        explode(',', $columns),
                    ));
                }
                $query = $relationQuery instanceof \Illuminate\Database\Eloquent\Relations\Relation ? $relationQuery->getQuery() : $relationQuery;
                $reads->query($query);
            };
        }
        return $current;
    }

    private function canReportAcrossHealthSafety(User $user): bool
    {
        return $user->canDo('incidents.create') && $user->canDo('healthSafety.viewAllSites');
    }
    private function healthSafetySiteBypassPermissions(): array { return ['healthSafety.viewAllSites']; }
    private function incidentReportSiteBypassPermissions(): array { return ['healthSafety.viewAllSites', 'reports.viewAny']; }

    private function scalarize(mixed $value): mixed
    {
        if ($value instanceof \Illuminate\Support\Collection) { return $this->scalarize($value->toArray()); }
        if ($value instanceof \Carbon\CarbonInterface) { return $value->toJSON(); }
        if (is_array($value)) { foreach ($value as $key => $part) { $value[$key] = $this->scalarize($part); } return $value; }
        if ($value !== null && ! is_scalar($value)) { throw new LogicException('Incident register result must be detached.'); }
        return $value;
    }
}
