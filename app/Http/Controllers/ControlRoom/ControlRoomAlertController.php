<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ControlRoom\AlertQueue;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ControlRoom\AlertWorkspaceService;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Services\ControlRoom\ControlRoomAlertProvenanceService;
use App\Services\ControlRoom\SensorIncidentBridgeService;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

class ControlRoomAlertController extends Controller
{
    /**
     * SQL fragment for ordering severity: critical first, low last.
     * Used by both priority sort and explicit severity sort.
     */
    private const SEVERITY_ORDER_SQL = "FIELD(severity, 'critical', 'high', 'medium', 'low')";

    private const TRANSACTION_ATTEMPTS = 3;

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
        $status = $request->input('status');
        if (filled($status) && $status !== 'all') {
            $query->where('status', $status);
        } else {
            $query->actionable();
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
            'critical' => (clone $statsBase)->notSnoozed()->where('severity', 'critical')->actionable()->count(),
            'in_triage' => (clone $statsBase)->where('status', 'triaging')->count(),
            'assigned_to_me' => (clone $statsBase)->notSnoozed()->where('assigned_to_user_id', $user->id)->actionable()->count(),
            'unassigned' => (clone $statsBase)->notSnoozed()->whereNull('assigned_to_user_id')->actionable()->count(),
            'snoozed' => (clone $statsBase)->snoozed()->count(),
            'sla_breached' => (clone $statsBase)->actionable()
                ->whereHas('sla', fn ($q) => $q->breached())
                ->count(),
        ];

        $staff = $this->assignableStaff($user);

        $latestAlertQuery = ControlRoomAlert::query()
            ->actionable();
        $this->siteAccess()->applyAlertScope($latestAlertQuery, $user, $this->alertBypassPermissions());

        // Triage queue summary — compact overview for operators
        $queues = TriageQueue::active()
            ->withCount(['alerts as active_alert_count' => function ($query) use ($user) {
                $query->actionable();
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
        return match ($alert->sla?->getStatus()) {
            'breached' => 'red',
            'at_risk' => 'yellow',
            'on_track', 'resolved' => 'green',
            default => null,
        };
    }

    /**
     * Bulk acknowledge multiple alerts.
     */
    public function bulkAcknowledge(Request $request, ControlRoomAlertLifecycleService $lifecycle)
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
            try {
                $lifecycle->acknowledge($alert, $user);
            } catch (InvalidArgumentException) {
                $skipped++;

                continue;
            }

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
            'alert_ids.*' => ['integer', 'distinct'],
            'assigned_to_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        [$count, $skipped] = DB::transaction(function () use ($data, $user): array {
            $freshActor = $this->freshAssignmentActor($user);
            $alerts = ControlRoomAlert::query()
                ->whereIn('id', $data['alert_ids'])
                ->tap(fn ($query) => $this->siteAccess()->applyAlertScope(
                    $query,
                    $freshActor,
                    $this->alertBypassPermissions(),
                ))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            abort_if(
                $alerts->count() !== count($data['alert_ids']),
                403,
                'You are not authorized to assign one or more selected alerts.',
            );

            $assignee = $this->lockAssignableStaff(
                $freshActor,
                (int) $data['assigned_to_user_id'],
            );

            $count = 0;
            $skipped = 0;
            $at = now();
            foreach ($alerts as $lockedAlert) {
                if (! $lockedAlert->isActionable()) {
                    $skipped++;

                    continue;
                }

                $lockedAlert->forceFill([
                    'assigned_to_user_id' => $assignee->id,
                    'assigned_at' => $at,
                    'assigned_by_user_id' => $freshActor->id,
                    'context' => $this->appendAssignmentHistory(
                        $lockedAlert,
                        $assignee,
                        $freshActor,
                        $lockedAlert->assigned_to_user_id ? 'reassigned' : 'assigned',
                        $at,
                    ),
                ])->save();

                AuditLogger::logOrFail('controlRoom.alert.assign', $lockedAlert, [
                    'alert_id' => $lockedAlert->id,
                    'assigned_to' => $assignee->id,
                    'assigned_by' => $freshActor->id,
                    'actor_id' => $freshActor->id,
                    'bulk' => true,
                ]);

                $count++;
            }

            return [$count, $skipped];
        }, self::TRANSACTION_ATTEMPTS);

        $message = "{$count} alert(s) assigned.";
        if ($skipped > 0) {
            $message .= " {$skipped} skipped (terminal).";
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

        DB::transaction(function () use ($alert, $user): void {
            $lockedAlert = ControlRoomAlert::query()
                ->whereKey($alert->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCanAccessAlert($user, $lockedAlert);
            if (! $lockedAlert->isActionable()) {
                throw ValidationException::withMessages([
                    'alert' => "Cannot assign an alert in '{$lockedAlert->status}' status.",
                ]);
            }

            $at = now();
            $lockedAlert->forceFill([
                'assigned_to_user_id' => $user->id,
                'assigned_at' => $at,
                'assigned_by_user_id' => $user->id,
                'context' => $this->appendAssignmentHistory(
                    $lockedAlert,
                    $user,
                    $user,
                    $lockedAlert->assigned_to_user_id ? 'reassigned' : 'assigned',
                    $at,
                ),
            ])->save();

            AuditLogger::log('controlRoom.alert.assign', $lockedAlert, [
                'alert_id' => $lockedAlert->id,
                'assigned_to' => $user->id,
                'assigned_by' => $user->id,
                'self_assign' => true,
            ]);
        }, self::TRANSACTION_ATTEMPTS);

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
    public function acknowledge(
        Request $request,
        ControlRoomAlert $alert,
        ControlRoomAlertLifecycleService $lifecycle,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $lifecycle->acknowledge($alert, $user, $data['notes'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['alert' => $e->getMessage()]);
        }

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
        } catch (InvalidArgumentException $e) {
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
        } catch (InvalidArgumentException $e) {
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

        try {
            DB::transaction(function () use ($alert, $user, $until, $data): void {
                $lockedAlert = ControlRoomAlert::query()
                    ->whereKey($alert->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertCanAccessAlert($user, $lockedAlert);

                if ($lockedAlert->isTerminal()) {
                    throw new InvalidArgumentException('Resolved, closed, or dismissed alerts can\'t be snoozed.');
                }
                if (strtolower((string) $lockedAlert->severity) === 'critical') {
                    throw new InvalidArgumentException('Critical alerts can\'t be snoozed — acknowledge or triage them.');
                }

                $lockedAlert->forceFill([
                    'snoozed_until' => $until,
                    'snoozed_by_user_id' => $user->id,
                ])->save();

                AuditLogger::log('controlRoom.alert.snooze', $lockedAlert, [
                    'alert_id' => $lockedAlert->id,
                    'snoozed_by' => $user->id,
                    'snoozed_until' => $until->toIso8601String(),
                    'window' => $data['window'],
                    'note' => $data['note'] ?? null,
                ]);
            }, self::TRANSACTION_ATTEMPTS);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['alert' => $exception->getMessage()]);
        }

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
    public function triage(
        Request $request,
        ControlRoomAlert $alert,
        ControlRoomAlertLifecycleService $lifecycle,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $lifecycle->startTriage($alert, $user, $data['notes'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['alert' => $e->getMessage()]);
        }

        return back()->with('success', 'Alert is now being triaged.');
    }

    /**
     * Resolve an alert.
     */
    public function resolve(
        Request $request,
        ControlRoomAlert $alert,
        ControlRoomAlertLifecycleService $lifecycle,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $data = $request->validate([
            'resolution_notes' => ['required', 'string', 'max:2000'],
            'resolution_code' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $lifecycle->resolve(
                $alert,
                $user,
                $data['resolution_notes'],
                $data['resolution_code'] ?? $alert->resolution_code ?? 'resolved',
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['alert' => $e->getMessage()]);
        }

        return back()->with('success', 'Alert resolved.');
    }

    /**
     * Close an alert (final state).
     */
    public function close(
        Request $request,
        ControlRoomAlert $alert,
        ControlRoomAlertLifecycleService $lifecycle,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $data = $request->validate([
            'closure_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $lifecycle->close($alert, $user, $data['closure_notes'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['alert' => $e->getMessage()]);
        }

        return back()->with('success', 'Alert closed.');
    }

    /**
     * Explicitly restart a terminal operational response after its linked
     * incident has been reopened. Incident review itself never changes this
     * alert state implicitly.
     */
    public function reopenForIncident(
        Request $request,
        ControlRoomAlert $alert,
        ControlRoomAlertLifecycleService $lifecycle,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $incident = $alert->clientIncident()->first();
        if (! $incident) {
            return back()->withErrors(['alert' => 'This alert has no linked incident to reopen.']);
        }

        try {
            $lifecycle->reopenForIncident($alert, $incident, $user, $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['alert' => $e->getMessage()]);
        }

        return back()->with('success', 'Operational response reopened for triage.');
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

        $assignmentNote = trim((string) ($data['notes'] ?? ''));
        $assignmentNote = $assignmentNote === '' ? null : $assignmentNote;

        DB::transaction(function () use ($alert, $assignmentNote, $data, $user): void {
            $freshActor = $this->freshAssignmentActor($user);
            $lockedAlert = ControlRoomAlert::query()
                ->whereKey($alert->id)
                ->tap(fn ($query) => $this->siteAccess()->applyAlertScope(
                    $query,
                    $freshActor,
                    $this->alertBypassPermissions(),
                ))
                ->lockForUpdate()
                ->first();
            abort_unless(
                $lockedAlert,
                403,
                'You are not authorized to access alerts for this site.',
            );
            if (! $lockedAlert->isActionable()) {
                throw ValidationException::withMessages([
                    'alert' => "Cannot assign an alert in '{$lockedAlert->status}' status.",
                ]);
            }

            $assignee = $this->lockAssignableStaff(
                $freshActor,
                (int) $data['assigned_to_user_id'],
            );

            $at = now();
            $context = $lockedAlert->context ?? [];
            $assignmentHistory = $context['assignment_history'] ?? [];
            $assignmentHistory[] = [
                'action' => $lockedAlert->assigned_to_user_id ? 'reassigned' : 'assigned',
                'from_user_id' => $lockedAlert->assigned_to_user_id,
                'from_user_name' => $lockedAlert->assignedTo()->value('name'),
                'to_user_id' => $assignee->id,
                'to_user_name' => $assignee->name,
                'by_user_id' => $freshActor->id,
                'by_user_name' => $freshActor->name,
                'reason' => $data['reason'] ?? null,
                'at' => $at->toIso8601String(),
            ];
            $context['assignment_history'] = $assignmentHistory;

            if ($assignmentNote !== null) {
                $activity = $context['activity_log'] ?? [];
                $activity[] = [
                    'type' => 'assignment_note',
                    'transition' => 'assignment',
                    'content' => $assignmentNote,
                    'user_id' => $freshActor->id,
                    'user_name' => $freshActor->name,
                    'created_at' => $at->toIso8601String(),
                ];
                $context['activity_log'] = $activity;
            }

            $lockedAlert->forceFill([
                'assigned_to_user_id' => $assignee->id,
                'assigned_at' => $at,
                'assigned_by_user_id' => $freshActor->id,
                'context' => $context,
            ])->save();

            AuditLogger::logOrFail('controlRoom.alert.assign', $lockedAlert, [
                'alert_id' => $lockedAlert->id,
                'assigned_to' => $assignee->id,
                'assigned_by' => $freshActor->id,
                'actor_id' => $freshActor->id,
                'assignment_note' => $assignmentNote,
            ]);
        }, self::TRANSACTION_ATTEMPTS);

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

        DB::transaction(function () use ($alert, $user): void {
            $lockedAlert = ControlRoomAlert::query()
                ->whereKey($alert->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCanAccessAlert($user, $lockedAlert);
            if (! $lockedAlert->isActionable()) {
                throw ValidationException::withMessages([
                    'alert' => "Cannot unassign an alert in '{$lockedAlert->status}' status.",
                ]);
            }

            $previousAssignee = $lockedAlert->assigned_to_user_id;
            $at = now();
            $context = $this->appendAssignmentHistory(
                $lockedAlert,
                null,
                $user,
                'unassigned',
                $at,
            );
            $lockedAlert->forceFill([
                'assigned_to_user_id' => null,
                'assigned_at' => null,
                'assigned_by_user_id' => null,
                'context' => $context,
            ])->save();

            AuditLogger::log('controlRoom.alert.unassign', $lockedAlert, [
                'alert_id' => $lockedAlert->id,
                'previous_assignee' => $previousAssignee,
                'unassigned_by' => $user->id,
            ]);
        }, self::TRANSACTION_ATTEMPTS);

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

        $data = $request->validate([
            'escalation_reason' => ['required', 'string', 'max:1000'],
            'escalation_level' => ['nullable', 'integer', 'min:1'],
        ]);

        $effectiveLevel = DB::transaction(function () use ($alert, $data, $user): int {
            $lockedAlert = ControlRoomAlert::query()
                ->whereKey($alert->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCanAccessAlert($user, $lockedAlert);
            if (! $lockedAlert->isActionable()) {
                throw ValidationException::withMessages([
                    'alert' => "Cannot escalate an alert in '{$lockedAlert->status}' status.",
                ]);
            }

            $requestedLevel = isset($data['escalation_level'])
                ? (int) $data['escalation_level']
                : ((int) ($lockedAlert->escalation_level ?? 0)) + 1;
            $currentLevel = min(max((int) ($lockedAlert->escalation_level ?? 0), 0), 5);
            $effectiveLevel = max($currentLevel, min($requestedLevel, 5));
            $at = now();
            $context = $lockedAlert->context ?? [];
            $history = $context['escalation_history'] ?? [];
            $history[] = [
                'level' => $effectiveLevel,
                'requested_level' => $requestedLevel,
                'reason' => $data['escalation_reason'],
                'escalated_by' => $user->id,
                'escalated_at' => $at->toIso8601String(),
            ];
            $context['escalation_history'] = $history;

            $lockedAlert->forceFill([
                'escalation_level' => $effectiveLevel,
                'escalated_at' => $at,
                'escalated_by_user_id' => $user->id,
                'context' => $context,
            ])->save();

            AuditLogger::log('controlRoom.alert.escalate', $lockedAlert, [
                'alert_id' => $lockedAlert->id,
                'escalation_level' => $effectiveLevel,
                'requested_level' => $requestedLevel,
                'escalated_by' => $user->id,
            ]);

            return $effectiveLevel;
        }, self::TRANSACTION_ATTEMPTS);

        return back()->with('success', 'Alert escalated to level '.$effectiveLevel.'.');
    }

    /**
     * Add a note to an alert.
     */
    public function addNote(
        Request $request,
        ControlRoomAlert $alert,
        ControlRoomAlertLifecycleService $lifecycle,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $lifecycle->appendOperatorNote($alert, $user, $data['note'], 'note');

        return back()->with('success', 'Note added.');
    }

    /**
     * Update non-lifecycle alert working fields.
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
        ]);

        // Update only the fields that were actually provided in the request
        $fieldsToUpdate = [];
        foreach (['priority', 'category', 'due_at'] as $field) {
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
        } elseif (! $siteAccess->isUnrestrictedPlatformUser($user)) {
            abort(403, 'A site is required when creating an alert.');
        }

        $provenance = $this->alertProvenance();
        $siteId = ! empty($data['site_id']) ? (int) $data['site_id'] : null;
        $clientId = $client?->id ? (int) $client->id : null;
        $asset = null;

        if (! empty($data['asset_id'])) {
            $asset = Asset::query()
                ->with('client:id,site_id,organization_id')
                ->find((int) $data['asset_id']);

            if (! $asset || ! $provenance->assetMatchesTuple($asset, $siteId, $clientId)) {
                throw ValidationException::withMessages([
                    'asset_id' => 'The selected asset does not belong to the alert client and site.',
                ]);
            }
        }

        if (! empty($data['fleet_signal_id'])) {
            $signal = FleetSignal::query()
                ->with('asset.client:id,site_id,organization_id')
                ->find((int) $data['fleet_signal_id']);

            if (! $signal || ! $provenance->fleetSignalMatchesTuple(
                $signal,
                $siteId,
                $clientId,
                $asset?->id ? (int) $asset->id : null,
            )) {
                throw ValidationException::withMessages([
                    'fleet_signal_id' => 'The selected fleet signal does not belong to the alert client, site, and asset.',
                ]);
            }

            $data['asset_id'] ??= (int) $signal->asset_id;
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

    /**
     * @return array<string, mixed>
     */
    private function appendAssignmentHistory(
        ControlRoomAlert $alert,
        ?User $assignee,
        User $actor,
        string $action,
        \DateTimeInterface $at,
        ?string $reason = null,
    ): array {
        $context = $alert->context ?? [];
        $history = $context['assignment_history'] ?? [];
        $history[] = [
            'action' => $action,
            'from_user_id' => $alert->assigned_to_user_id,
            'from_user_name' => $alert->assigned_to_user_id
                ? User::query()->whereKey($alert->assigned_to_user_id)->value('name')
                : null,
            'to_user_id' => $assignee?->id,
            'to_user_name' => $assignee?->name,
            'by_user_id' => $actor->id,
            'by_user_name' => $actor->name,
            'reason' => $reason,
            'at' => $at->format(DATE_ATOM),
        ];
        $context['assignment_history'] = $history;

        return $context;
    }

    protected function siteAccess(): UserSiteAccessService
    {
        return app(UserSiteAccessService::class);
    }

    protected function alertProvenance(): ControlRoomAlertProvenanceService
    {
        return app(ControlRoomAlertProvenanceService::class);
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

    protected function freshAssignmentActor(User $actor): User
    {
        $freshActor = User::query()->whereKey($actor->id)->first();
        abort_unless(
            $freshActor && $freshActor->canDo('controlRoom.alerts.assign'),
            403,
            'You no longer have permission to assign Control Room alerts.',
        );

        return $freshActor;
    }

    protected function lockAssignableStaff(User $actor, int $assigneeUserId): User
    {
        $query = User::query()
            ->staff()
            ->whereKey($assigneeUserId);
        $this->siteAccess()->applyControlRoomAssigneeScope(
            $query,
            $actor,
            $this->alertBypassPermissions(),
        );
        $assignee = $query->lockForUpdate()->first();
        abort_unless(
            $assignee,
            403,
            'You are not authorized to assign alerts to that staff member.',
        );

        return $assignee;
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
