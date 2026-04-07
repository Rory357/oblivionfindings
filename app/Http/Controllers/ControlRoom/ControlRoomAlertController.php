<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ControlRoomAlert;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ControlRoomAlertController extends Controller
{
    /**
     * Display the alerts list with filters, sorting and stats.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        $query = ControlRoomAlert::with([
            'asset:id,name,asset_tag',
            'assignedTo:id,name,email',
            'client:id,first_name,last_name',
            'sla',
        ]);

        $this->siteAccess()->applyAlertScope($query, $user, $this->alertBypassPermissions());

        // Filters
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($severity = $request->input('severity')) {
            $query->where('severity', $severity);
        }

        if ($source = $request->input('source')) {
            $query->where('source', $source);
        }

        if ($assignedTo = $request->input('assigned_to')) {
            if ($assignedTo === 'me') {
                $query->where('assigned_to_user_id', $user->id);
            } elseif ($assignedTo === 'unassigned') {
                $query->whereNull('assigned_to_user_id');
            } else {
                $query->where('assigned_to_user_id', (int) $assignedTo);
            }
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('alert_type', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhere('source', 'like', "%{$search}%");
            });
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->where('triggered_at', '>=', Carbon::parse($dateFrom)->startOfDay());
        }

        if ($dateTo = $request->input('date_to')) {
            $query->where('triggered_at', '<=', Carbon::parse($dateTo)->endOfDay());
        }

        // Sorting
        $sortField = $request->input('sort', 'triggered_at');
        $sortDir = $request->input('dir', 'desc');
        $allowedSorts = ['triggered_at', 'severity', 'status', 'alert_type'];
        if (in_array($sortField, $allowedSorts)) {
            if ($sortField === 'severity') {
                $query->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low') " . ($sortDir === 'desc' ? 'DESC' : 'ASC'));
            } else {
                $query->orderBy($sortField, $sortDir === 'asc' ? 'asc' : 'desc');
            }
        } else {
            $query->orderByDesc('triggered_at');
        }

        $paginated = $query->paginate(30)->withQueryString();

        $alerts = $paginated->through(fn(ControlRoomAlert $alert) => [
            'id' => $alert->id,
            'source' => $alert->source,
            'alert_type' => $alert->alert_type,
            'severity' => $alert->severity,
            'status' => $alert->status,
            'escalation_level' => $alert->escalation_level,
            'triggered_at' => optional($alert->triggered_at)->toISOString(),
            'asset' => $alert->asset ? [
                'id' => $alert->asset->id,
                'name' => $alert->asset->name,
                'asset_tag' => $alert->asset->asset_tag,
            ] : null,
            'assigned_to' => $alert->assignedTo ? [
                'id' => $alert->assignedTo->id,
                'name' => $alert->assignedTo->name,
            ] : null,
            'client_name' => $alert->client
                ? trim($alert->client->first_name . ' ' . $alert->client->last_name)
                : null,
            'sla_status' => $this->deriveSlaStatus($alert),
            'notes' => $alert->notes ? \Illuminate\Support\Str::limit($alert->notes, 120) : null,
        ]);

        // Stats (unfiltered counts)
        $statsBase = ControlRoomAlert::query();
        $this->siteAccess()->applyAlertScope($statsBase, $user, $this->alertBypassPermissions());

        $stats = [
            'total' => (clone $statsBase)->count(),
            'open' => (clone $statsBase)->where('status', 'open')->count(),
            'critical' => (clone $statsBase)->where('severity', 'critical')->whereNotIn('status', ['resolved', 'closed'])->count(),
            'assigned_to_me' => (clone $statsBase)->where('assigned_to_user_id', $user->id)->whereNotIn('status', ['resolved', 'closed'])->count(),
            'unassigned' => (clone $statsBase)->whereNull('assigned_to_user_id')->whereNotIn('status', ['resolved', 'closed'])->count(),
        ];

        $staff = User::staff()
            ->whereHas('roles', fn($q) => $q->whereIn('name', ['admin', 'provider_manager', 'coordinator']))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('control-room/alerts/index', [
            'alerts' => $alerts,
            'filters' => $request->only(['status', 'severity', 'source', 'assigned_to', 'search', 'date_from', 'date_to', 'sort', 'dir']),
            'stats' => $stats,
            'staff' => $staff,
            'can' => [
                'manage' => $user->canDo('controlRoom.alerts.manage'),
                'assign' => $user->canDo('controlRoom.alerts.assign'),
            ],
        ]);
    }

    /**
     * Derive SLA status for a given alert (green/yellow/red/none).
     */
    private function deriveSlaStatus(ControlRoomAlert $alert): ?string
    {
        if (!$alert->sla) {
            return null;
        }

        $sla = $alert->sla;
        if ($sla->resolution_breached || $sla->response_breached || $sla->acknowledge_breached) {
            return 'red';
        }

        // Check if any deadline is approaching (within 30 minutes)
        $now = now();
        $deadlines = array_filter([
            $sla->acknowledge_deadline,
            $sla->response_deadline,
            $sla->resolution_deadline,
        ]);

        foreach ($deadlines as $deadline) {
            if ($deadline && $deadline->gt($now) && $deadline->diffInMinutes($now) <= 30) {
                return 'yellow';
            }
        }

        return 'green';
    }

    /**
     * Bulk acknowledge multiple alerts.
     */
    public function bulkAcknowledge(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $data = $request->validate([
            'alert_ids' => ['required', 'array'],
            'alert_ids.*' => ['integer'],
        ]);

        $alerts = ControlRoomAlert::whereIn('id', $data['alert_ids'])
            ->where('status', 'open')
            ->tap(fn ($query) => $this->siteAccess()->applyAlertScope($query, $user, $this->alertBypassPermissions()))
            ->get();

        abort_if($alerts->count() !== count($data['alert_ids']), 403, 'You are not authorized to manage one or more selected alerts.');

        $count = 0;
        foreach ($alerts as $alert) {
            $alert->update([
                'status' => 'ack',
                'acknowledged_at' => now(),
                'acknowledged_by_user_id' => $user->id,
            ]);

            $alert->sla?->recordAcknowledge();

            AuditLogger::log('controlRoom.alert.acknowledge', $alert, [
                'alert_id' => $alert->id,
                'acknowledged_by' => $user->id,
                'bulk' => true,
            ]);

            $count++;
        }

        return back()->with('success', "{$count} alert(s) acknowledged.");
    }

    /**
     * Bulk assign multiple alerts to a staff member.
     */
    public function bulkAssign(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.assign'), 403);

        $data = $request->validate([
            'alert_ids' => ['required', 'array'],
            'alert_ids.*' => ['integer'],
            'assigned_to_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $alerts = ControlRoomAlert::whereIn('id', $data['alert_ids'])
            ->tap(fn ($query) => $this->siteAccess()->applyAlertScope($query, $user, $this->alertBypassPermissions()))
            ->get();

        abort_if($alerts->count() !== count($data['alert_ids']), 403, 'You are not authorized to assign one or more selected alerts.');

        $count = 0;
        foreach ($alerts as $alert) {
            $alert->update([
                'assigned_to_user_id' => $data['assigned_to_user_id'],
                'assigned_at' => now(),
                'assigned_by_user_id' => $user->id,
            ]);

            AuditLogger::log('controlRoom.alert.assign', $alert, [
                'alert_id' => $alert->id,
                'assigned_to' => $data['assigned_to_user_id'],
                'assigned_by' => $user->id,
                'bulk' => true,
            ]);

            $count++;
        }

        return back()->with('success', "{$count} alert(s) assigned.");
    }

    /**
     * Assign an alert to the current user (shortcut).
     */
    public function assignToMe(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.assign'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $alert->update([
            'assigned_to_user_id' => $user->id,
            'assigned_at' => now(),
            'assigned_by_user_id' => $user->id,
        ]);

        AuditLogger::log('controlRoom.alert.assign', $alert, [
            'alert_id' => $alert->id,
            'assigned_to' => $user->id,
            'assigned_by' => $user->id,
            'self_assign' => true,
        ]);

        return back()->with('success', 'Alert assigned to you.');
    }

    /**
     * Display the specified alert.
     */
    public function show(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $alert->load([
            'asset:id,name,asset_tag',
            'fleetSignal',
            'assignedTo:id,name,email',
            'acknowledgedBy:id,name',
            'resolvedBy:id,name',
            'closedBy:id,name',
            'escalatedBy:id,name',
            'assignedBy:id,name',
            'createdBy:id,name',
            'playbookRun.playbook',
            'playbookRun.steps',
            'evidencePacks.evidenceItems',
            'communications',
            'sla.slaDefinition',
            'client:id,first_name,last_name',
            'device:id,type,latitude,longitude,location_description',
            'tasks' => fn($q) => $q->whereNull('parent_task_id')->orderBy('sort_order')->with(['assignedTo:id,name', 'subtasks.assignedTo:id,name']),
            'discussions' => fn($q) => $q->whereNull('parent_id')->orderBy('created_at', 'asc')->with(['user:id,name', 'replies' => fn($r) => $r->orderBy('created_at', 'asc')->with('user:id,name')]),
            'watchers.user:id,name',
            'timeEntries' => fn($q) => $q->orderBy('created_at', 'desc')->with('user:id,name'),
        ]);

        // Fetch audit trail for this alert
        $auditLogs = AuditLog::query()
            ->where('auditable_type', ControlRoomAlert::class)
            ->where('auditable_id', $alert->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                'meta' => $log->meta,
                'created_at' => $log->created_at->toISOString(),
            ]);

        AuditLogger::log('controlRoom.alert.view', $alert, [
            'alert_id' => $alert->id,
        ]);

        return Inertia::render('control-room/show', [
            'alert' => [
                'id' => $alert->id,
                'source' => $alert->source,
                'alert_type' => $alert->alert_type,
                'severity' => $alert->severity,
                'status' => $alert->status,
                'asset_id' => $alert->asset_id,
                'asset' => $alert->asset ? [
                    'id' => $alert->asset->id,
                    'name' => $alert->asset->name,
                    'asset_tag' => $alert->asset->asset_tag,
                ] : null,
                'fleet_signal_id' => $alert->fleet_signal_id,
                'fleet_signal' => $alert->fleetSignal ? [
                    'id' => $alert->fleetSignal->id,
                    'signal_type' => $alert->fleetSignal->signal_type,
                    'severity_hint' => $alert->fleetSignal->severity_hint,
                    'occurred_at' => optional($alert->fleetSignal->occurred_at)->toISOString(),
                    'payload' => $alert->fleetSignal->payload,
                ] : null,
                'fleet_context' => $alert->context['fleet_context']
                    ?? $alert->context['normalized_data']['fleet_context']
                    ?? null,
                'assigned_to_user_id' => $alert->assigned_to_user_id,
                'assigned_to' => $alert->assignedTo ? [
                    'id' => $alert->assignedTo->id,
                    'name' => $alert->assignedTo->name,
                    'email' => $alert->assignedTo->email,
                ] : null,
                'acknowledged_by' => $alert->acknowledgedBy ? [
                    'id' => $alert->acknowledgedBy->id,
                    'name' => $alert->acknowledgedBy->name,
                ] : null,
                'resolved_by' => $alert->resolvedBy ? [
                    'id' => $alert->resolvedBy->id,
                    'name' => $alert->resolvedBy->name,
                ] : null,
                'closed_by' => $alert->closedBy ? [
                    'id' => $alert->closedBy->id,
                    'name' => $alert->closedBy->name,
                ] : null,
                'escalated_by' => $alert->escalatedBy ? [
                    'id' => $alert->escalatedBy->id,
                    'name' => $alert->escalatedBy->name,
                ] : null,
                'assigned_by' => $alert->assignedBy ? [
                    'id' => $alert->assignedBy->id,
                    'name' => $alert->assignedBy->name,
                ] : null,
                'created_by' => $alert->createdBy ? [
                    'id' => $alert->createdBy->id,
                    'name' => $alert->createdBy->name,
                ] : null,
                'triggered_at' => optional($alert->triggered_at)->toISOString(),
                'acknowledged_at' => optional($alert->acknowledged_at)->toISOString(),
                'resolved_at' => optional($alert->resolved_at)->toISOString(),
                'closed_at' => optional($alert->closed_at)->toISOString(),
                'escalated_at' => optional($alert->escalated_at)->toISOString(),
                'assigned_at' => optional($alert->assigned_at)->toISOString(),
                'escalation_level' => $alert->escalation_level,
                'context' => $alert->context,
                'notes' => $alert->notes,
                'priority' => $alert->priority,
                'due_at' => optional($alert->due_at)->toISOString(),
                'category' => $alert->category,
                'resolution_code' => $alert->resolution_code,
                'created_at' => optional($alert->created_at)->toISOString(),
                'updated_at' => optional($alert->updated_at)->toISOString(),
            ],
            'playbook_run' => $alert->playbookRun ? [
                'id' => $alert->playbookRun->id,
                'status' => $alert->playbookRun->status,
                'current_step' => $alert->playbookRun->current_step,
                'completed_steps' => $alert->playbookRun->completed_steps,
                'total_steps' => $alert->playbookRun->total_steps,
                'playbook' => [
                    'id' => $alert->playbookRun->playbook->id,
                    'name' => $alert->playbookRun->playbook->name,
                    'category' => $alert->playbookRun->playbook->category,
                ],
                'steps' => $alert->playbookRun->steps->map(fn($s) => [
                    'id' => $s->id,
                    'title' => $s->playbook_step_title ?? 'Step ' . $s->step_number,
                    'status' => $s->status,
                    'notes' => $s->notes,
                    'completed_at' => optional($s->completed_at)->toISOString(),
                ])->values(),
            ] : null,
            'evidence_packs' => $alert->evidencePacks->map(fn($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'status' => $p->status,
                'item_count' => $p->item_count,
                'items' => $p->evidenceItems->map(fn($i) => [
                    'id' => $i->id,
                    'type' => $i->type,
                    'title' => $i->title,
                    'file_path' => $i->file_path,
                    'created_at' => optional($i->created_at)->toISOString(),
                ])->values(),
            ])->values(),
            'communications' => $alert->communications->map(fn($c) => [
                'id' => $c->id,
                'channel' => $c->channel,
                'direction' => $c->direction,
                'purpose' => $c->purpose,
                'status' => $c->status,
                'content' => $c->content,
                'target_user_name' => $c->user?->name,
                'sent_at' => optional($c->sent_at)->toISOString(),
                'created_at' => optional($c->created_at)->toISOString(),
            ])->values(),
            'sla' => $alert->sla ? [
                'acknowledge_deadline' => optional($alert->sla->acknowledge_deadline)->toISOString(),
                'response_deadline' => optional($alert->sla->response_deadline)->toISOString(),
                'resolution_deadline' => optional($alert->sla->resolution_deadline)->toISOString(),
                'acknowledge_breached' => $alert->sla->acknowledge_breached,
                'response_breached' => $alert->sla->response_breached,
                'resolution_breached' => $alert->sla->resolution_breached,
            ] : null,
            'client' => $alert->client ? [
                'id' => $alert->client->id,
                'name' => trim($alert->client->first_name . ' ' . $alert->client->last_name),
            ] : null,
            'location' => $alert->device && $alert->device->latitude ? [
                'lat' => (float) $alert->device->latitude,
                'lng' => (float) $alert->device->longitude,
                'description' => $alert->device->location_description,
            ] : null,
            'audit_logs' => $auditLogs,
            'can' => [
                'manage' => $user->canDo('controlRoom.alerts.manage'),
                'assign' => $user->canDo('controlRoom.alerts.assign'),
                'escalate' => $user->canDo('controlRoom.alerts.escalate'),
            ],
            'staff' => User::staff()
                ->whereHas('roles', fn($q) => $q->whereIn('name', ['admin', 'provider_manager', 'coordinator']))
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'tasks' => $alert->tasks->map(fn($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'status' => $t->status,
                'priority' => $t->priority,
                'due_at' => $t->due_at?->toISOString(),
                'completed_at' => $t->completed_at?->toISOString(),
                'estimated_minutes' => $t->estimated_minutes,
                'actual_minutes' => $t->actual_minutes,
                'sort_order' => $t->sort_order,
                'assigned_to' => $t->assignedTo ? ['id' => $t->assignedTo->id, 'name' => $t->assignedTo->name] : null,
                'created_by_name' => $t->createdBy?->name,
                'subtasks' => $t->subtasks->map(fn($st) => [
                    'id' => $st->id, 'title' => $st->title, 'status' => $st->status,
                    'assigned_to' => $st->assignedTo ? ['id' => $st->assignedTo->id, 'name' => $st->assignedTo->name] : null,
                ])->values(),
                'created_at' => $t->created_at->toISOString(),
            ])->values(),
            'discussions' => $alert->discussions->map(fn($d) => [
                'id' => $d->id,
                'type' => $d->type,
                'content' => $d->content,
                'is_internal' => $d->is_internal,
                'attachments' => $d->attachments ?? [],
                'mentions' => $d->mentions ?? [],
                'user' => ['id' => $d->user->id, 'name' => $d->user->name],
                'edited_at' => $d->edited_at?->toISOString(),
                'created_at' => $d->created_at->toISOString(),
                'replies' => $d->replies->map(fn($r) => [
                    'id' => $r->id, 'type' => $r->type, 'content' => $r->content,
                    'is_internal' => $r->is_internal, 'attachments' => $r->attachments ?? [],
                    'user' => ['id' => $r->user->id, 'name' => $r->user->name],
                    'edited_at' => $r->edited_at?->toISOString(),
                    'created_at' => $r->created_at->toISOString(),
                ])->values(),
            ])->values(),
            'watchers' => $alert->watchers->map(fn($w) => [
                'id' => $w->id,
                'user_id' => $w->user_id,
                'user_name' => $w->user->name,
            ])->values(),
            'time_entries' => $alert->timeEntries->map(fn($te) => [
                'id' => $te->id,
                'user_name' => $te->user->name,
                'user_id' => $te->user_id,
                'started_at' => $te->started_at?->toISOString(),
                'ended_at' => $te->ended_at?->toISOString(),
                'duration_minutes' => $te->duration_minutes,
                'description' => $te->description,
                'task_id' => $te->task_id,
                'is_running' => $te->started_at && !$te->ended_at,
                'created_at' => $te->created_at->toISOString(),
            ])->values(),
            'time_spent_minutes' => $alert->time_spent_minutes ?? 0,
            'is_watching' => $alert->watchers->contains('user_id', $user->id),
            'config_options' => [
                'categories' => \App\Models\ControlRoom\ConfigOption::forGroup('category'),
                'resolution_codes' => \App\Models\ControlRoom\ConfigOption::forGroup('resolution_code'),
            ],
        ]);
    }

    /**
     * Acknowledge an alert.
     */
    public function acknowledge(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        if ($alert->status === 'closed' || $alert->status === 'resolved') {
            return back()->withErrors(['alert' => 'Cannot acknowledge a closed or resolved alert.']);
        }

        $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $alert->update([
            'status' => 'ack',
            'acknowledged_at' => now(),
            'acknowledged_by_user_id' => $user->id,
            'notes' => $request->input('notes') ?: $alert->notes,
        ]);

        $alert->sla?->recordAcknowledge();

        AuditLogger::log('controlRoom.alert.acknowledge', $alert, [
            'alert_id' => $alert->id,
            'acknowledged_by' => $user->id,
        ]);

        return back()->with('success', 'Alert acknowledged.');
    }

    /**
     * Start triaging an alert.
     */
    public function triage(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        if (!in_array($alert->status, ['open', 'ack'])) {
            return back()->withErrors(['alert' => 'Alert must be open or acknowledged to start triage.']);
        }

        $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $alert->update([
            'status' => 'triaging',
            'notes' => $request->input('notes') ?: $alert->notes,
        ]);

        $alert->sla?->recordResponse();

        AuditLogger::log('controlRoom.alert.triage', $alert, [
            'alert_id' => $alert->id,
            'triaged_by' => $user->id,
        ]);

        return back()->with('success', 'Alert is now being triaged.');
    }

    /**
     * Resolve an alert.
     */
    public function resolve(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        if ($alert->status === 'closed' || $alert->status === 'resolved') {
            return back()->withErrors(['alert' => 'Alert is already resolved or closed.']);
        }

        $request->validate([
            'resolution_notes' => ['required', 'string', 'max:2000'],
        ]);

        $alert->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by_user_id' => $user->id,
            'notes' => $request->input('resolution_notes'),
        ]);

        $alert->sla?->recordResolution();

        AuditLogger::log('controlRoom.alert.resolve', $alert, [
            'alert_id' => $alert->id,
            'resolved_by' => $user->id,
        ]);

        return back()->with('success', 'Alert resolved.');
    }

    /**
     * Close an alert (final state).
     */
    public function close(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        if ($alert->status === 'closed') {
            return back()->withErrors(['alert' => 'Alert is already closed.']);
        }

        $request->validate([
            'closure_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $alert->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by_user_id' => $user->id,
            'notes' => $request->input('closure_notes') ?: $alert->notes,
        ]);

        AuditLogger::log('controlRoom.alert.close', $alert, [
            'alert_id' => $alert->id,
            'closed_by' => $user->id,
        ]);

        return back()->with('success', 'Alert closed.');
    }

    /**
     * Assign an alert to a staff member.
     */
    public function assign(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.assign'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $data = $request->validate([
            'assigned_to_user_id' => ['required', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        // Record assignment change in history
        $assignmentHistory = $alert->context['assignment_history'] ?? [];
        $assignmentHistory[] = [
            'action' => $alert->assigned_to_user_id ? 'reassigned' : 'assigned',
            'from_user_id' => $alert->assigned_to_user_id,
            'from_user_name' => $alert->assignedTo?->name,
            'to_user_id' => $data['assigned_to_user_id'],
            'to_user_name' => User::find($data['assigned_to_user_id'])?->name,
            'by_user_id' => $user->id,
            'by_user_name' => $user->name,
            'reason' => $data['reason'] ?? null,
            'at' => now()->toISOString(),
        ];

        $alert->update([
            'assigned_to_user_id' => $data['assigned_to_user_id'],
            'assigned_at' => now(),
            'assigned_by_user_id' => $user->id,
            'notes' => $data['notes'] ?? $alert->notes,
            'context' => array_merge($alert->context ?? [], [
                'assignment_history' => $assignmentHistory,
            ]),
        ]);

        AuditLogger::log('controlRoom.alert.assign', $alert, [
            'alert_id' => $alert->id,
            'assigned_to' => $data['assigned_to_user_id'],
            'assigned_by' => $user->id,
        ]);

        return back()->with('success', 'Alert assigned.');
    }

    /**
     * Unassign an alert.
     */
    public function unassign(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.assign'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $previousAssignee = $alert->assigned_to_user_id;

        // Record unassignment in history
        $assignmentHistory = $alert->context['assignment_history'] ?? [];
        $assignmentHistory[] = [
            'action' => 'unassigned',
            'from_user_id' => $alert->assigned_to_user_id,
            'from_user_name' => $alert->assignedTo?->name,
            'to_user_id' => null,
            'to_user_name' => null,
            'by_user_id' => $user->id,
            'by_user_name' => $user->name,
            'reason' => null,
            'at' => now()->toISOString(),
        ];

        $alert->update([
            'assigned_to_user_id' => null,
            'assigned_at' => null,
            'assigned_by_user_id' => null,
            'context' => array_merge($alert->context ?? [], [
                'assignment_history' => $assignmentHistory,
            ]),
        ]);

        AuditLogger::log('controlRoom.alert.unassign', $alert, [
            'alert_id' => $alert->id,
            'previous_assignee' => $previousAssignee,
            'unassigned_by' => $user->id,
        ]);

        return back()->with('success', 'Alert unassigned.');
    }

    /**
     * Escalate an alert.
     */
    public function escalate(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.escalate'), 403);
        $this->assertCanAccessAlert($user, $alert);

        if ($alert->status === 'closed' || $alert->status === 'resolved') {
            return back()->withErrors(['alert' => 'Cannot escalate a closed or resolved alert.']);
        }

        $data = $request->validate([
            'escalation_reason' => ['required', 'string', 'max:1000'],
            'escalation_level' => ['nullable', 'integer', 'min:1'],
        ]);

        $newLevel = $data['escalation_level'] ?? (($alert->escalation_level ?? 0) + 1);

        $alert->update([
            'escalation_level' => min($newLevel, 5),
            'escalated_at' => now(),
            'escalated_by_user_id' => $user->id,
            'context' => array_merge($alert->context ?? [], [
                'escalation_history' => array_merge($alert->context['escalation_history'] ?? [], [
                    [
                        'level' => $newLevel,
                        'reason' => $data['escalation_reason'],
                        'escalated_by' => $user->id,
                        'escalated_at' => now()->toISOString(),
                    ],
                ]),
            ]),
        ]);

        AuditLogger::log('controlRoom.alert.escalate', $alert, [
            'alert_id' => $alert->id,
            'escalation_level' => $newLevel,
            'escalated_by' => $user->id,
        ]);

        return back()->with('success', 'Alert escalated to level ' . $newLevel . '.');
    }

    /**
     * Add a note to an alert.
     */
    public function addNote(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $existingNotes = $alert->context['activity_log'] ?? [];
        $existingNotes[] = [
            'type' => 'note',
            'content' => $data['note'],
            'user_id' => $user->id,
            'user_name' => $user->name,
            'created_at' => now()->toISOString(),
        ];

        $alert->update([
            'context' => array_merge($alert->context ?? [], [
                'activity_log' => $existingNotes,
            ]),
        ]);

        AuditLogger::log('controlRoom.alert.addNote', $alert, [
            'alert_id' => $alert->id,
        ]);

        return back()->with('success', 'Note added.');
    }

    /**
     * Update alert meta fields (priority, category, due_at, resolution_code).
     */
    public function updateMeta(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $data = $request->validate([
            'priority' => ['nullable', 'in:critical,high,medium,low'],
            'category' => ['nullable', 'string', 'max:100'],
            'due_at' => ['nullable', 'date'],
            'resolution_code' => ['nullable', 'string', 'max:100'],
        ]);

        // Update only the fields that were actually provided in the request
        $fieldsToUpdate = [];
        foreach (['priority', 'category', 'due_at', 'resolution_code'] as $field) {
            if ($request->has($field)) {
                $fieldsToUpdate[$field] = $data[$field];
            }
        }

        if (!empty($fieldsToUpdate)) {
            $alert->update($fieldsToUpdate);
        }

        AuditLogger::log('controlRoom.alert.updateMeta', $alert, [
            'alert_id' => $alert->id,
            'fields' => array_keys($fieldsToUpdate),
            'updated_by' => $user->id,
        ]);

        return back();
    }

    /**
     * Store a new alert (API endpoint for external integrations).
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.create'), 403);

        $data = $request->validate([
            'source' => ['required', 'string', 'in:fleet,personal_tracker,manual,external,compliance,other'],
            'alert_type' => ['required', 'string', 'max:100'],
            'severity' => ['required', 'string', 'in:low,medium,high,critical'],
            'asset_id' => ['nullable', 'integer', 'exists:assets,id'],
            'fleet_signal_id' => ['nullable', 'integer', 'exists:fleet_signals,id'],
            'context' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['status'] = 'open';
        $data['triggered_at'] = now();
        $data['created_by_user_id'] = $user->id;
        $queue = TriageQueue::findForAlert($data['severity'], $data['source'], $data['alert_type']);
        $data['queue_id'] = $queue?->id;

        $alert = ControlRoomAlert::create($data);

        if ($queue) {
            \App\Models\ControlRoom\AlertQueue::create([
                'alert_id' => $alert->id,
                'queue_id' => $queue->id,
                'entered_at' => now(),
            ]);
        }

        if (!$alert->sla) {
            $slaDefinition = SlaDefinition::findForAlert($alert->alert_type, $alert->severity, $alert->source);
            if ($slaDefinition) {
                AlertSla::createFromDefinition($alert, $slaDefinition);
            }
        }

        AuditLogger::log('controlRoom.alert.create', $alert, [
            'alert_id' => $alert->id,
            'source' => $data['source'],
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Alert created.',
                'alert' => [
                    'id' => $alert->id,
                    'status' => $alert->status,
                ],
            ], 201);
        }

        return redirect()->route('control-room.alerts.show', $alert)
            ->with('success', 'Alert created.');
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
        return ['shifts.manageAny', 'timesheets.manageAny', 'reports.viewAny'];
    }

    protected function assertCanAccessAlert(User $user, ControlRoomAlert $alert): void
    {
        $this->siteAccess()->assertCanAccessAlert(
            $user,
            $alert,
            $this->alertBypassPermissions(),
            'You are not authorized to access alerts for this site.',
        );
    }
}
