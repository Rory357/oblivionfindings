<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientIncidentAttachment;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\IncidentFollowup;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsCorrectiveActionService;
use App\Services\HealthSafety\NotifiableEventClassifier;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class IncidentController extends Controller
{
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
                    $term = '%' . $q . '%';
                    $query->where(fn ($sub) => $sub->where('description', 'like', $term)
                        ->orWhere('type', 'like', $term)
                        ->orWhere('title', 'like', $term));
                })
                ->when($severity, fn ($query) => $query->where('severity', $severity))
                ->when($clientId, fn ($query) => $query->where('client_id', $clientId))
                ->when($siteId, fn ($query) => $query->whereHas('client', fn ($c) => $c->where('site_id', $siteId)))
                ->when($source, fn ($query) => $query->where('source', $source))
                ->when($from, fn ($query) => $query->whereDate('occurred_at', '>=', $from))
                ->when($to, fn ($query) => $query->whereDate('occurred_at', '<=', $to));
        };

        $applyTab = fn ($query, string $t) => match ($t) {
            'open' => $query->where('status', '!=', 'closed'),
            'investigation' => $query->whereIn('investigation_status', ['pending', 'in_progress']),
            'worksafe' => $query->where('is_notifiable', true),
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
                ->with(['incident:id,type,client_id', 'incident.client:id,first_name,last_name', 'assignedTo:id,name'])
                ->orderByRaw('due_at is null')
                ->orderBy('due_at')
                ->paginate(50)
                ->withQueryString()
                ->through(fn (IncidentFollowup $f) => [
                    'id' => $f->id,
                    'incident_id' => $f->client_incident_id,
                    'incident_type' => $f->incident?->type,
                    'client_name' => $f->incident?->client
                        ? trim(($f->incident->client->first_name ?? '') . ' ' . ($f->incident->client->last_name ?? ''))
                        : null,
                    'assigned_to' => $f->assignedTo?->name,
                    'due_at' => $f->due_at,
                    'overdue' => $f->due_at ? $f->due_at->isPast() : false,
                    'notes' => $f->notes,
                ]);
            $rowsKind = 'followups';
        } else {
            $rows = $applyTab($applyFilters(ClientIncident::query()), $tab)
                ->with(['client:id,first_name,last_name,site_id', 'client.site:id,name', 'reporter:id,name'])
                ->withCount([
                    'attachments',
                    'followups as open_followups_count' => fn ($qq) => $qq->whereNull('completed_at'),
                ])
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString()
                ->through(fn (ClientIncident $i) => [
                    'id' => $i->id,
                    'occurred_at' => $i->occurred_at,
                    'type' => $i->type,
                    'description' => $i->description,
                    'severity' => $i->severity,
                    'status' => $i->status,
                    'source' => $i->source,
                    'interactive' => $i->interactive,
                    'is_notifiable' => (bool) $i->is_notifiable,
                    'worksafe_notification_status' => $i->worksafe_notification_status,
                    'potential_severity' => $i->potential_severity,
                    'investigation_status' => $i->investigation_status,
                    'control_room_alert_id' => $i->control_room_alert_id,
                    'requires_followup' => (bool) $i->requires_followup,
                    'attachments_count' => $i->attachments_count,
                    'open_followups_count' => $i->open_followups_count,
                    'client' => $i->client ? [
                        'id' => $i->client->id,
                        'first_name' => $i->client->first_name,
                        'last_name' => $i->client->last_name,
                        'site' => $i->client->site?->name,
                    ] : null,
                    'reporter' => $i->reporter ? ['name' => $i->reporter->name] : null,
                ]);
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
        $worksafeAwaiting = $applyFilters(ClientIncident::query())
            ->where('is_notifiable', true)
            ->where(fn ($w) => $w->whereNull('worksafe_notification_status')->orWhere('worksafe_notification_status', 'pending'))
            ->count();
        $activeAlerts = $applyFilters(ClientIncident::query())
            ->whereNotNull('control_room_alert_id')
            ->whereHas('controlRoomAlert', fn ($a) => $a->whereNotIn('status', ['resolved', 'closed']))
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
            $sites = Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
            $clients = Client::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']);
        }

        // Report-wizard data for the modal-first "+ Report": clients the user may
        // report for (scoped like IncidentController@create) + follow-up owners.
        $reportClients = null;
        $reportStaff = null;
        if ($user->canDo('incidents.create')) {
            $reportClients = Client::query()
                ->when(! $user->canDo('clients.viewAny'), fn ($qq) => $qq->whereHas('supportWorkers', fn ($s) => $s->whereKey($user->id)))
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name']);
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
                'templatesManage' => $user->canDo('incidents.templates.manage'),
            ],
            // Detail-over-list: when ?incident= is present the dialog opens over
            // the register (Inertia partial-reloads only this prop). Null otherwise.
            'detail' => $request->filled('incident')
                ? $this->buildIncidentDetail($request, (int) $request->get('incident'))
                : null,
        ]);
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
                'reporter:id,name,email',
                'shift:id,starts_at,ends_at,actual_ends_at',
                'attachments.uploader:id,name',
                'followups.assignedTo:id,name',
                'followups.creator:id,name',
                'investigator:id,name',
                'controlRoomAlert:id,status,severity,alert_type,triggered_at,resolved_at',
                'safeguardingConcerns',
                'fleetIncident:id,incident_type',
            ])
            ->find($incidentId);

        if (! $incident || ! $user || $user->cannot('view', $incident)) {
            return null;
        }

        // Governance wrapper recorded by ClientIncidentObserver (idempotent).
        $hsEvent = HsEvent::query()
            ->where('source_type', ClientIncident::class)
            ->where('source_id', $incident->id)
            ->with(['latestInvestigation', 'correctiveActions.assignedTo:id,name'])
            ->first();

        $inv = $hsEvent?->latestInvestigation;

        return [
            'id' => $incident->id,
            'type' => $incident->type,
            'source' => $incident->source,
            'interactive' => $incident->interactive,
            'severity' => $incident->severity,
            'status' => $incident->status,
            'occurred_at' => $incident->occurred_at,
            'description' => $incident->description,
            'immediate_action_taken' => $incident->immediate_action_taken,
            'witnesses' => $incident->witnesses,
            'is_notifiable' => (bool) $incident->is_notifiable,
            'worksafe_notification_status' => $incident->worksafe_notification_status,
            'worksafe_notified_at' => $incident->worksafe_notified_at,
            'worksafe_reference' => $incident->worksafe_reference,
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
            'control_room_alert_id' => $incident->control_room_alert_id,
            'client' => $incident->client ? [
                'id' => $incident->client->id,
                'first_name' => $incident->client->first_name,
                'last_name' => $incident->client->last_name,
                'site' => $incident->client->site?->name,
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
            'control_room_alert' => $incident->controlRoomAlert ? [
                'id' => $incident->controlRoomAlert->id,
                'status' => $incident->controlRoomAlert->status,
                'severity' => $incident->controlRoomAlert->severity,
                'alert_type' => $incident->controlRoomAlert->alert_type,
                'triggered_at' => $incident->controlRoomAlert->triggered_at,
                'resolved_at' => $incident->controlRoomAlert->resolved_at,
            ] : null,
            'hs_event' => $hsEvent ? [
                'id' => $hsEvent->id,
                'reference_number' => $hsEvent->reference_number,
                'status' => $hsEvent->status,
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
            'can' => [
                'update' => $user->can('update', $incident),
                'submit' => $user->can('submit', $incident),
                'review' => $user->can('review', $incident),
                'close' => $user->can('close', $incident),
                'reopen' => $user->can('reopen', $incident),
                'followupsManage' => $user->canDo('incidents.followups.manage'),
                'followupsComplete' => $user->canDo('incidents.followups.complete') || $user->canDo('incidents.followups.manage'),
                'portalManage' => $user->canDo('incidents.portal.manage'),
                'raiseCorrectiveAction' => $user->canDo('incidents.viewAny') || $user->canDo('compliance.view') || $user->canDo('hazards.view'),
            ],
            // Assignee options for the add-follow-up + raise-corrective-action forms.
            'assignable_staff' => ($user->canDo('incidents.followups.manage') || $user->canDo('incidents.viewAny') || $user->canDo('compliance.view') || $user->canDo('hazards.view'))
                ? User::staff()->orderBy('name')->get(['id', 'name'])
                : [],
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
            $shift = Shift::query()->find((int) $request->query('shift_id'));
            if ($shift && ($shift->user_id === $user->id || $user->canDo('incidents.viewAny'))) {
                $params['report_shift_id'] = $shift->id;
                if ($shift->client_id) {
                    $params['report_client_id'] = (int) $shift->client_id;
                }
            }
        }
        if ($request->filled('client_id')) {
            $params['report_client_id'] = (int) $request->query('client_id');
        }

        return redirect()->route('incidents.index', $params);
    }

    public function store(Request $request, NotifiableEventClassifier $classifier)
    {
        abort_unless($request->user()?->canDo('incidents.create'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'template_id' => ['nullable', 'integer', 'exists:incident_templates,id'],
            'type' => ['required', 'string', 'max:120'],
            'severity' => ['required', 'in:low,medium,high'],
            'occurred_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'requires_followup' => ['sometimes', 'boolean'],
            'immediate_action_taken' => ['nullable', 'string'],
            'witnesses' => ['nullable', 'string'],

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

            // Report-wizard extras (one-submit report flow)
            'hazard' => ['nullable', 'string'],
            'followups' => ['nullable', 'array'],
            'followups.*.notes' => ['required_with:followups', 'string'],
            'followups.*.assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'followups.*.due_at' => ['nullable', 'date'],
        ]);

        // Server-side notifiable enforcement (NZ HSWA, G2) — escalate-only: a
        // hospitalisation / ambulance treatment, or a "notifiable" injury classification,
        // forces the WorkSafe-notifiable flag regardless of the client-side determination.
        $harmProxy = in_array($data['medical_treatment_type'] ?? null, ['hospital', 'ambulance'], true)
            ? NotifiableEventClassifier::HARM_HOSPITALISATION
            : null;
        $severityProxy = ($data['injury_classification'] ?? null) === 'notifiable'
            ? NotifiableEventClassifier::SEVERITY_CRITICAL
            : null;
        $isNotifiable = $classifier->isNotifiable($harmProxy, $severityProxy)
            || (bool) ($data['is_notifiable'] ?? false);

        $client = Client::query()->findOrFail($data['client_id']);
        $this->authorize('view', $client);

        // Only persist shift_id when the reporter is the assigned worker on
        // that shift (or a manager). Stops a worker forging the link.
        $shiftId = null;
        if (! empty($data['shift_id'])) {
            $shift = Shift::query()->find((int) $data['shift_id']);
            if ($shift && ($shift->user_id === $request->user()?->id || $request->user()?->canDo('incidents.viewAny'))) {
                $shiftId = $shift->id;
            }
        }

        $incident = ClientIncident::create([
            'client_id' => $client->id,
            'reported_by' => $request->user()?->id,
            'shift_id' => $shiftId,
            'template_id' => $data['template_id'] ?? null,
            'type' => $data['type'],
            'severity' => $data['severity'],
            'status' => 'draft',
            'source' => 'manual',
            'occurred_at' => $data['occurred_at'] ?? now(),
            'description' => $data['description'] ?? null,
            'requires_followup' => (bool)($data['requires_followup'] ?? false) || ! empty($data['followups']),
            'immediate_action_taken' => $data['immediate_action_taken'] ?? null,
            'witnesses' => $data['witnesses'] ?? null,
            'title' => $data['type'] . ' incident',
            'metadata' => $request->filled('hazard') ? ['hazard' => $data['hazard']] : null,

            // Near-miss
            'potential_severity' => $data['potential_severity'] ?? null,
            'potential_consequence' => $data['potential_consequence'] ?? null,

            // Injury details
            'injured_person_name' => $data['injured_person_name'] ?? null,
            'injured_person_role' => $data['injured_person_role'] ?? null,
            'injured_person_age' => $data['injured_person_age'] ?? null,
            'injury_body_part' => $data['injury_body_part'] ?? null,
            'injury_nature' => $data['injury_nature'] ?? null,
            'injury_classification' => $data['injury_classification'] ?? null,
            'medical_treatment_type' => $data['medical_treatment_type'] ?? null,

            // WorkSafe
            'is_notifiable' => $isNotifiable,
            'site_preserved' => (bool) ($data['site_preserved'] ?? false),
            'worksafe_reference' => $data['worksafe_reference'] ?? null,
            'worksafe_notification_status' => $isNotifiable ? 'pending' : null,
        ]);

        // Auto-escalate abuse/neglect incidents to safeguarding
        if (preg_match('/abuse|neglect/i', $incident->type)) {
            \App\Models\SafeguardingConcern::create([
                'subject_type' => \App\Models\Client::class,
                'subject_id' => $client->id,
                'subject_name' => $client->first_name . ' ' . $client->last_name,
                'concern_type' => 'incident_escalation',
                'severity' => $incident->severity,
                'description' => $incident->description,
                'occurred_at' => $incident->occurred_at,
                'reported_by_user_id' => $request->user()?->id,
                'reported_by_name' => $request->user()?->name,
                'status' => 'open',
                'requires_external_referral' => true,
                'related_incident_id' => $incident->id,
                'created_by' => $request->user()?->id,
            ]);
        }

        // High severity alert -> managers only (drafts can still be high severity)
        if ($incident->severity === 'high') {
            app(NotificationService::class)->notifyCrud(
                $request->user(),
                'created',
                'incident',
                $incident,
                $client,
                [
                    'event_key' => 'incidents.high_severity_alert',
                    'severity' => $incident->severity,
                    'title' => 'High severity incident drafted',
                    'body' => "Client: {$client->first_name} {$client->last_name}\nType: {$incident->type}\nSeverity: {$incident->severity}",
                    'url' => url("/incidents/{$incident->id}"),
                    'include_assigned_workers' => false,
                ]
            );
        }

        // Report-wizard follow-ups: created alongside the incident in one submit.
        foreach ($data['followups'] ?? [] as $fu) {
            $incident->followups()->create([
                'notes' => $fu['notes'],
                'assigned_to_user_id' => $fu['assigned_to_user_id'] ?? null,
                'due_at' => $fu['due_at'] ?? null,
                'created_by' => $request->user()?->id,
            ]);
        }

        // In-place wizard (the modal-first Report flow / H&S command-centre): stay
        // on the referring page so its props refresh and the success pane can show.
        if ($request->boolean('stay')) {
            return back()
                ->with('success', 'Incident recorded.')
                ->with('created_incident_id', $incident->id);
        }

        if ($request->boolean('continue_wizard')) {
            return redirect()
                ->route('incidents.create', ['incident' => $incident->id])
                ->with('success', 'Incident saved. Add any extra detail below.');
        }

        return redirect()->route('incidents.show', $incident)->with('success', 'Incident draft created.');
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
        if ($user && $incident->isEditableByReporter($user) && !$user->canDo('incidents.viewAny')) {
            unset($data['review_notes']);
            unset($data['portal_visible']);
        }

        if ($user && !$user->canDo('incidents.portal.manage')) {
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
            ] as $field) {
                unset($data[$field]);
            }
        }

        $incident->update([
            ...$data,
            'title' => ($data['type'] ?? $incident->type) . ' incident',
        ]);

        return back()->with('success', 'Incident updated.');
    }

    public function updateAttachment(Request $request, ClientIncident $incident, ClientIncidentAttachment $attachment)
    {
        $this->authorize('view', $incident);
        abort_unless($request->user()?->canDo('incidents.portal.manage'), 403);

        abort_unless((int)$attachment->incident_id === (int)$incident->id, 404);

        $data = $request->validate([
            'portal_visible' => ['required', 'boolean'],
        ]);

        $attachment->update([
            'portal_visible' => (bool)$data['portal_visible'],
        ]);

        return back()->with('success', 'Attachment sharing updated.');
    }

    public function submit(Request $request, ClientIncident $incident)
    {
        $this->authorize('submit', $incident);

        $incident->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $incident->load(['client:id,first_name,last_name']);
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
                // Submission is for managers to action; avoid pinging the submitter again.
                'include_entity_user' => false,
            ]
        );

        // High severity: extra managers-only alert on submission.
        if ($incident->severity === 'high') {
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
                ]
            );
        }

        return back()->with('success', 'Incident submitted.');
    }

    public function review(Request $request, ClientIncident $incident)
    {
        $this->authorize('review', $incident);

        // Guardrail: review is only valid for submitted incidents.
        abort_unless($incident->status === 'submitted', 403);

        $data = $request->validate([
            'review_notes' => ['nullable', 'string'],
        ]);

        $incident->update([
            'status' => 'reviewed',
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
            'review_notes' => $data['review_notes'] ?? $incident->review_notes,
        ]);

        $incident->load(['client:id,first_name,last_name']);
        $client = $incident->client;

        // Notify reporter + assigned workers that the incident has been reviewed.
        $targets = [];
        if (!empty($incident->reported_by)) {
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

        // Guardrail: closing is only valid for reviewed incidents.
        abort_unless($incident->status === 'reviewed', 403);

        $data = $request->validate([
            'closed_outcome' => ['required', 'string', 'max:120'],
            'closed_notes' => ['nullable', 'string'],
        ]);

        // Guardrail: high-severity incidents require a completed investigation before closure.
        if (in_array($incident->severity, ['high', 'critical'], true) && $incident->investigation_status !== 'completed') {
            return back()->with('error', 'High-severity incidents require a completed investigation before closure.');
        }

        // Guardrail: incidents cannot be closed while there are any open follow-ups.
        // This applies if follow-ups were explicitly flagged *or* any follow-up records exist.
        $hasOpenFollowups = $incident->followups()->whereNull('completed_at')->exists();
        if ($hasOpenFollowups) {
            return back()->with('error', 'There are open follow-ups. Please complete them before closing the incident.');
        }

        $incident->update([
            'status' => 'closed',
            'closed_by' => $request->user()?->id,
            'closed_at' => now(),
            'closed_outcome' => $data['closed_outcome'],
            'closed_notes' => $data['closed_notes'] ?? null,
        ]);

        // State-sync (Gap D): closing the system-of-record resolves the linked
        // Control Room alert so the two stay coherent and it leaves the live queue.
        $this->resolveLinkedAlertOnClose($incident, $request->user()?->id);

        $incident->load(['client:id,first_name,last_name']);
        $client = $incident->client;

        // Notify reporter + assigned workers that the incident has been closed.
        $targets = [];
        if (!empty($incident->reported_by)) {
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

        return back()->with('success', 'Incident closed.');
    }

    /**
     * Resolve the Control Room alert linked to a just-closed incident (Gap D).
     * Only an actionable alert is transitioned; failures never block the close.
     */
    private function resolveLinkedAlertOnClose(ClientIncident $incident, ?int $userId): void
    {
        if (! $incident->control_room_alert_id) {
            return;
        }

        try {
            $alert = ControlRoomAlert::find($incident->control_room_alert_id);
            if ($alert && $alert->isActionable() && $alert->canTransitionTo(ControlRoomAlert::STATUS_RESOLVED)) {
                $alert->update([
                    'status' => ControlRoomAlert::STATUS_RESOLVED,
                    'resolved_at' => now(),
                    'resolved_by_user_id' => $userId,
                    'resolution_code' => $alert->resolution_code ?? 'incident_closed',
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('IncidentController: failed to resolve linked alert on incident close', [
                'incident_id' => $incident->id,
                'alert_id' => $incident->control_room_alert_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function reopen(Request $request, ClientIncident $incident)
    {
        $this->authorize('reopen', $incident);

        // Only closed incidents may be reopened.
        abort_unless($incident->status === 'closed', 403);

        $data = $request->validate([
            'reopened_reason' => ['required', 'string', 'max:2000'],
        ]);

        $incident->update([
            'status' => 'reviewed',
            'reopened_by' => $request->user()?->id,
            'reopened_at' => now(),
            'reopened_reason' => $data['reopened_reason'],

            // clear closure fields so the incident becomes "open" again
            'closed_by' => null,
            'closed_at' => null,
            'closed_outcome' => null,
            'closed_notes' => null,
        ]);

        $incident->load(['client:id,first_name,last_name']);
        $client = $incident->client;

        // Notify reporter that the incident has been reopened.
        $targets = [];
        if (!empty($incident->reported_by)) {
            $targets[] = (int) $incident->reported_by;
        }

        app(\App\Services\NotificationService::class)->notifyCrud(
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
    public function raiseCorrectiveAction(Request $request, ClientIncident $incident, HsCorrectiveActionService $service)
    {
        $this->authorize('view', $incident);
        $user = $request->user();
        abort_unless($user && ($user->canDo('incidents.viewAny') || $user->canDo('compliance.view') || $user->canDo('hazards.view')), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', 'in:low,medium,high,critical'],
            'due_date' => ['nullable', 'date'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

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

        $service->createStandalone($hsEvent, [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'action_type' => 'corrective',
            'priority' => $data['priority'] ?? 'medium',
            'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
            'assigned_by_user_id' => $user->id,
            'due_date' => $data['due_date'] ?? null,
            'created_by' => $user->id,
        ]);

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
        $disk = 'public';
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

        abort_unless((int)$attachment->incident_id === (int)$incident->id, 404);

        // Attachments may be removed only while incident is editable by reporter (admins/managers can also remove if they can update)
        $user = $request->user();
        if ($user && !$user->canDo('incidents.viewAny')) {
            abort_unless($incident->isEditableByReporter($user), 403);
        }

        $disk = $attachment->disk ?: 'public';
        if ($attachment->path && Storage::disk($disk)->exists($attachment->path)) {
            Storage::disk($disk)->delete($attachment->path);
        }

        $attachment->delete();

        return back()->with('success', 'Attachment removed.');
    }

    public function downloadAttachment(Request $request, ClientIncident $incident, ClientIncidentAttachment $attachment)
    {
        $this->authorize('view', $incident);
        abort_unless((int)$attachment->incident_id === (int)$incident->id, 404);

        $disk = $attachment->disk ?: 'public';
        return Storage::disk($disk)->download($attachment->path, $attachment->original_name);
    }
}
