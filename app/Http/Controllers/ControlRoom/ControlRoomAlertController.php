<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ControlRoom\AlertQueue;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ControlRoom\AlertWorkspaceService;
use App\Services\ControlRoom\SensorIncidentBridgeService;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ControlRoomAlertController extends Controller
{
    /**
     * SQL fragment for ordering severity: critical first, low last.
     * Used by both priority sort and explicit severity sort.
     */
    private const SEVERITY_ORDER_SQL = "FIELD(severity, 'critical', 'high', 'medium', 'low')";

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
            'playbookRun:id,alert_id,playbook_id,status,current_step,completed_steps,total_steps',
            'playbookRun.playbook:id,name,category',
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

        // Snooze — the "Snoozed" tab shows currently-snoozed alerts; every other
        // view hides them so the desk stays decluttered. An elapsed snooze
        // returns the alert to the worklist automatically (scopes key off now()).
        if ($request->input('snoozed') === '1') {
            $query->snoozed();
        } else {
            $query->notSnoozed();
        }

        // Sorting — default is operational priority (severity → escalation → oldest first)
        $sortField = $request->input('sort', 'priority');
        $sortDir = $request->input('dir', 'desc');
        $allowedSorts = ['triggered_at', 'severity', 'status', 'alert_type', 'priority'];

        if ($sortField === 'priority' || ! in_array($sortField, $allowedSorts, true)) {
            $this->applyOperationalPrioritySort($query);
        } elseif ($sortField === 'severity') {
            $query->orderByRaw(self::SEVERITY_ORDER_SQL.' '.($sortDir === 'desc' ? 'DESC' : 'ASC'));
        } else {
            $query->orderBy($sortField, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $paginated = $query->paginate(30)->withQueryString();

        $alerts = $paginated->through(fn (ControlRoomAlert $alert) => [
            'id' => $alert->id,
            'source' => $alert->source,
            'alert_type' => $alert->alert_type,
            'severity' => $alert->severity,
            'status' => $alert->status,
            'escalation_level' => $alert->escalation_level,
            'triggered_at' => optional($alert->triggered_at)->toISOString(),
            'age_minutes' => $alert->triggered_at ? (int) $alert->triggered_at->diffInMinutes(now()) : null,
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
                ? trim($alert->client->first_name.' '.$alert->client->last_name)
                : null,
            'sla_status' => $this->deriveSlaStatus($alert),
            'snoozed_until' => optional($alert->snoozed_until)->toISOString(),
            'notes' => $alert->notes ? Str::limit($alert->notes, 120) : null,
            // Operator context — what this alert is about (from normalized_data)
            'summary' => $this->extractAlertSummary($alert),
            // Playbook progress — shows operator what action state this is in
            'playbook' => $alert->playbookRun ? [
                'name' => $alert->playbookRun->playbook?->name,
                'status' => $alert->playbookRun->status,
                'progress' => $alert->playbookRun->total_steps > 0
                    ? (int) round(($alert->playbookRun->completed_steps / $alert->playbookRun->total_steps) * 100)
                    : 0,
                'current_step' => $alert->playbookRun->current_step,
                'total_steps' => $alert->playbookRun->total_steps,
            ] : null,
        ]);

        // Stats (unfiltered counts)
        $statsBase = ControlRoomAlert::query();
        $this->siteAccess()->applyAlertScope($statsBase, $user, $this->alertBypassPermissions());

        // The five tab counts mirror the worklist, which hides currently-snoozed
        // alerts — so they exclude snoozed too. Snoozed gets its own count/tab.
        $stats = [
            'total' => (clone $statsBase)->notSnoozed()->count(),
            'open' => (clone $statsBase)->notSnoozed()->where('status', 'open')->count(),
            'critical' => (clone $statsBase)->notSnoozed()->where('severity', 'critical')->whereNotIn('status', ['resolved', 'closed'])->count(),
            'in_triage' => (clone $statsBase)->where('status', 'triaging')->count(),
            'assigned_to_me' => (clone $statsBase)->notSnoozed()->where('assigned_to_user_id', $user->id)->whereNotIn('status', ['resolved', 'closed'])->count(),
            'unassigned' => (clone $statsBase)->notSnoozed()->whereNull('assigned_to_user_id')->whereNotIn('status', ['resolved', 'closed'])->count(),
            'snoozed' => (clone $statsBase)->snoozed()->count(),
            'sla_breached' => (clone $statsBase)->whereNotIn('status', ['resolved', 'closed'])
                ->whereHas('sla', fn ($q) => $q->where(fn ($sq) => $sq->where('acknowledge_breached', true)
                    ->orWhere('response_breached', true)
                    ->orWhere('resolution_breached', true)
                ))
                ->count(),
        ];

        $staff = $this->assignableStaff($user);

        $latestAlertQuery = ControlRoomAlert::query()
            ->whereNotIn('status', ['resolved', 'closed']);
        $this->siteAccess()->applyAlertScope($latestAlertQuery, $user, $this->alertBypassPermissions());

        // Triage queue summary — compact overview for operators
        $queues = TriageQueue::active()
            ->withCount(['alerts as active_alert_count' => function ($query) use ($user) {
                $query->whereNotIn('status', ['resolved', 'closed']);
                $this->siteAccess()->applyAlertScope($query, $user, $this->alertBypassPermissions());
            }])
            ->orderBy('tier')
            ->get(['id', 'name', 'tier', 'code'])
            ->map(fn ($q) => [
                'id' => $q->id,
                'name' => $q->name,
                'tier' => $q->tier,
                'active_alerts' => $q->active_alert_count,
            ]);

        $clientsQuery = Client::query()->orderBy('first_name');
        $this->siteAccess()->applyClientScope($clientsQuery, $user, $this->alertBypassPermissions());
        $clients = $clientsQuery->get(['id', 'first_name', 'last_name'])
            ->map(fn (Client $client) => [
                'id' => $client->id,
                'name' => trim($client->first_name.' '.$client->last_name),
            ]);

        $sitesQuery = Site::query()->orderBy('name');
        $this->siteAccess()->applySiteScope($sitesQuery, $user, $this->alertBypassPermissions());
        $sites = $sitesQuery->get(['id', 'name']);

        return Inertia::render('control-room/alerts/index', [
            'alerts' => $alerts,
            'filters' => $request->only(['status', 'severity', 'source', 'assigned_to', 'search', 'date_from', 'date_to', 'sort', 'dir', 'snoozed']),
            'stats' => $stats,
            'queues' => $queues,
            'staff' => $staff,
            // For the New-alert wizard (manual alert creation).
            'clients' => $clients,
            'sites' => $sites,
            'can' => [
                'manage' => $user->canDo('controlRoom.alerts.manage'),
                'assign' => $user->canDo('controlRoom.alerts.assign'),
                'create' => $user->canDo('controlRoom.alerts.create'),
            ],
            // Polling metadata — frontend can use these to detect stale data.
            // latest_alert_at: timestamp of the most recently triggered unresolved alert.
            // If this changes between polls, the list has new data.
            'server_time' => now()->toISOString(),
            'latest_alert_at' => $latestAlertQuery->max('updated_at'),
            // Workspace-over-list: when ?alert= is present the workspace dialog
            // opens over this page (Inertia partial-reloads only this prop).
            'detail' => fn () => $request->filled('alert')
                ? app(AlertWorkspaceService::class)->build($user, (int) $request->input('alert'))
                : null,
        ]);
    }

    /**
     * Extract a human-readable summary from the alert context for operator scanning.
     *
     * Defensive fallback chain ensures operators almost never see a blank summary:
     * 1. normalized_data.title (set by all signal services and bridge methods)
     * 2. normalized_data.description (sometimes richer than title)
     * 3. alert notes (manually added context)
     * 4. alert_type as display name (always available, humanised)
     *
     * This degrades gracefully for any future emitter that omits context fields.
     */
    private function extractAlertSummary(ControlRoomAlert $alert): string
    {
        $ctx = $alert->context['normalized_data'] ?? $alert->context ?? [];

        // 1. Signal/bridge normalised title
        $title = $ctx['title'] ?? null;
        if ($title && is_string($title) && trim($title) !== '') {
            return Str::limit(trim($title), 100);
        }

        // 2. Description (may contain more detail)
        $desc = $ctx['description'] ?? null;
        if ($desc && is_string($desc) && trim($desc) !== '') {
            return Str::limit(trim($desc), 100);
        }

        // 3. Notes on the alert
        if ($alert->notes && trim($alert->notes) !== '') {
            return Str::limit(trim($alert->notes), 100);
        }

        // 4. Humanised alert_type as last resort (always available)
        return str_replace(['.', '_'], ' ', ucfirst($alert->alert_type));
    }

    /**
     * Apply operational priority sort: severity → escalation → oldest first.
     *
     * This is the default sort for the triage list. Ensures critical alerts
     * are always at the top, heavily escalated alerts surface quickly, and
     * within the same priority band the longest-waiting alert comes first.
     */
    private function applyOperationalPrioritySort($query): void
    {
        $query->orderByRaw(self::SEVERITY_ORDER_SQL.' ASC')   // critical first
            ->orderByDesc('escalation_level')                   // most escalated first
            ->orderBy('triggered_at', 'asc');                   // oldest first
    }

    /**
     * Derive SLA status for a given alert (green/yellow/red/none).
     */
    private function deriveSlaStatus(ControlRoomAlert $alert): ?string
    {
        if (! $alert->sla?->isApplicable()) {
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
            ->tap(fn ($query) => $this->siteAccess()->applyAlertScope($query, $user, $this->alertBypassPermissions()))
            ->get();

        $count = 0;
        $skipped = 0;
        foreach ($alerts as $alert) {
            if (! $alert->canTransitionTo(ControlRoomAlert::STATUS_ACK)) {
                $skipped++;

                continue;
            }

            $alert->update([
                'status' => ControlRoomAlert::STATUS_ACK,
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

        $message = "{$count} alert(s) acknowledged.";
        if ($skipped > 0) {
            $message .= " {$skipped} skipped (already acknowledged or resolved).";
        }

        return back()->with('success', $message);
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

        $this->assertCanAssignAlertToUser($user, (int) $data['assigned_to_user_id']);

        $alerts = ControlRoomAlert::whereIn('id', $data['alert_ids'])
            ->tap(fn ($query) => $this->siteAccess()->applyAlertScope($query, $user, $this->alertBypassPermissions()))
            ->get();

        abort_if($alerts->count() !== count($data['alert_ids']), 403, 'You are not authorized to assign one or more selected alerts.');

        $count = 0;
        $skipped = 0;
        foreach ($alerts as $alert) {
            if (! $alert->isActionable()) {
                $skipped++;

                continue;
            }

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

        $message = "{$count} alert(s) assigned.";
        if ($skipped > 0) {
            $message .= " {$skipped} skipped (resolved or closed).";
        }

        return back()->with('success', $message);
    }

    /**
     * Assign an alert to the current user (shortcut).
     */
    public function assignToMe(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.assign'), 403);
        $this->assertCanAccessAlert($user, $alert);

        if (! $alert->isActionable()) {
            return back()->withErrors(['alert' => "Cannot assign an alert in '{$alert->status}' status."]);
        }

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

        // Deep-link fallback for the alert workspace: same payload as the
        // ?alert= modal-over-list on every Control Room surface.
        $detail = app(AlertWorkspaceService::class)->build($user, $alert->id);
        abort_unless($detail !== null, 404);

        return Inertia::render('control-room/show', $detail);
    }

    /**
     * Acknowledge an alert.
     */
    public function acknowledge(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        if (! $alert->canTransitionTo(ControlRoomAlert::STATUS_ACK)) {
            return back()->withErrors(['alert' => "Cannot acknowledge an alert in '{$alert->status}' status."]);
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
     * Confirm a sensor detection into a ClientIncident (Gap B).
     */
    public function confirm(Request $request, ControlRoomAlert $alert, SensorIncidentBridgeService $bridge)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:120'],
            'severity' => ['nullable', 'string', 'in:low,medium,high'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $incident = $bridge->confirm($alert, $user, array_filter($data, fn ($v) => $v !== null && $v !== ''));
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['alert' => $e->getMessage()]);
        }

        return back()
            ->with('success', "Confirmed — incident INC-{$incident->id} created.")
            ->with('confirmed_incident_id', $incident->id);
    }

    /**
     * Dismiss a sensor detection as a false positive (Gap B). No incident is created.
     */
    public function dismiss(Request $request, ControlRoomAlert $alert, SensorIncidentBridgeService $bridge)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $bridge->dismiss($alert, $data['reason'], $user);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['alert' => $e->getMessage()]);
        }

        return back()->with('success', 'Alert dismissed as a false positive.');
    }

    /**
     * Snooze an alert — set it aside on the operator worklist for a window
     * (15 minutes, an hour, until end of day, or a custom time). The alert stays
     * open and its SLA clocks keep running; it just drops off the default
     * worklist until the window elapses or an operator unsnoozes it, and it is
     * always reachable via the Snoozed tab. Critical and terminal alerts can't
     * be snoozed — mirrors the frontline /my-day rule.
     */
    public function snooze(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        if ($alert->isTerminal()) {
            return back()->withErrors(['alert' => 'Resolved or closed alerts can\'t be snoozed.']);
        }

        if (strtolower((string) $alert->severity) === 'critical') {
            return back()->withErrors(['alert' => 'Critical alerts can\'t be snoozed — acknowledge or triage them.']);
        }

        $data = $request->validate([
            'window' => ['required', 'in:15m,1h,shift,custom'],
            'snoozed_until' => ['required_if:window,custom', 'nullable', 'date', 'after:now'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $tz = config('app.worker_timezone');
        $until = match ($data['window']) {
            '1h' => now()->addHour(),
            'shift' => now()->timezone($tz)->endOfDay()->utc(),
            'custom' => Carbon::parse($data['snoozed_until'], $tz)->utc(),
            default => now()->addMinutes(15),
        };

        $alert->update([
            'snoozed_until' => $until,
            'snoozed_by_user_id' => $user->id,
        ]);

        AuditLogger::log('controlRoom.alert.snooze', $alert, [
            'alert_id' => $alert->id,
            'snoozed_by' => $user->id,
            'snoozed_until' => $until->toIso8601String(),
            'window' => $data['window'],
            'note' => $data['note'] ?? null,
        ]);

        $label = $until->copy()->timezone($tz)->format('D j M, g:i a');

        return back()->with('success', "Snoozed until {$label}.");
    }

    /**
     * Unsnooze an alert — return it to the worklist immediately.
     */
    public function unsnooze(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $alert->update([
            'snoozed_until' => null,
            'snoozed_by_user_id' => null,
        ]);

        AuditLogger::log('controlRoom.alert.unsnooze', $alert, [
            'alert_id' => $alert->id,
            'unsnoozed_by' => $user->id,
        ]);

        return back()->with('success', 'Alert returned to the worklist.');
    }

    /**
     * Start triaging an alert.
     */
    public function triage(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        if (! $alert->canTransitionTo(ControlRoomAlert::STATUS_TRIAGING)) {
            return back()->withErrors(['alert' => "Cannot start triage on an alert in '{$alert->status}' status."]);
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

        if (! $alert->canTransitionTo(ControlRoomAlert::STATUS_RESOLVED)) {
            return back()->withErrors(['alert' => "Cannot resolve an alert in '{$alert->status}' status."]);
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

        if (! $alert->canTransitionTo(ControlRoomAlert::STATUS_CLOSED)) {
            return back()->withErrors(['alert' => "Cannot close an alert in '{$alert->status}' status."]);
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

        if (! $alert->isActionable()) {
            return back()->withErrors(['alert' => "Cannot assign an alert in '{$alert->status}' status."]);
        }

        $data = $request->validate([
            'assigned_to_user_id' => ['required', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->assertCanAssignAlertToUser($user, (int) $data['assigned_to_user_id']);

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

        if (! $alert->isActionable()) {
            return back()->withErrors(['alert' => "Cannot unassign an alert in '{$alert->status}' status."]);
        }

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

        if (! $alert->isActionable()) {
            return back()->withErrors(['alert' => "Cannot escalate an alert in '{$alert->status}' status."]);
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

        return back()->with('success', 'Alert escalated to level '.$newLevel.'.');
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

        // The due time arrives as a naive datetime-local string in the worker's
        // wall clock; interpret it in the worker timezone and store UTC, or a
        // 9:00 am target displays as 9:00 pm.
        if (! empty($fieldsToUpdate['due_at'])) {
            $fieldsToUpdate['due_at'] = \Illuminate\Support\Carbon::parse(
                $fieldsToUpdate['due_at'],
                config('app.worker_timezone'),
            )->utc();
        }

        if (! empty($fieldsToUpdate)) {
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
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'priority' => ['nullable', 'in:critical,high,medium,low'],
            'context' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $siteAccess = $this->siteAccess();
        $bypassPermissions = $this->alertBypassPermissions();
        $client = null;

        if (! empty($data['client_id'])) {
            $clientQuery = Client::query();
            $siteAccess->applyClientScope($clientQuery, $user, $bypassPermissions);
            $client = $clientQuery->find($data['client_id']);
            abort_unless($client, 403, 'You are not authorized to access that client.');

            $clientSiteId = $client->site_id ? (int) $client->site_id : null;
            $requestedSiteId = ! empty($data['site_id']) ? (int) $data['site_id'] : null;

            if ($clientSiteId && $requestedSiteId && $clientSiteId !== $requestedSiteId) {
                throw ValidationException::withMessages([
                    'site_id' => 'The selected site does not match the selected client.',
                ]);
            }

            if (! $requestedSiteId && $clientSiteId) {
                $data['site_id'] = $clientSiteId;
            }
        }

        if (! empty($data['site_id'])) {
            $siteAccess->assertCanAccessSiteId($user, (int) $data['site_id'], $bypassPermissions);
        } elseif (! $siteAccess->canBypass($user, $bypassPermissions)) {
            abort(403, 'A site is required when creating an alert.');
        }

        $data['status'] = 'open';
        $data['triggered_at'] = now();
        $data['created_by_user_id'] = $user->id;
        $queue = TriageQueue::findForAlert($data['severity'], $data['source'], $data['alert_type']);
        $data['queue_id'] = $queue?->id;

        $alert = ControlRoomAlert::create($data);

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

        // The New-alert wizard reads created_alert_id from flash for its
        // success pane; plain form posts still land on the alert workspace.
        if ($request->header('X-Inertia')) {
            return back()
                ->with('success', 'Alert created.')
                ->with('created_alert_id', $alert->id);
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
        return ['reports.viewAny'];
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

    protected function assertCanAssignAlertToUser(User $user, int $assigneeUserId): void
    {
        $this->siteAccess()->assertCanAssignControlRoomAlertToUser(
            $user,
            $assigneeUserId,
            $this->alertBypassPermissions(),
            'You are not authorized to assign alerts to that staff member.',
        );
    }

    protected function assignableStaff(User $user): Collection
    {
        if (! $user->canDo('controlRoom.alerts.assign') && ! $user->canDo('controlRoom.alerts.manage')) {
            return collect();
        }

        $staffQuery = User::staff()->orderBy('name');
        $this->siteAccess()->applyControlRoomAssigneeScope($staffQuery, $user, $this->alertBypassPermissions());

        return $staffQuery->get(['id', 'name', 'email']);
    }
}
