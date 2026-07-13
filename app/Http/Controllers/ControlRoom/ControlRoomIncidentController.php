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
use App\Models\MedicationError;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ControlRoom\AlertWorkspaceService;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ControlRoomIncidentController extends Controller
{
    /**
     * Display the unified incident tracker feed.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        $filters = $request->only([
            'source_type', 'severity', 'status', 'client_id', 'site_id',
            'date_from', 'date_to', 'search',
        ]);
        $siteAccess = $this->siteAccess();
        $bypassPermissions = $this->alertBypassPermissions();

        if (! empty($filters['site_id'])) {
            $siteAccess->assertCanAccessSiteId($user, (int) $filters['site_id'], $bypassPermissions);
        }
        if (! empty($filters['client_id'])) {
            $siteAccess->assertCanAccessClientId($user, (int) $filters['client_id'], $bypassPermissions);
        }

        $dateFrom = ! empty($filters['date_from'])
            ? Carbon::parse($filters['date_from'])->startOfDay()
            : now()->subDays(30)->startOfDay();

        $dateTo = ! empty($filters['date_to'])
            ? Carbon::parse($filters['date_to'])->endOfDay()
            : now()->endOfDay();

        $incidents = collect();

        // ── Client Incidents ──────────────────────────────────
        if (empty($filters['source_type']) || $filters['source_type'] === 'client_incident') {
            $ciQuery = ClientIncident::query()
                ->with(['client.site', 'reporter:id,name'])
                ->where('status', '!=', 'draft')
                ->whereBetween('occurred_at', [$dateFrom, $dateTo]);
            $siteAccess->applyClientIncidentScope($ciQuery, $user, $bypassPermissions);

            if (! empty($filters['severity'])) {
                $ciQuery->where('severity', $filters['severity']);
            }
            if (! empty($filters['status'])) {
                $ciQuery->where('status', $filters['status']);
            }
            if (! empty($filters['client_id'])) {
                $ciQuery->where('client_id', $filters['client_id']);
            }
            if (! empty($filters['site_id'])) {
                $ciQuery->whereHas('client', fn ($q) => $q->where('site_id', $filters['site_id']));
            }
            if (! empty($filters['search'])) {
                $search = $filters['search'];
                $ciQuery->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            $ciQuery->get()->each(function (ClientIncident $ci) use ($incidents) {
                $incidents->push([
                    'id' => 'ci_'.$ci->id,
                    'source_type' => 'client_incident',
                    'source_id' => $ci->id,
                    'title' => $ci->title ?: ('Incident: '.ucfirst($ci->type ?? 'General')),
                    'description' => $ci->description,
                    'severity' => $ci->severity ?? 'medium',
                    'status' => $ci->status,
                    'client_name' => $ci->client?->full_name ?? 'Unknown',
                    'site_name' => $ci->client?->site?->name ?? 'Unknown',
                    'occurred_at' => $ci->occurred_at?->toISOString(),
                    'reporter_name' => $ci->reporter?->name ?? 'Unknown',
                    'type_label' => ucfirst(str_replace('_', ' ', $ci->type ?? 'incident')),
                    'immediate_action' => $ci->immediate_action_taken ?? $ci->immediate_action,
                    'requires_followup' => (bool) $ci->requires_followup,
                    'location' => $ci->location,
                ]);
            });
        }

        // ── Medication Errors ─────────────────────────────────
        if (empty($filters['source_type']) || $filters['source_type'] === 'medication_error') {
            $meQuery = MedicationError::query()
                ->with(['client.site', 'reportedBy:id,name', 'medication'])
                ->whereBetween('reported_at', [$dateFrom, $dateTo]);
            $this->applyMedicationErrorScope($meQuery, $user);

            if (! empty($filters['severity'])) {
                $meQuery->where('severity', $filters['severity']);
            }
            if (! empty($filters['status'])) {
                $meQuery->where('status', $filters['status']);
            }
            if (! empty($filters['client_id'])) {
                $meQuery->where('client_id', $filters['client_id']);
            }
            if (! empty($filters['site_id'])) {
                $meQuery->whereHas('client', fn ($q) => $q->where('site_id', $filters['site_id']));
            }
            if (! empty($filters['search'])) {
                $search = $filters['search'];
                $meQuery->where(function ($q) use ($search) {
                    $q->where('description', 'like', "%{$search}%")
                        ->orWhere('error_type', 'like', "%{$search}%");
                });
            }

            $meQuery->get()->each(function (MedicationError $me) use ($incidents) {
                $incidents->push([
                    'id' => 'me_'.$me->id,
                    'source_type' => 'medication_error',
                    'source_id' => $me->id,
                    'title' => 'Medication Error: '.ucfirst(str_replace('_', ' ', $me->error_type ?? 'Unknown')),
                    'description' => $me->description,
                    'severity' => $me->severity ?? 'medium',
                    'status' => $me->status,
                    'client_name' => $me->client?->full_name ?? 'Unknown',
                    'site_name' => $me->client?->site?->name ?? 'Unknown',
                    'occurred_at' => $me->reported_at?->toISOString(),
                    'reporter_name' => $me->reportedBy?->name ?? 'Unknown',
                    'type_label' => ucfirst(str_replace('_', ' ', $me->error_type ?? 'medication error')),
                    'immediate_action' => $me->immediate_action,
                    'requires_followup' => false,
                    'location' => null,
                ]);
            });
        }

        // ── Safeguarding Concerns ─────────────────────────────
        if (empty($filters['source_type']) || $filters['source_type'] === 'safeguarding') {
            $sgQuery = SafeguardingConcern::query()
                ->with(['reportedBy:id,name', 'site:id,name'])
                ->whereBetween('occurred_at', [$dateFrom, $dateTo]);
            $this->applySafeguardingScope($sgQuery, $user);

            if (! empty($filters['severity'])) {
                $sgQuery->where('severity', $filters['severity']);
            }
            if (! empty($filters['status'])) {
                $sgQuery->where('status', $filters['status']);
            }
            if (! empty($filters['site_id'])) {
                $sgQuery->where('site_id', $filters['site_id']);
            }
            if (! empty($filters['search'])) {
                $search = $filters['search'];
                $sgQuery->where(function ($q) use ($search) {
                    $q->where('description', 'like', "%{$search}%")
                        ->orWhere('concern_type', 'like', "%{$search}%")
                        ->orWhere('subject_name', 'like', "%{$search}%");
                });
            }

            $sgQuery->get()->each(function (SafeguardingConcern $sg) use ($incidents) {
                $incidents->push([
                    'id' => 'sg_'.$sg->id,
                    'source_type' => 'safeguarding',
                    'source_id' => $sg->id,
                    'title' => 'Safeguarding: '.ucfirst(str_replace('_', ' ', $sg->concern_type ?? 'Concern')),
                    'description' => $sg->description,
                    'severity' => $sg->severity ?? 'high',
                    'status' => $sg->status,
                    'client_name' => $sg->subject_name ?? 'Unknown',
                    'site_name' => $sg->site?->name ?? 'Unknown',
                    'occurred_at' => $sg->occurred_at?->toISOString(),
                    'reporter_name' => $sg->reportedBy?->name ?? ($sg->reported_by_name ?? 'Unknown'),
                    'type_label' => ucfirst(str_replace('_', ' ', $sg->abuse_category ?? $sg->concern_type ?? 'safeguarding')),
                    'immediate_action' => $sg->immediate_actions,
                    'requires_followup' => (bool) $sg->requires_external_referral,
                    'location' => $sg->location,
                ]);
            });
        }

        // ── Sorting: critical first, then high, then by date desc ─
        $severityOrder = ['critical' => 0, 'major' => 1, 'high' => 1, 'moderate' => 2, 'medium' => 2, 'minor' => 3, 'low' => 3, 'near_miss' => 4];

        $sorted = $incidents->sortBy([
            fn ($a, $b) => ($severityOrder[$a['severity']] ?? 5) <=> ($severityOrder[$b['severity']] ?? 5),
            fn ($a, $b) => strcmp($b['occurred_at'] ?? '', $a['occurred_at'] ?? ''),
        ])->values();

        // ── Stats ─────────────────────────────────────────────
        $stats = [
            'total' => $sorted->count(),
            'critical' => $sorted->where('severity', 'critical')->count(),
            'high' => $sorted->whereIn('severity', ['high', 'major'])->count(),
            'unresolved' => $sorted->whereNotIn('status', ['closed', 'resolved'])->count(),
        ];

        // ── Paginate ──────────────────────────────────────────
        $page = (int) $request->input('page', 1);
        $perPage = 25;
        $paginatedItems = $sorted->slice(($page - 1) * $perPage, $perPage)->values();
        $lastPage = max(1, (int) ceil($sorted->count() / $perPage));

        $paginated = [
            'data' => $paginatedItems,
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $sorted->count(),
            'from' => $sorted->count() ? (($page - 1) * $perPage) + 1 : null,
            'to' => min($page * $perPage, $sorted->count()),
        ];

        // ── Supporting data ───────────────────────────────────
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

        return Inertia::render('control-room/incidents', [
            'incidents' => $paginated,
            'filters' => $filters,
            'stats' => $stats,
            'sites' => $sites,
            'clients' => $clients,
            'can' => [
                'createAlert' => $user->canDo('controlRoom.alerts.create'),
            ],
            // Workspace-over-list: when ?alert= is present the alert workspace
            // dialog opens over the tracker (e.g. following a linked alert).
            'detail' => fn () => $request->filled('alert')
                ? app(AlertWorkspaceService::class)->build($user, (int) $request->input('alert'))
                : null,
        ]);
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

        return back()
            ->with('success', "Incident INC-{$result['incident']->id} flagged and alert raised.")
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
        if ($this->siteAccess()->canBypass($user, $this->alertBypassPermissions())) {
            return $query;
        }

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

        if (! $siteAccess->canBypass($user, $bypassPermissions)) {
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
