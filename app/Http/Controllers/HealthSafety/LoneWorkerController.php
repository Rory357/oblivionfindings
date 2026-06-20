<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\LoneWorkerAlert;
use App\Models\LoneWorkerSession;
use App\Models\Shift;
use App\Models\ShiftGpsLog;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\LoneWorkerSignalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

/**
 * Lone Worker session management — the coordinator / H&S "watch-tower".
 *
 * ARCHITECTURAL NOTE (PR4):
 * Operational lone worker alerts flow through the canonical pipeline:
 *   LoneWorkerSignalService → SignalProcessingService → ControlRoomAlert
 *
 * ControlRoomAlert (source='lone_worker') is the operational source of truth.
 * LoneWorkerAlert is a LEGACY compatibility model — still written during the
 * transition but it does NOT drive operational triage, SLA, escalation, or
 * playbooks. Operators triage/resolve lone worker alerts via the Control Room;
 * the acknowledge/resolve actions here are convenience actions for the H&S view.
 *
 * Actor model (see docs/lone-workers-redesign/INTEGRATION_AUDIT.md):
 *  - Coordinator / H&S lead  → THIS page (register, wizard, detail, escalate).
 *  - The lone worker         → My Day check-in card (single tap), never here.
 *  - The client              → never touches lone worker safety.
 */
class LoneWorkerController extends Controller
{
    /** Live session statuses (always surfaced in the register regardless of period). */
    private const LIVE_STATUSES = ['active', 'overdue', 'emergency'];

    /**
     * The coordinator watch-tower: live register, alerts, hero KPIs, detail.
     */
    public function index(Request $request): \Inertia\Response
    {
        $tab = $request->input('tab') === 'alerts' ? 'alerts' : 'sessions';
        $filters = [
            'site_id' => $request->input('site_id'),
            'status' => $request->input('status'),
            'user_id' => $request->input('user_id'),
            'period' => in_array($request->input('period'), ['today', 'week', '30d', 'all'], true)
                ? $request->input('period')
                : 'today',
            'q' => trim((string) $request->input('q')) ?: null,
        ];

        $boundary = match ($filters['period']) {
            'week' => now()->subDays(7),
            '30d' => now()->subDays(30),
            'all' => null,
            default => now()->startOfDay(),
        };

        // ── Sessions register (default tab) ──────────────────────────────
        $sessions = $tab === 'sessions'
            ? LoneWorkerSession::with(['user:id,name', 'site:id,name', 'client:id,first_name,last_name'])
                ->when($filters['site_id'], fn ($q) => $q->where('site_id', $filters['site_id']))
                ->when($filters['status'], fn ($q) => $q->where('status', $filters['status']))
                ->when($filters['user_id'], fn ($q) => $q->where('user_id', $filters['user_id']))
                ->when($boundary, fn ($q) => $q->where(function ($w) use ($boundary) {
                    $w->where('started_at', '>=', $boundary)
                        ->orWhereIn('status', self::LIVE_STATUSES);
                }))
                ->when($filters['q'], fn ($q) => $q->where(function ($w) use ($filters) {
                    $term = '%' . $filters['q'] . '%';
                    $w->whereHas('user', fn ($u) => $u->where('name', 'like', $term))
                        ->orWhereHas('client', fn ($c) => $c
                            ->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term))
                        ->orWhere('activity_description', 'like', $term)
                        ->orWhere('location', 'like', $term);
                }))
                // Most-urgent first: emergency → overdue → active → completed, then newest.
                ->orderByRaw("FIELD(status, 'emergency', 'overdue', 'active', 'completed')")
                ->orderByDesc('started_at')
                ->paginate(25)
                ->withQueryString()
                ->through(fn ($s) => $this->mapSession($s))
            : $this->emptyPaginator();

        // ── Alerts register (canonical ControlRoomAlert, source=lone_worker) ──
        $alerts = $tab === 'alerts'
            ? ControlRoomAlert::where('source', 'lone_worker')
                ->with(['client:id,first_name,last_name', 'site:id,name'])
                ->when($filters['site_id'], fn ($q) => $q->where('site_id', $filters['site_id']))
                ->when($filters['status'], fn ($q) => $q->where('status', $this->crStatus($filters['status'])))
                ->when($boundary, fn ($q) => $q->where(function ($w) use ($boundary) {
                    $w->where('triggered_at', '>=', $boundary)
                        ->orWhereNotIn('status', ['resolved', 'closed']);
                }))
                ->when($filters['q'], fn ($q) => $q->where(function ($w) use ($filters) {
                    $term = '%' . $filters['q'] . '%';
                    $w->where('notes', 'like', $term)
                        ->orWhereHas('client', fn ($c) => $c
                            ->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term))
                        ->orWhereHas('site', fn ($s) => $s->where('name', 'like', $term));
                }))
                ->orderByDesc('triggered_at')
                ->paginate(25)
                ->withQueryString()
                ->through(fn ($a) => $this->mapCanonicalAlert($a))
            : $this->emptyPaginator();

        // ── Detail (param-driven partial reload) ─────────────────────────
        $detail = null;
        if ($request->filled('session')) {
            $detail = $this->sessionDetail((int) $request->input('session'));
        } elseif ($request->filled('alert')) {
            $detail = $this->alertDetail((string) $request->input('alert'), $request);
        }

        $shiftData = $this->monitorableShifts();

        return Inertia::render('health-safety/lone-workers/index', [
            'tab' => $tab,
            'sessions' => $sessions,
            'alerts' => $alerts,
            'detail' => $detail,
            'tabCounts' => $this->tabCounts(),
            'hero' => $this->heroBlock($shiftData['unmonitored_lone']),
            'filters' => $filters,
            'options' => [
                'sites' => Site::select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
                'staff' => User::select('id', 'name')->orderBy('name')->get(),
                'clients' => Client::select('id', 'first_name', 'last_name')->orderBy('last_name')->get()
                    ->map(fn ($c) => ['id' => $c->id, 'name' => trim($c->first_name . ' ' . $c->last_name)]),
                'shifts' => $shiftData['shifts'],
            ],
            'can' => [
                'manage' => $request->user()?->canDo('hazards.manage') ?? false,
                'view' => $request->user()?->canDo('hazards.view') ?? false,
                'view_control_room' => $request->user()?->canDo('controlRoom.viewAny') ?? false,
            ],
        ]);
    }

    /**
     * Start a new lone worker session (coordinator, on a worker's behalf).
     */
    public function startSession(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'site_id' => ['nullable', 'exists:sites,id'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'shift_id' => ['nullable', 'exists:shifts,id'],
            'expected_end_at' => ['required', 'date', 'after:now'],
            'activity_description' => ['nullable', 'string', 'max:2000'],
            'check_in_interval_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'location' => ['nullable', 'string', 'max:500'],
            'location_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'location_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        // Reuse the roster's last GPS ping rather than re-keying coordinates.
        if (! empty($validated['shift_id']) && empty($validated['location_lat']) && empty($validated['location_lng'])) {
            $ping = ShiftGpsLog::where('shift_id', $validated['shift_id'])->latest('captured_at')->first();
            if ($ping) {
                $validated['location_lat'] = $ping->latitude;
                $validated['location_lng'] = $ping->longitude;
            }
        }

        LoneWorkerSession::create(array_merge($validated, [
            'started_at' => now(),
            'last_check_in_at' => now(),
            'status' => 'active',
            'check_in_interval_minutes' => $validated['check_in_interval_minutes'] ?? 60,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        if ($request->boolean('stay')) {
            return back()->with('success', 'Lone worker session started successfully.');
        }

        return redirect()->route('health-safety.lone-workers.index')
            ->with('success', 'Lone worker session started successfully.');
    }

    /**
     * Extend / edit an active or overdue session (new endpoint for the redesign).
     * Extending an overdue session clears it back to active.
     */
    public function updateSession(Request $request, LoneWorkerSession $session): RedirectResponse
    {
        if (! ($session->isActive() || $session->isOverdue())) {
            return back()->with('error', 'Only active or overdue sessions can be edited.');
        }

        $validated = $request->validate([
            'expected_end_at' => ['required', 'date'],
            'check_in_interval_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'activity_description' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:500'],
            'location_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'location_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $session->update(array_merge($validated, [
            // Editing an active/overdue session resumes normal monitoring.
            'status' => 'active',
            'updated_by' => $request->user()->id,
        ]));

        return back()->with('success', 'Session updated.');
    }

    /**
     * Record a check-in for a session.
     *
     * Authorization (the route is auth-only — this is the real gate):
     *  - the session's OWN worker may self-check-in (the My Day one-tap card), or
     *  - a coordinator / H&S lead with hazards.manage may check in on a worker's
     *    behalf from the watch-tower detail modal.
     * Frontline support workers hold no hazards.* permission, so the own-worker
     * branch is what makes the My Day card work.
     */
    public function checkIn(Request $request, LoneWorkerSession $session): RedirectResponse
    {
        abort_unless(
            $request->user()->id === $session->user_id || $request->user()->canDo('hazards.manage'),
            403,
        );

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:ok,concern,emergency'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'location_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'location_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $session->checkIns()->create(array_merge($validated, [
            'checked_in_at' => now(),
            'status' => $validated['status'] ?? 'ok',
        ]));

        $session->update([
            'last_check_in_at' => now(),
            'status' => 'active',
            'updated_by' => $request->user()->id,
        ]);

        // If check-in status is emergency, trigger emergency flow
        if (($validated['status'] ?? 'ok') === 'emergency') {
            $session->update([
                'status' => 'emergency',
                'emergency_triggered_at' => now(),
                'emergency_notes' => $validated['notes'] ?? null,
            ]);

            // Legacy alert (compatibility — will be removed in future cleanup)
            $session->alerts()->create([
                'alert_type' => 'emergency',
                'triggered_at' => now(),
                'status' => 'active',
            ]);

            // Canonical signal → Control Room (operational source of truth)
            app(LoneWorkerSignalService::class)->emitEmergency($session, $validated['notes'] ?? null);
        }

        return redirect()->back()->with('success', 'Check-in recorded successfully.');
    }

    /**
     * End a lone worker session.
     */
    public function endSession(Request $request, LoneWorkerSession $session): RedirectResponse
    {
        $session->update([
            'ended_at' => now(),
            'status' => 'completed',
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Lone worker session ended successfully.');
    }

    /**
     * Trigger emergency for an active session.
     */
    public function triggerEmergency(Request $request, LoneWorkerSession $session): RedirectResponse
    {
        $validated = $request->validate([
            'emergency_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $session->update([
            'status' => 'emergency',
            'emergency_triggered_at' => now(),
            'emergency_notes' => $validated['emergency_notes'] ?? null,
            'updated_by' => $request->user()->id,
        ]);

        // Legacy alert (compatibility — will be removed in future cleanup)
        $session->alerts()->create([
            'alert_type' => 'emergency',
            'triggered_at' => now(),
            'status' => 'active',
        ]);

        // Canonical signal → Control Room (operational source of truth)
        app(LoneWorkerSignalService::class)->emitEmergency($session, $validated['emergency_notes'] ?? null);

        return redirect()->back()->with('success', 'Emergency alert sent to Control Room.');
    }

    /**
     * Acknowledge a legacy alert (convenience action only — triage in Control Room).
     */
    public function acknowledgeAlert(Request $request, LoneWorkerAlert $alert): RedirectResponse
    {
        $alert->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $request->user()->id,
            'status' => 'acknowledged',
        ]);

        return redirect()->back()
            ->with('success', 'Alert acknowledged. For operational triage and escalation, use the Control Room.');
    }

    /**
     * Resolve a legacy alert with notes (convenience action only — resolve in Control Room).
     */
    public function resolveAlert(Request $request, LoneWorkerAlert $alert): RedirectResponse
    {
        $validated = $request->validate([
            'resolution_notes' => ['required', 'string', 'max:2000'],
        ]);

        $alert->update([
            'resolved_at' => now(),
            'resolution_notes' => $validated['resolution_notes'],
            'status' => 'resolved',
        ]);

        return redirect()->back()
            ->with('success', 'Alert resolved. Ensure the corresponding Control Room alert is also resolved.');
    }

    /* ───────────────────────────── Payload builders ───────────────────────────── */

    /**
     * Tab badge counts (org-wide totals, never filter-scoped).
     */
    private function tabCounts(): array
    {
        return [
            'sessions' => LoneWorkerSession::whereIn('status', self::LIVE_STATUSES)->count(),
            'alerts' => ControlRoomAlert::where('source', 'lone_worker')
                ->whereNotIn('status', ['resolved', 'closed'])
                ->count(),
        ];
    }

    /**
     * Hero KPI clusters + NZ compliance badge counts/booleans.
     * Counts & booleans only — the page formats the copy (never pre-format here).
     */
    private function heroBlock(int $loneShiftsUnmonitored): array
    {
        // Load active sessions once; derive the check-in freshness counts in PHP via the
        // model's Carbon helper (UTC-correct) rather than SQL NOW(), which can sit in a
        // different timezone than the stored UTC datetimes and skew the comparison.
        $activeRows = LoneWorkerSession::where('status', 'active')
            ->get(['id', 'status', 'last_check_in_at', 'started_at', 'check_in_interval_minutes']);
        $active = $activeRows->count();
        $overdue = LoneWorkerSession::where('status', 'overdue')->count();
        $emergency = LoneWorkerSession::where('status', 'emergency')->count();

        $endingSoon = LoneWorkerSession::where('status', 'active')
            ->whereBetween('expected_end_at', [now(), now()->addHour()])
            ->count();

        // Active sessions already past their check-in window (not yet flipped by the 5-min job).
        $noRecentCheckIn = $activeRows->filter->isCheckInOverdue()->count();
        // Active sessions still within their check-in window (genuinely "checked in").
        $checkedIn = $active - $noRecentCheckIn;

        $alertsToday = ControlRoomAlert::where('source', 'lone_worker')
            ->where('triggered_at', '>=', now()->startOfDay())
            ->count();
        $awaitingAck = ControlRoomAlert::where('source', 'lone_worker')
            ->where('status', 'open')
            ->count();
        $unresolved = ControlRoomAlert::where('source', 'lone_worker')
            ->whereNotIn('status', ['resolved', 'closed'])
            ->count();

        $nzHour = (int) now()->setTimezone('Pacific/Auckland')->format('G');
        $afterHours = $nzHour < 7 || $nzHour >= 19;

        return [
            'clusters' => [
                'live' => [
                    'active' => $active,
                    'overdue' => $overdue,
                    'emergency' => $emergency,
                    'ending_soon' => $endingSoon,
                ],
                'alerts' => [
                    'today' => $alertsToday,
                    'awaiting_ack' => $awaitingAck,
                    'unresolved' => $unresolved,
                    'no_recent_checkin' => $noRecentCheckIn,
                ],
            ],
            'badges' => [
                'checked_in' => $checkedIn,
                'monitored_total' => $active + $overdue + $emergency,
                'overdue' => $overdue,
                'emergency_active' => $emergency > 0,
                'after_hours' => $afterHours,
            ],
            'lone_shifts_unmonitored' => $loneShiftsUnmonitored,
        ];
    }

    /**
     * In-progress rostered shifts available to monitor (for the wizard "from a
     * shift" mode) + how many "lone" ones are not yet monitored (hero KPI).
     *
     * "Lone" is derived (no Shift.is_lone_worker flag yet): on-call shifts, or a
     * worker who is the only person currently on shift at their site (solo cover).
     */
    private function monitorableShifts(): array
    {
        $shifts = Shift::with(['staff:id,name', 'site:id,name', 'client:id,first_name,last_name'])
            ->whereNotNull('actual_starts_at')
            ->whereNull('actual_ends_at')
            ->where('status', '!=', 'cancelled')
            ->where('ends_at', '>=', now()->subHours(2))
            ->whereDoesntHave('loneWorkerSession', fn ($q) => $q->whereIn('status', self::LIVE_STATUSES))
            ->orderBy('ends_at')
            ->limit(100)
            ->get();

        $siteCounts = $shifts->whereNotNull('site_id')->groupBy('site_id')->map->count();

        $gpsByShift = $shifts->isEmpty()
            ? collect()
            : ShiftGpsLog::whereIn('shift_id', $shifts->pluck('id'))
                ->orderByDesc('captured_at')
                ->get()
                ->groupBy('shift_id');

        $list = $shifts->map(function (Shift $shift) use ($siteCounts, $gpsByShift) {
            $isSolo = $shift->site_id && ($siteCounts[$shift->site_id] ?? 0) === 1;
            $isLone = (bool) $shift->is_on_call || $isSolo;
            $ping = $gpsByShift->get($shift->id)?->first();

            return [
                'id' => $shift->id,
                'worker' => $shift->staff ? ['id' => $shift->staff->id, 'name' => $shift->staff->name] : null,
                'site' => $shift->site ? ['id' => $shift->site->id, 'name' => $shift->site->name] : null,
                'client' => $shift->client
                    ? ['id' => $shift->client->id, 'name' => trim($shift->client->first_name . ' ' . $shift->client->last_name)]
                    : null,
                'starts_at' => $shift->starts_at,
                'ends_at' => $shift->ends_at,
                'location' => $shift->location,
                'location_lat' => $ping?->latitude,
                'location_lng' => $ping?->longitude,
                'is_on_call' => (bool) $shift->is_on_call,
                'is_lone' => $isLone,
            ];
        })
            ->sortByDesc('is_lone')
            ->values();

        return [
            'shifts' => $list,
            'unmonitored_lone' => $list->where('is_lone', true)->count(),
        ];
    }

    /**
     * Shared session display shape (list rows + detail).
     */
    private function mapSession(LoneWorkerSession $s): array
    {
        return [
            'id' => $s->id,
            'user' => $s->user ? ['id' => $s->user->id, 'name' => $s->user->name] : null,
            'site' => $s->site ? ['id' => $s->site->id, 'name' => $s->site->name] : null,
            'client' => $s->client
                ? ['id' => $s->client->id, 'name' => trim($s->client->first_name . ' ' . $s->client->last_name)]
                : null,
            'shift_id' => $s->shift_id,
            'started_at' => $s->started_at,
            'expected_end_at' => $s->expected_end_at,
            'ended_at' => $s->ended_at,
            'last_check_in_at' => $s->last_check_in_at,
            'status' => $s->status,
            'activity_description' => $s->activity_description,
            'check_in_interval_minutes' => $s->check_in_interval_minutes,
            'location' => $s->location,
            'location_lat' => $s->location_lat,
            'location_lng' => $s->location_lng,
            'is_check_in_overdue' => $s->isCheckInOverdue(),
        ];
    }

    /**
     * Hydrate a session for the detail modal (plan + check-in timeline + alerts + shift).
     */
    private function sessionDetail(int $id): ?array
    {
        $s = LoneWorkerSession::with([
            'user:id,name', 'site:id,name', 'client:id,first_name,last_name',
            'shift:id,starts_at,ends_at,status,is_on_call', 'checkIns', 'alerts',
        ])->find($id);

        if (! $s) {
            return null;
        }

        $data = $this->mapSession($s);
        $data['_type'] = 'session';
        $data['emergency_triggered_at'] = $s->emergency_triggered_at;
        $data['emergency_notes'] = $s->emergency_notes;

        $data['check_ins'] = $s->checkIns->sortByDesc('checked_in_at')->values()->map(fn ($c) => [
            'id' => $c->id,
            'status' => $c->status,
            'notes' => $c->notes,
            'checked_in_at' => $c->checked_in_at,
            'location_lat' => $c->location_lat,
            'location_lng' => $c->location_lng,
        ]);

        // Alert history = canonical CR alerts for this session + legacy alerts.
        $legacy = $s->alerts->sortByDesc('triggered_at')->values()->map(fn ($a) => [
            'id' => 'legacy_' . $a->id,
            'type' => $a->alert_type,
            'triggered_at' => $a->triggered_at,
            'status' => $a->status,
            'source' => 'legacy',
        ]);

        $canonical = ControlRoomAlert::where('source', 'lone_worker')
            ->where('context->normalized_data->lone_worker_session_id', $s->id)
            ->orderByDesc('triggered_at')
            ->get()
            ->map(fn ($a) => [
                'id' => 'cr_' . $a->id,
                'type' => $a->alert_type,
                'triggered_at' => $a->triggered_at,
                'status' => $this->mapCrStatus($a->status),
                'source' => 'control_room',
            ]);

        $data['alerts'] = $canonical->concat($legacy)->sortByDesc('triggered_at')->values();
        $data['shift'] = $s->shift ? [
            'id' => $s->shift->id,
            'starts_at' => $s->shift->starts_at,
            'ends_at' => $s->shift->ends_at,
            'status' => $s->shift->status,
            'is_on_call' => (bool) $s->shift->is_on_call,
        ] : null;

        return $data;
    }

    /**
     * Hydrate an alert for the detail modal. Handles the cr_/legacy_ id prefixes
     * emitted by mapCanonicalAlert/mapLegacyAlert.
     */
    private function alertDetail(string $rawId, Request $request): ?array
    {
        if (str_starts_with($rawId, 'cr_')) {
            $alert = ControlRoomAlert::with(['client:id,first_name,last_name', 'site:id,name'])
                ->find((int) substr($rawId, 3));
            if (! $alert) {
                return null;
            }
            $data = $this->mapCanonicalAlert($alert);
            $data['_type'] = 'alert';
            $data['cr_id'] = $alert->id;
            $data['can_view_control_room'] = $request->user()?->canDo('controlRoom.viewAny') ?? false;
            $data['incident_id'] = $alert->context['normalized_data']['incident_id']
                ?? ($alert->context['incident_id'] ?? null);

            return $data;
        }

        if (str_starts_with($rawId, 'legacy_')) {
            $alert = LoneWorkerAlert::with([
                'session.user:id,name', 'session.site:id,name', 'session.client:id,first_name,last_name',
            ])->find((int) substr($rawId, 7));
            if (! $alert) {
                return null;
            }
            $data = $this->mapLegacyAlert($alert);
            $data['_type'] = 'alert';
            $data['cr_id'] = null;
            $data['can_view_control_room'] = false;
            $data['incident_id'] = null;

            return $data;
        }

        return null;
    }

    /**
     * Map our UI alert status filter to the canonical ControlRoomAlert status.
     */
    private function crStatus(string $uiStatus): string
    {
        return match ($uiStatus) {
            'active' => 'open',
            'acknowledged' => 'ack',
            'resolved' => 'resolved',
            default => $uiStatus,
        };
    }

    private function mapCrStatus(string $crStatus): string
    {
        return match ($crStatus) {
            'open' => 'active',
            'ack', 'triaging' => 'acknowledged',
            'resolved', 'closed' => 'resolved',
            default => $crStatus,
        };
    }

    /**
     * Empty paginator shape for the inactive tab (keeps the prop contract stable).
     */
    private function emptyPaginator(): array
    {
        return [
            'data' => [],
            'links' => [],
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => 25,
            'total' => 0,
            'from' => null,
            'to' => null,
        ];
    }

    /**
     * Map a legacy LoneWorkerAlert to the shared alert display shape (historical only).
     */
    private function mapLegacyAlert(LoneWorkerAlert $alert): array
    {
        return [
            'id' => 'legacy_' . $alert->id,
            'session' => $alert->session ? [
                'id' => $alert->session->id,
                'user' => $alert->session->user ? [
                    'id' => $alert->session->user->id,
                    'name' => $alert->session->user->name,
                ] : null,
                'site' => $alert->session->site ? [
                    'id' => $alert->session->site->id,
                    'name' => $alert->session->site->name,
                ] : null,
                'client' => $alert->session->client ? [
                    'id' => $alert->session->client->id,
                    'name' => trim($alert->session->client->first_name . ' ' . $alert->session->client->last_name),
                ] : null,
                'started_at' => $alert->session->started_at,
                'expected_end_at' => $alert->session->expected_end_at,
                'last_check_in_at' => $alert->session->last_check_in_at,
                'status' => $alert->session->status,
                'activity_description' => $alert->session->activity_description,
                'check_in_interval_minutes' => $alert->session->check_in_interval_minutes,
                'location' => $alert->session->location,
            ] : null,
            'type' => $alert->alert_type,
            'triggered_at' => $alert->triggered_at,
            'status' => $alert->status,
            'source' => 'legacy',
            'notes' => $alert->resolution_notes ?? null,
        ];
    }

    /**
     * Map a canonical ControlRoomAlert to the shared alert display shape (source of truth).
     */
    private function mapCanonicalAlert(ControlRoomAlert $alert): array
    {
        $ctx = $alert->context['normalized_data'] ?? [];

        return [
            'id' => 'cr_' . $alert->id,
            'session' => [
                'id' => $ctx['lone_worker_session_id'] ?? null,
                'user' => isset($ctx['worker_name']) ? [
                    'id' => $ctx['worker_user_id'] ?? null,
                    'name' => $ctx['worker_name'],
                ] : null,
                'site' => isset($ctx['site_name']) ? [
                    'id' => $ctx['site_id'] ?? null,
                    'name' => $ctx['site_name'],
                ] : null,
                'client' => isset($ctx['client_name']) ? [
                    'id' => $ctx['client_id'] ?? null,
                    'name' => $ctx['client_name'],
                ] : null,
                'started_at' => $ctx['started_at'] ?? null,
                'expected_end_at' => $ctx['expected_end_at'] ?? null,
                'last_check_in_at' => $ctx['last_check_in_at'] ?? null,
                'status' => null,
                'activity_description' => $ctx['activity_description'] ?? null,
                'check_in_interval_minutes' => null,
                'location' => $ctx['location'] ?? null,
                'location_lat' => $ctx['location_lat'] ?? null,
                'location_lng' => $ctx['location_lng'] ?? null,
            ],
            'type' => $alert->alert_type,
            'triggered_at' => $alert->triggered_at,
            'status' => $this->mapCrStatus($alert->status),
            'source' => 'control_room',
            'notes' => $alert->notes,
        ];
    }
}
