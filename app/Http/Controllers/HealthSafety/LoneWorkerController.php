<?php

namespace App\Http\Controllers\HealthSafety;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\LoneWorkerAlert;
use App\Models\LoneWorkerSession;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Services\HealthSafety\LoneWorkerSignalService;
use App\Services\HealthSafety\ShiftGpsAccessService;
use App\Services\Queclink\LocateNowService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use UnexpectedValueException;

/**
 * Lone Worker session management — the coordinator / H&S "watch-tower".
 *
 * ARCHITECTURAL NOTE (PR4):
 * Operational lone worker alerts flow through the canonical pipeline:
 *   LoneWorkerSignalService → SignalProcessingService → ControlRoomAlert
 *
 * ControlRoomAlert (source='lone_worker') is the operational source of truth.
 * LoneWorkerAlert is read-only historical evidence. It never drives operational
 * triage, SLA, escalation, playbooks, or convenience actions. Operators work the
 * single canonical ControlRoomAlert lifecycle from Control Room or this H&S view.
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

    /** Explicit H&S-wide access; hazards.view/manage remain site-scoped. */
    private const SITE_BYPASS_PERMISSIONS = UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS;

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly ShiftGpsAccessService $shiftGpsAccess,
        private readonly CanonicalDeviceSiteResolver $deviceSites,
    ) {}

    /**
     * The coordinator watch-tower: live register, alerts, hero KPIs, detail.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $tab = $request->input('tab') === 'alerts' ? 'alerts' : 'sessions';
        $requestedSiteId = $request->integer('site_id') ?: null;
        if ($requestedSiteId !== null) {
            $this->siteAccess->assertCanAccessSiteId($user, $requestedSiteId, self::SITE_BYPASS_PERMISSIONS);
        }

        $filters = [
            'site_id' => $requestedSiteId,
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
        if ($tab === 'sessions') {
            $sessionsQuery = LoneWorkerSession::with(['user:id,name', 'site:id,name', 'client:id,first_name,last_name'])
                ->when(
                    $filters['site_id'],
                    fn (Builder $query) => $this->applySessionSiteFilter(
                        $query,
                        (int) $filters['site_id'],
                    ),
                )
                ->when($filters['status'], fn ($q) => $q->where('status', $filters['status']))
                ->when($filters['user_id'], fn ($q) => $q->where('user_id', $filters['user_id']))
                ->when($boundary, fn ($q) => $q->where(function ($w) use ($boundary) {
                    $w->where('started_at', '>=', $boundary)
                        ->orWhereIn('status', self::LIVE_STATUSES);
                }))
                ->when($filters['q'], fn ($q) => $q->where(function ($w) use ($filters) {
                    $term = '%'.$filters['q'].'%';
                    $w->whereHas('user', fn ($u) => $u->where('name', 'like', $term))
                        ->orWhereHas('client', fn ($c) => $c
                            ->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term))
                        ->orWhere('activity_description', 'like', $term)
                        ->orWhere('location', 'like', $term);
                }))
                // Most-urgent first: emergency → overdue → active → completed, then newest.
                ->orderByRaw("FIELD(status, 'emergency', 'overdue', 'active', 'completed')")
                ->orderByDesc('started_at');
            $this->applySessionScope($sessionsQuery, $user);
            $sessions = $sessionsQuery->paginate(25)
                ->withQueryString()
                ->through(fn ($s) => $this->mapSession($s));
        } else {
            $sessions = $this->emptyPaginator();
        }

        // ── Alerts register (canonical ControlRoomAlert, source=lone_worker) ──
        if ($tab === 'alerts') {
            $alertsQuery = ControlRoomAlert::where('source', 'lone_worker')
                ->with(['client:id,first_name,last_name', 'site:id,name'])
                ->when($filters['site_id'], fn ($q) => $q->where('site_id', $filters['site_id']))
                ->when($filters['status'], fn ($q) => $q->where('status', $this->crStatus($filters['status'])))
                ->when(! $filters['status'], fn ($q) => $q->whereIn('status', [
                    ...ControlRoomAlert::ACTIVE_STATUSES,
                    ControlRoomAlert::STATUS_RESOLVED,
                    ControlRoomAlert::STATUS_CLOSED,
                ]))
                ->when($boundary, fn ($q) => $q->where(function ($w) use ($boundary) {
                    $w->where('triggered_at', '>=', $boundary)
                        ->orWhereIn('status', ControlRoomAlert::ACTIVE_STATUSES);
                }))
                ->when($filters['q'], fn ($q) => $q->where(function ($w) use ($filters) {
                    $term = '%'.$filters['q'].'%';
                    $w->where('notes', 'like', $term)
                        ->orWhereHas('client', fn ($c) => $c
                            ->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term))
                        ->orWhereHas('site', fn ($s) => $s->where('name', 'like', $term));
                }))
                ->orderByDesc('triggered_at');
            $this->siteAccess->applyAlertScope($alertsQuery, $user, self::SITE_BYPASS_PERMISSIONS);
            $alerts = $alertsQuery->paginate(25)
                ->withQueryString();
            $sessionsById = $this->scopedSessionsForCanonicalAlerts(
                $alerts->getCollection(),
                $user,
            );
            $alerts->through(function (ControlRoomAlert $alert) use ($sessionsById): array {
                $sessionId = $this->nullablePositiveId(
                    data_get($alert->context, 'normalized_data.lone_worker_session_id'),
                );

                return $this->mapCanonicalAlert(
                    $alert,
                    $sessionId === null ? null : $sessionsById->get($sessionId),
                );
            });
        } else {
            $alerts = $this->emptyPaginator();
        }

        // ── Detail (param-driven partial reload) ─────────────────────────
        $detail = null;
        if ($request->filled('session')) {
            $detail = $this->sessionDetail((int) $request->input('session'), $user);
        } elseif ($request->filled('alert')) {
            $detail = $this->alertDetail((string) $request->input('alert'), $request);
        }

        $shiftData = $this->monitorableShifts($user);
        $siteQuery = Site::select('id', 'name', 'address_line_1', 'suburb', 'city', 'postcode', 'latitude', 'longitude')
            ->where('is_active', true)
            ->orderBy('name');
        $this->siteAccess->applySiteScope($siteQuery, $user, self::SITE_BYPASS_PERMISSIONS);
        $staffQuery = User::select('id', 'name')->orderBy('name');
        $this->siteAccess->applyStaffScope($staffQuery, $user, self::SITE_BYPASS_PERMISSIONS);
        $clientQuery = Client::select('id', 'first_name', 'last_name')->orderBy('last_name');
        $this->siteAccess->applyClientScope($clientQuery, $user, self::SITE_BYPASS_PERMISSIONS);

        return Inertia::render('health-safety/lone-workers/index', [
            'tab' => $tab,
            'sessions' => $sessions,
            'alerts' => $alerts,
            'detail' => $detail,
            'tabCounts' => $this->tabCounts($user),
            'hero' => $this->heroBlock($shiftData['unmonitored_lone'], $user),
            'filters' => $filters,
            'options' => [
                'sites' => $siteQuery->get()
                    ->map(fn ($s) => [
                        'id' => $s->id,
                        'name' => $s->name,
                        // Composed one-line address so the wizard can prefill the location
                        // field when a site is chosen ("selectable from site").
                        'address' => collect([$s->address_line_1, $s->suburb, $s->city, $s->postcode])
                            ->filter()->implode(', ') ?: null,
                        'latitude' => $s->latitude,
                        'longitude' => $s->longitude,
                    ]),
                'staff' => $staffQuery->get(),
                'clients' => $clientQuery->get()
                    ->map(fn ($c) => ['id' => $c->id, 'name' => trim($c->first_name.' '.$c->last_name)]),
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

        DB::transaction(function () use ($request, $validated): void {
            $actor = User::query()
                ->whereKey($request->user()->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($actor->canDo('hazards.manage'), 403);

            $shift = ! empty($validated['shift_id'])
                ? Shift::query()->whereKey($validated['shift_id'])->lockForUpdate()->firstOrFail()
                : null;
            if ($shift && $shift->client_id !== null) {
                $shift->setRelation(
                    'client',
                    Client::query()->whereKey($shift->client_id)->lockForUpdate()->first(),
                );
                foreach (collect([$shift->site_id, $shift->client?->site_id])
                    ->map(fn (mixed $siteId): ?int => $this->nullablePositiveId($siteId))
                    ->filter()
                    ->unique() as $candidateSiteId) {
                    $this->siteAccess->assertCanAccessSiteId(
                        $actor,
                        $candidateSiteId,
                        self::SITE_BYPASS_PERMISSIONS,
                    );
                }
                if ($shift->site_id !== null
                    && $shift->client?->site_id !== null
                    && (int) $shift->site_id !== (int) $shift->client->site_id) {
                    throw ValidationException::withMessages([
                        'shift_id' => 'The rostered shift has conflicting site and client details. Correct the shift before starting monitoring.',
                    ]);
                }
            }
            if ($shift) {
                $this->siteAccess->assertCanAccessShift($actor, $shift, self::SITE_BYPASS_PERMISSIONS);
            }

            $workerQuery = User::query()->staff()->whereKey($validated['user_id']);
            $this->siteAccess->applyStaffScope($workerQuery, $actor, self::SITE_BYPASS_PERMISSIONS);
            $worker = $workerQuery->lockForUpdate()->first();
            abort_unless($worker, 403, 'You are not authorized to start monitoring for that worker.');

            if ($shift && (int) $shift->user_id !== (int) $worker->id) {
                throw ValidationException::withMessages([
                    'user_id' => 'The selected worker must match the worker rostered on the shift.',
                ]);
            }

            $requestedClientId = ! empty($validated['client_id'])
                ? (int) $validated['client_id']
                : null;
            $shiftClientId = $shift && $shift->client_id !== null
                ? (int) $shift->client_id
                : null;
            if ($shift && $shiftClientId !== $requestedClientId) {
                throw ValidationException::withMessages([
                    'client_id' => 'The selected client must exactly match the client rostered on the shift.',
                ]);
            }
            $clientId = $shift ? $shiftClientId : $requestedClientId;
            $client = null;
            if ($clientId !== null) {
                $clientQuery = Client::query()->whereKey($clientId);
                $this->siteAccess->applyClientScope($clientQuery, $actor, self::SITE_BYPASS_PERMISSIONS);
                $client = $clientQuery->lockForUpdate()->first();
                abort_unless($client, 403, 'You are not authorized to start monitoring for that client.');
            }

            $shiftSiteId = $shift ? $this->siteAccess->shiftSiteId($shift) : null;
            $requestedSiteId = ! empty($validated['site_id'])
                ? (int) $validated['site_id']
                : null;
            if ($shiftSiteId !== null
                && $requestedSiteId !== null
                && $shiftSiteId !== $requestedSiteId) {
                throw ValidationException::withMessages([
                    'site_id' => 'The selected site must match the site for the rostered shift.',
                ]);
            }
            $siteId = $shiftSiteId
                ?? $requestedSiteId
                ?? ($client?->site_id ? (int) $client->site_id : null);
            if ($siteId === null) {
                throw ValidationException::withMessages([
                    'site_id' => 'Select an accessible site, client, or rostered shift for this session.',
                ]);
            }
            if ($client && (int) $client->site_id !== $siteId) {
                throw ValidationException::withMessages([
                    'client_id' => 'The selected client must belong to the authoritative site for this session.',
                ]);
            }

            $siteQuery = Site::query()->whereKey($siteId);
            $this->siteAccess->applySiteScope($siteQuery, $actor, self::SITE_BYPASS_PERMISSIONS);
            $site = $siteQuery->lockForUpdate()->first();
            abort_unless(
                $site
                    && in_array(
                        (int) $site->id,
                        $this->siteAccess->accessibleSiteIds($worker),
                        true,
                    ),
                403,
                'You are not authorized to start monitoring at that site.',
            );

            $sessionData = $validated;
            $sessionData['user_id'] = $worker->id;
            $sessionData['shift_id'] = $shift?->id;
            $sessionData['client_id'] = $client?->id;
            $sessionData['site_id'] = $siteId;

            $session = LoneWorkerSession::query()->create(array_merge($sessionData, [
                'started_at' => now(),
                'last_check_in_at' => now(),
                'status' => 'active',
                'check_in_interval_minutes' => $sessionData['check_in_interval_minutes'] ?? 60,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]));

            // A Shift GPS ping is staff location evidence. It may be reused only
            // after the authorised lone-worker safety session is live, and only
            // through the canonical Shift Site and worker assignment boundary.
            if ($shift
                && $actor->canDo('assets.telemetry.view')
                && blank($sessionData['location_lat'] ?? null)
                && blank($sessionData['location_lng'] ?? null)) {
                $ping = $this->shiftGpsAccess->latestForLiveSession($actor, $shift, $session);
                if ($ping) {
                    $session->update([
                        'location_lat' => $ping->latitude,
                        'location_lng' => $ping->longitude,
                    ]);
                }
            }

            AuditLogger::logOrFail('healthSafety.loneWorker.session.start', $session, [
                'actor_id' => $actor->id,
                'worker_user_id' => $worker->id,
                'site_id' => $site->id,
                'shift_id' => $shift?->id,
                'client_id' => $client?->id,
            ]);
        }, 3);

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
        $validated = $request->validate([
            'expected_end_at' => ['required', 'date'],
            'check_in_interval_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'activity_description' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:500'],
            'location_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'location_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $updated = DB::transaction(function () use ($request, $session, $validated): bool {
            $actor = $this->lockedActor($request);
            abort_unless($actor->canDo('hazards.manage'), 403);
            $lockedSession = $this->lockedSessionContext((int) $session->id);
            $this->assertCanAccessSession($actor, $lockedSession);

            if (! in_array($lockedSession->status, ['active', 'overdue'], true)) {
                return false;
            }

            $lockedSession->update(array_merge($validated, [
                // Extending an overdue session deliberately resumes monitoring.
                'status' => 'active',
                'updated_by' => $actor->id,
            ]));
            $this->auditSessionMutation(
                'healthSafety.loneWorker.session.update',
                $lockedSession,
                $actor,
            );

            return true;
        }, 3);

        if (! $updated) {
            return back()->with('error', 'Only active or overdue sessions can be edited.');
        }

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
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:ok,concern,emergency'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'location_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'location_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $recorded = DB::transaction(function () use ($request, $session, $validated): bool {
            $actor = $this->lockedActor($request);
            $lockedSession = $this->lockedSessionContext((int) $session->id);
            $lockedOwner = $actor->id === $lockedSession->user_id;
            abort_unless($lockedOwner || $actor->canDo('hazards.manage'), 403);
            $this->assertCanAccessSession($actor, $lockedSession, $lockedOwner);

            if (! in_array($lockedSession->status, ['active', 'overdue'], true)) {
                return false;
            }

            $checkedInAt = now();
            $lockedSession->checkIns()->create(array_merge($validated, [
                'checked_in_at' => $checkedInAt,
                'status' => $validated['status'] ?? 'ok',
            ]));

            $lockedSession->update([
                'last_check_in_at' => $checkedInAt,
                'status' => 'active',
                'updated_by' => $actor->id,
            ]);

            // If check-in status is emergency, trigger emergency flow.
            if (($validated['status'] ?? 'ok') === 'emergency') {
                $lockedSession->update([
                    'status' => 'emergency',
                    'emergency_triggered_at' => $checkedInAt,
                    'emergency_notes' => $validated['notes'] ?? null,
                ]);

                // Canonical signal → Control Room (operational source of truth)
                app(LoneWorkerSignalService::class)->emitEmergency(
                    $lockedSession,
                    $validated['notes'] ?? null,
                );
            }

            $this->auditSessionMutation(
                'healthSafety.loneWorker.session.checkIn',
                $lockedSession,
                $actor,
                ['check_in_status' => $validated['status'] ?? 'ok'],
            );

            return true;
        }, 3);

        if (! $recorded) {
            return back()->with('error', 'Only active or overdue sessions can receive a check-in.');
        }

        return redirect()->back()->with('success', 'Check-in recorded successfully.');
    }

    /**
     * End a lone worker session.
     */
    public function endSession(Request $request, LoneWorkerSession $session): RedirectResponse
    {
        $ended = DB::transaction(function () use ($request, $session): bool {
            $actor = $this->lockedActor($request);
            abort_unless($actor->canDo('hazards.manage'), 403);
            $lockedSession = $this->lockedSessionContext((int) $session->id);
            $this->assertCanAccessSession($actor, $lockedSession);

            if (! in_array($lockedSession->status, ['active', 'overdue'], true)) {
                return false;
            }

            $lockedSession->update([
                'ended_at' => now(),
                'status' => 'completed',
                'updated_by' => $actor->id,
            ]);
            $this->auditSessionMutation(
                'healthSafety.loneWorker.session.end',
                $lockedSession,
                $actor,
            );

            return true;
        }, 3);

        if (! $ended) {
            return back()->with('error', 'Only active or overdue sessions can be ended. Resolve an emergency in Control Room first.');
        }

        return redirect()->back()->with('success', 'Lone worker session ended successfully.');
    }

    /**
     * Soft-delete a completed session — remove an erroneous / test / duplicate entry from
     * the register. SoftDeletes retains the row (and AuditableChanges logs the removal) so
     * the safety record is never destroyed and an administrator can restore it. Only
     * completed sessions are removable: a live (active/overdue) or emergency session must be
     * ended first, so we never silently drop active monitoring or a critical emergency record.
     */
    public function destroy(Request $request, LoneWorkerSession $session): RedirectResponse
    {
        $removed = DB::transaction(function () use ($request, $session): bool {
            $actor = $this->lockedActor($request);
            abort_unless($actor->canDo('hazards.manage'), 403);
            $lockedSession = $this->lockedSessionContext((int) $session->id);
            $this->assertCanAccessSession($actor, $lockedSession);

            if ($lockedSession->status !== 'completed') {
                return false;
            }

            $lockedSession->update(['updated_by' => $actor->id]);
            $lockedSession->delete();
            $this->auditSessionMutation(
                'healthSafety.loneWorker.session.delete',
                $lockedSession,
                $actor,
            );

            return true;
        }, 3);

        if (! $removed) {
            return back()->with('error', 'Only completed sessions can be removed. End the session first.');
        }

        return redirect()->route('health-safety.lone-workers.index')
            ->with('success', 'Session removed from the register (retained for audit).');
    }

    /**
     * Trigger emergency for an active session.
     */
    public function triggerEmergency(Request $request, LoneWorkerSession $session): RedirectResponse
    {
        $validated = $request->validate([
            'emergency_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $outcome = DB::transaction(function () use ($request, $session, $validated): string {
            $actor = $this->lockedActor($request);
            abort_unless($actor->canDo('hazards.manage'), 403);
            $lockedSession = $this->lockedSessionContext((int) $session->id);
            $this->assertCanAccessSession($actor, $lockedSession);

            if ($lockedSession->status === 'emergency') {
                return 'already_emergency';
            }

            if (! in_array($lockedSession->status, ['active', 'overdue'], true)) {
                return 'not_live';
            }

            $triggeredAt = now();
            $lockedSession->update([
                'status' => 'emergency',
                'emergency_triggered_at' => $triggeredAt,
                'emergency_notes' => $validated['emergency_notes'] ?? null,
                'updated_by' => $actor->id,
            ]);

            // Canonical signal → Control Room (operational source of truth)
            app(LoneWorkerSignalService::class)->emitEmergency(
                $lockedSession,
                $validated['emergency_notes'] ?? null,
            );
            $this->auditSessionMutation(
                'healthSafety.loneWorker.session.emergency',
                $lockedSession,
                $actor,
            );

            return 'triggered';
        });

        if ($outcome === 'already_emergency') {
            return redirect()->back()->with('success', 'Emergency alert is already active in Control Room.');
        }

        if ($outcome === 'not_live') {
            return back()->with('error', 'Only active or overdue sessions can trigger an emergency.');
        }

        return redirect()->back()->with('success', 'Emergency alert sent to Control Room.');
    }

    /**
     * Acknowledge the canonical operational alert. Replays are idempotent.
     */
    public function acknowledgeAlert(
        Request $request,
        ControlRoomAlert $alert,
        ControlRoomAlertLifecycleService $lifecycle,
    ): RedirectResponse {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $alert, $lifecycle, $validated): void {
            $actor = $this->lockedActor($request);
            abort_unless($actor->canDo('hazards.manage'), 403);
            [$canonical] = $this->lockedCanonicalAlertContext((int) $alert->id, $actor);
            if ($canonical->status === ControlRoomAlert::STATUS_OPEN) {
                $lifecycle->acknowledge($canonical, $actor, $validated['notes'] ?? null);
            } elseif (! in_array($canonical->status, [
                ControlRoomAlert::STATUS_ACK,
                ControlRoomAlert::STATUS_TRIAGING,
                ControlRoomAlert::STATUS_CONFIRMED,
                ControlRoomAlert::STATUS_RESOLVED,
                ControlRoomAlert::STATUS_CLOSED,
            ], true)) {
                abort(409, 'This canonical alert cannot be acknowledged in its current state.');
            }
        }, 3);

        return redirect()->back()
            ->with('success', 'Control Room alert acknowledged.');
    }

    /**
     * Resolve the canonical operational alert. Replays are idempotent.
     */
    public function resolveAlert(
        Request $request,
        ControlRoomAlert $alert,
        ControlRoomAlertLifecycleService $lifecycle,
    ): RedirectResponse {
        $validated = $request->validate([
            'resolution_notes' => ['required', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $alert, $validated, $lifecycle): void {
            $actor = $this->lockedActor($request);
            abort_unless($actor->canDo('hazards.manage'), 403);
            [$canonical, $lockedSession] = $this->lockedCanonicalAlertContext((int) $alert->id, $actor);
            if (! in_array($canonical->status, [
                ControlRoomAlert::STATUS_RESOLVED,
                ControlRoomAlert::STATUS_CLOSED,
            ], true)) {
                if ($canonical->status === ControlRoomAlert::STATUS_OPEN) {
                    $canonical = $lifecycle->acknowledge($canonical, $actor);
                }
                if ($canonical->status === ControlRoomAlert::STATUS_ACK) {
                    $canonical = $lifecycle->startTriage($canonical, $actor);
                }
                if (in_array($canonical->status, [
                    ControlRoomAlert::STATUS_TRIAGING,
                    ControlRoomAlert::STATUS_CONFIRMED,
                ], true)) {
                    $canonical = $lifecycle->resolve(
                        $canonical,
                        $actor,
                        $validated['resolution_notes'],
                        'resolved_in_health_safety',
                    );
                }
                abort_unless($canonical->status === ControlRoomAlert::STATUS_RESOLVED, 409);
            }

            $this->resumeResolvedEmergencySession($canonical, $lockedSession, $actor);
        }, 3);

        return redirect()->back()
            ->with('success', 'Control Room alert resolved.');
    }

    /**
     * Hand the worker's paired GPS tracker to the governed Device command plane.
     */
    public function locateNow(Request $request, LoneWorkerSession $session, LocateNowService $locateNow): RedirectResponse
    {
        $managementUrl = DB::transaction(function () use ($request, $session, $locateNow): ?string {
            $actor = $this->lockedActor($request);
            abort_unless($actor->canDo('hazards.manage'), 403);
            [$lockedSession, $device] = $this->lockedTrackerSessionContext((int) $session->id);
            $this->assertCanAccessSession($actor, $lockedSession);

            if (! $device) {
                return null;
            }

            $this->auditSessionMutation(
                'healthSafety.loneWorker.location.management_opened',
                $lockedSession,
                $actor,
                [
                    'device_id' => $device->id,
                    'capability' => 'tracking.location_refresh',
                ],
            );

            return $locateNow->managementUrlForDevice($device);
        }, 3);

        if ($managementUrl === null) {
            return back()->with('error', 'This worker does not have a paired GPS tracker.');
        }

        return redirect()->to($managementUrl)->with(
            'success',
            'Review the governed location refresh, confirm your identity, and record the operational reason before dispatch.',
        );
    }

    /**
     * Acknowledge a tracker panic: clear the device panic flag and ack the open
     * Control Room lone-worker alerts for this session.
     */
    public function acknowledgePanic(
        Request $request,
        LoneWorkerSession $session,
        ControlRoomAlertLifecycleService $lifecycle,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $session, $lifecycle): void {
            $actor = $this->lockedActor($request);
            abort_unless($actor->canDo('hazards.manage'), 403);
            [$lockedSession, $device] = $this->lockedTrackerSessionContext((int) $session->id);
            $this->assertCanAccessSession($actor, $lockedSession);

            if ($device) {
                $meta = $device->meta ?? [];
                $meta['panic_active'] = false;
                $meta['panic_acknowledged_at'] = now()->toISOString();
                $meta['panic_acknowledged_by'] = $actor->id;
                $device->forceFill(['meta' => $meta])->save();
            }

            $alertsQuery = ControlRoomAlert::where('source', 'lone_worker')
                ->where('alert_type', LoneWorkerSignalService::canonicalAlertType(
                    LoneWorkerSignalService::TYPE_EMERGENCY,
                ))
                ->where('context->normalized_data->lone_worker_session_id', $lockedSession->id)
                ->where('status', ControlRoomAlert::STATUS_OPEN);
            $this->siteAccess->applyAlertScope($alertsQuery, $actor, self::SITE_BYPASS_PERMISSIONS);
            $alerts = $alertsQuery->lockForUpdate()->get()
                ->filter(fn (ControlRoomAlert $alert) => $this->canonicalAlertMatchesSession(
                    $alert,
                    $lockedSession,
                ));
            abort_if($alerts->count() > 1, 409, 'This emergency has contradictory canonical alerts.');
            if ($alerts->isNotEmpty()) {
                $lifecycle->acknowledge($alerts->first(), $actor);
            }
            $this->auditSessionMutation(
                'healthSafety.loneWorker.panic.acknowledge',
                $lockedSession,
                $actor,
            );
        }, 3);

        return back()->with('success', 'Panic acknowledged.');
    }

    /* ───────────────────────────── Payload builders ───────────────────────────── */

    /**
     * Tab badge counts (application-wide totals, never filter-scoped).
     */
    private function tabCounts(User $user): array
    {
        $sessionsQuery = LoneWorkerSession::whereIn('status', self::LIVE_STATUSES);
        $this->applySessionScope($sessionsQuery, $user);
        $alertsQuery = ControlRoomAlert::where('source', 'lone_worker')->actionable();
        $this->siteAccess->applyAlertScope($alertsQuery, $user, self::SITE_BYPASS_PERMISSIONS);

        return [
            'sessions' => $sessionsQuery->count(),
            'alerts' => $alertsQuery->count(),
        ];
    }

    /**
     * Hero KPI clusters + NZ compliance badge counts/booleans.
     * Counts & booleans only — the page formats the copy (never pre-format here).
     */
    private function heroBlock(int $loneShiftsUnmonitored, User $user): array
    {
        // Load active sessions once; derive the check-in freshness counts in PHP via the
        // model's Carbon helper (UTC-correct) rather than SQL NOW(), which can sit in a
        // different timezone than the stored UTC datetimes and skew the comparison.
        $activeRowsQuery = LoneWorkerSession::where('status', 'active');
        $this->applySessionScope($activeRowsQuery, $user);
        $activeRows = $activeRowsQuery->get([
            'id', 'status', 'last_check_in_at', 'started_at', 'check_in_interval_minutes',
        ]);
        $active = $activeRows->count();
        $overdueQuery = LoneWorkerSession::where('status', 'overdue');
        $this->applySessionScope($overdueQuery, $user);
        $overdue = $overdueQuery->count();
        $emergencyQuery = LoneWorkerSession::where('status', 'emergency');
        $this->applySessionScope($emergencyQuery, $user);
        $emergency = $emergencyQuery->count();

        $endingSoonQuery = LoneWorkerSession::where('status', 'active')
            ->whereBetween('expected_end_at', [now(), now()->addHour()]);
        $this->applySessionScope($endingSoonQuery, $user);
        $endingSoon = $endingSoonQuery->count();

        // Active sessions already past their check-in window (not yet flipped by the 5-min job).
        $noRecentCheckIn = $activeRows->filter->isCheckInOverdue()->count();
        // Active sessions still within their check-in window (genuinely "checked in").
        $checkedIn = $active - $noRecentCheckIn;

        $alertBase = ControlRoomAlert::where('source', 'lone_worker');
        $this->siteAccess->applyAlertScope($alertBase, $user, self::SITE_BYPASS_PERMISSIONS);
        $alertsToday = (clone $alertBase)
            ->where('triggered_at', '>=', now()->startOfDay())
            ->count();
        $awaitingAck = (clone $alertBase)->where('status', 'open')->count();
        $unresolved = (clone $alertBase)->actionable()->count();

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
     * "Lone" prefers the explicit Shift.is_lone_worker roster flag; for shifts not
     * yet flagged it falls back to the heuristic (on-call, or solo cover — the
     * worker is the only person currently on shift at their site).
     */
    private function monitorableShifts(User $user): array
    {
        $shiftQuery = Shift::with(['staff:id,name', 'site:id,name', 'client:id,first_name,last_name'])
            ->whereNotNull('actual_starts_at')
            ->whereNull('actual_ends_at')
            ->where('status', '!=', 'cancelled')
            ->where('ends_at', '>=', now()->subHours(2))
            ->whereDoesntHave('loneWorkerSession', fn ($q) => $q->whereIn('status', self::LIVE_STATUSES))
            ->orderBy('ends_at')
            ->limit(100);
        $this->siteAccess->applyShiftScope($shiftQuery, $user, self::SITE_BYPASS_PERMISSIONS);
        $shifts = $shiftQuery->get();

        $siteCounts = $shifts->whereNotNull('site_id')->groupBy('site_id')->map->count();

        $list = $shifts->map(function (Shift $shift) use ($siteCounts) {
            $isSolo = $shift->site_id && ($siteCounts[$shift->site_id] ?? 0) === 1;
            // Explicit roster flag is authoritative; fall back to the heuristic
            // (on-call, or solo cover at the site) for shifts not yet flagged.
            $isLone = (bool) $shift->is_lone_worker || (bool) $shift->is_on_call || $isSolo;

            return [
                'id' => $shift->id,
                'worker' => $shift->staff ? ['id' => $shift->staff->id, 'name' => $shift->staff->name] : null,
                'site' => $shift->site ? ['id' => $shift->site->id, 'name' => $shift->site->name] : null,
                'client' => $shift->client
                    ? ['id' => $shift->client->id, 'name' => trim($shift->client->first_name.' '.$shift->client->last_name)]
                    : null,
                'starts_at' => $shift->starts_at,
                'ends_at' => $shift->ends_at,
                'location' => $shift->location,
                // Staff GPS remains redacted until an authorised lone-worker
                // safety session is active. The Site's fixed coordinates remain
                // available separately to the wizard for its location field.
                'location_lat' => null,
                'location_lng' => null,
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
                ? ['id' => $s->client->id, 'name' => trim($s->client->first_name.' '.$s->client->last_name)]
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
    private function sessionDetail(int $id, User $user): ?array
    {
        $sessionQuery = LoneWorkerSession::with([
            ...$this->canonicalSessionSecurityRelations(),
            'checkIns',
            'alerts',
        ]);
        $this->applySessionScope($sessionQuery, $user);
        $s = $sessionQuery->find($id);

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

        $canonicalQuery = ControlRoomAlert::where('source', 'lone_worker')
            ->where('context->normalized_data->lone_worker_session_id', $s->id)
            ->orderByDesc('triggered_at');
        $this->siteAccess->applyAlertScope($canonicalQuery, $user, self::SITE_BYPASS_PERMISSIONS);
        $canonical = $canonicalQuery->get()
            ->filter(fn (ControlRoomAlert $alert) => $this->canonicalAlertMatchesSession($alert, $s))
            ->map(fn ($a) => [
                'id' => 'cr_'.$a->id,
                'type' => $a->alert_type,
                'triggered_at' => $a->triggered_at,
                'status' => $this->mapCrStatus($a->status),
                'source' => 'control_room',
            ]);

        $data['alerts'] = $canonical->sortByDesc('triggered_at')->values();
        $data['legacy_alert_history'] = $s->alerts
            ->sortByDesc('triggered_at')
            ->values()
            ->map(fn (LoneWorkerAlert $alert) => [
                'id' => 'legacy_'.$alert->id,
                'type' => $alert->alert_type,
                'triggered_at' => $alert->triggered_at,
                'status' => 'historical',
                'source' => 'legacy_history',
                'label' => 'Historical record — read only',
            ]);
        $data['shift'] = $s->shift ? [
            'id' => $s->shift->id,
            'starts_at' => $s->shift->starts_at,
            'ends_at' => $s->shift->ends_at,
            'status' => $s->shift->status,
            'is_on_call' => (bool) $s->shift->is_on_call,
        ] : null;

        // Paired GPS tracker (staff/lone-worker Queclink device), if any — last-known
        // location + Locate-now / acknowledge-panic actions for the detail card.
        $trackerState = 'not_assigned';
        $device = $this->workerTrackerDevice($s, availability: $trackerState);
        $data['tracker'] = $device ? $this->buildWorkerTrackerPayload($device, (int) $s->id) : null;
        $data['tracker_state'] = $trackerState;

        return $data;
    }

    /**
     * The GPS tracker actively assigned to a staff member (lone worker), if any.
     * Resolves via the canonical TARGET_STAFF DeviceAssignment and canonical
     * Device Site. The session Site, worker Site and Device Site must agree.
     */
    private function workerTrackerDevice(
        LoneWorkerSession $session,
        bool $lockForUpdate = false,
        bool $failOnConflict = false,
        ?string &$availability = null,
    ): ?Device {
        $availability = 'not_assigned';

        try {
            $siteId = $this->nullablePositiveId($session->site_id);
            $worker = $session->user instanceof User
                ? $session->user
                : User::query()->find($session->user_id);
            if ($siteId === null
                || ! $worker
                || ! in_array($siteId, $this->siteAccess->accessibleSiteIds($worker), true)) {
                throw new UnexpectedValueException('Tracker session Site provenance is invalid.');
            }

            $assignmentQuery = DeviceAssignment::query()
                ->whereHas('device', fn (Builder $deviceQuery) => $deviceQuery->where('domain', 'tracking'))
                ->where('assignable_type', DeviceAssignment::TARGET_STAFF)
                ->where('assignable_id', $worker->id)
                ->whereNull('released_at')
                ->whereNull('collection_stopped_at')
                ->where('assigned_at', '<=', now())
                ->orderBy('id')
                ->limit(2);
            if ($lockForUpdate) {
                $assignmentQuery->lockForUpdate();
            }
            $assignments = $assignmentQuery->get();
            if ($assignments->isEmpty()) {
                return null;
            }
            if ($assignments->count() !== 1) {
                throw new UnexpectedValueException('Worker has contradictory active tracker assignments.');
            }

            $assignment = $assignments->first();
            $deviceQuery = Device::query()
                ->whereKey($assignment->device_id)
                ->where('domain', 'tracking');
            if ($lockForUpdate) {
                $deviceQuery->lockForUpdate();
            }
            $device = $deviceQuery->first();
            if (! $device || $this->deviceSites->resolveForContext((int) $device->id) !== $siteId) {
                throw new UnexpectedValueException('Tracker Device Site does not match the session Site.');
            }

            $availability = 'available';

            return $device;
        } catch (UnexpectedValueException) {
            $availability = 'integrity_error';
            if ($failOnConflict) {
                abort(403, UserSiteAccessService::DEFAULT_MESSAGE);
            }

            return null;
        }
    }

    private function resumeResolvedEmergencySession(
        ControlRoomAlert $alert,
        LoneWorkerSession $session,
        User $actor,
    ): void {
        if ($alert->alert_type !== LoneWorkerSignalService::canonicalAlertType(
            LoneWorkerSignalService::TYPE_EMERGENCY,
        ) || $session->status !== 'emergency') {
            return;
        }

        $now = now();
        $lastCheckIn = $session->last_check_in_at ?? $session->started_at;
        $checkInOverdue = $lastCheckIn !== null
            && (int) $session->check_in_interval_minutes > 0
            && $lastCheckIn->copy()->addMinutes((int) $session->check_in_interval_minutes)->lte($now);
        $sessionOverdue = $session->expected_end_at?->lte($now) === true;
        $resumedStatus = $checkInOverdue || $sessionOverdue ? 'overdue' : 'active';

        $session->update([
            'status' => $resumedStatus,
            'updated_by' => $actor->id,
        ]);
        $this->auditSessionMutation(
            'healthSafety.loneWorker.session.emergencyResolved',
            $session,
            $actor,
            [
                'control_room_alert_id' => $alert->id,
                'resumed_status' => $resumedStatus,
            ],
        );
    }

    /**
     * Cross-path lock invariant shared with staff-SOS telemetry:
     * DeviceAssignment -> Device -> LoneWorkerSession.
     *
     * @return array{LoneWorkerSession, Device|null}
     */
    private function lockedTrackerSessionContext(int $sessionId): array
    {
        $snapshot = LoneWorkerSession::query()
            ->with('user:id,name,approved_at')
            ->select(['id', 'user_id', 'site_id'])
            ->whereKey($sessionId)
            ->firstOrFail();
        $snapshotUserId = (int) $snapshot->user_id;
        $snapshotSiteId = $this->nullablePositiveId($snapshot->site_id);
        $device = $this->workerTrackerDevice($snapshot, true, true);
        $session = $this->lockedSessionContext($sessionId);

        abort_unless(
            (int) $session->user_id === $snapshotUserId
                && $this->nullablePositiveId($session->site_id) === $snapshotSiteId,
            403,
        );

        return [$session, $device];
    }

    /**
     * Lean tracker payload for the session detail "Last-known location" card.
     */
    private function buildWorkerTrackerPayload(Device $device, int $sessionId): array
    {
        $meta = $device->meta ?? [];

        return [
            'name' => $device->name,
            'imei' => $device->imei,
            'latitude' => $device->latitude,
            'longitude' => $device->longitude,
            'last_seen_at' => $device->last_seen_at,
            'battery_level' => $device->battery_level,
            'panic_active' => (bool) ($meta['panic_active'] ?? false),
            'locate_url' => route('health-safety.lone-workers.sessions.locate', $sessionId),
            'acknowledge_panic_url' => route('health-safety.lone-workers.sessions.acknowledge-panic', $sessionId),
        ];
    }

    /**
     * Hydrate a canonical alert or read-only historical legacy evidence.
     */
    private function alertDetail(string $rawId, Request $request): ?array
    {
        if (str_starts_with($rawId, 'cr_')) {
            $alertQuery = ControlRoomAlert::query()
                ->where('source', 'lone_worker')
                ->with([
                    'client:id,first_name,last_name,site_id',
                    'site:id,name',
                    'clientIncident.client:id,site_id',
                ]);
            $this->siteAccess->applyAlertScope($alertQuery, $request->user(), self::SITE_BYPASS_PERMISSIONS);
            $alert = $alertQuery->find((int) substr($rawId, 3));
            if (! $alert) {
                return null;
            }
            $session = $this->verifiedSessionForCanonicalAlert($alert, $request->user());
            if (! $session) {
                return null;
            }
            $data = $this->mapCanonicalAlert($alert, $session);
            $data['_type'] = 'alert';
            $data['cr_id'] = $alert->id;
            $data['can_view_control_room'] = $request->user()?->canDo('controlRoom.viewAny') ?? false;
            $data['incident_id'] = $this->verifiedIncidentId($alert, $session);

            return $data;
        }

        if (str_starts_with($rawId, 'legacy_')) {
            $alertQuery = LoneWorkerAlert::with([
                'session.user:id,name', 'session.site:id,name', 'session.client:id,first_name,last_name',
            ])->whereHas('session', fn (Builder $sessionQuery) => $this->applySessionScope(
                $sessionQuery,
                $request->user(),
            ));
            $alert = $alertQuery->find((int) substr($rawId, 7));
            if (! $alert) {
                return null;
            }
            $data = $this->mapLegacyAlert($alert);
            $data['_type'] = 'alert';
            $data['cr_id'] = null;
            $data['can_view_control_room'] = false;
            $data['incident_id'] = null;
            $data['is_historical'] = true;

            return $data;
        }

        return null;
    }

    private function applySessionSiteFilter(Builder $query, int $siteId): Builder
    {
        return $query->where(function (Builder $siteScope) use ($siteId) {
            $siteColumn = $siteScope->qualifyColumn('site_id');
            $clientColumn = $siteScope->qualifyColumn('client_id');

            $siteScope->where($siteColumn, $siteId)
                ->orWhere(function (Builder $clientFallback) use (
                    $siteId,
                    $siteColumn,
                    $clientColumn,
                ) {
                    $clientFallback
                        ->whereNull($siteColumn)
                        ->whereNotNull($clientColumn)
                        ->whereHas('client', fn (Builder $clientQuery) => $clientQuery
                            ->where($clientQuery->qualifyColumn('site_id'), $siteId));
                })
                ->orWhere(function (Builder $shiftFallback) use (
                    $siteId,
                    $siteColumn,
                    $clientColumn,
                ) {
                    $shiftFallback
                        ->whereNull($siteColumn)
                        ->whereNull($clientColumn)
                        ->whereHas('shift', function (Builder $shiftQuery) use ($siteId) {
                            $shiftQuery
                                ->where($shiftQuery->qualifyColumn('site_id'), $siteId)
                                ->orWhere(function (Builder $shiftClientFallback) use ($siteId) {
                                    $shiftClientFallback
                                        ->whereNull($shiftClientFallback->qualifyColumn('site_id'))
                                        ->whereHas('client', fn (Builder $clientQuery) => $clientQuery
                                            ->where($clientQuery->qualifyColumn('site_id'), $siteId));
                                });
                        });
                });
        });
    }

    private function applySessionScope(Builder $query, ?User $user): Builder
    {
        $this->applySessionIntrinsicIntegrity($query);

        if ($this->siteAccess->canBypass($user, self::SITE_BYPASS_PERMISSIONS)) {
            return $query;
        }

        $siteIds = $this->siteAccess->accessibleSiteIds($user, self::SITE_BYPASS_PERMISSIONS);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $sessionScope) use ($siteIds) {
            $siteColumn = $sessionScope->qualifyColumn('site_id');
            $clientColumn = $sessionScope->qualifyColumn('client_id');
            $shiftColumn = $sessionScope->qualifyColumn('shift_id');

            // The session's own site is authoritative. Client and shift data
            // may enrich it only when their resolved site agrees.
            $sessionScope->where(function (Builder $directSite) use (
                $siteIds,
                $siteColumn,
                $clientColumn,
                $shiftColumn,
            ) {
                $directSite->whereIn($siteColumn, $siteIds)
                    ->where(function (Builder $clientAgreement) use ($clientColumn, $siteColumn) {
                        $clientAgreement->whereNull($clientColumn)
                            ->orWhereHas('client', fn (Builder $clientQuery) => $clientQuery
                                ->whereColumn('clients.site_id', $siteColumn));
                    })
                    ->where(function (Builder $shiftAgreement) use ($shiftColumn, $siteColumn) {
                        $shiftAgreement->whereNull($shiftColumn)
                            ->orWhereHas('shift', function (Builder $shiftQuery) use ($siteColumn) {
                                $shiftQuery->where(function (Builder $resolvedShiftSite) use ($siteColumn) {
                                    $resolvedShiftSite->whereColumn('shifts.site_id', $siteColumn)
                                        ->orWhere(function (Builder $shiftClientFallback) use ($siteColumn) {
                                            $shiftClientFallback->whereNull('shifts.site_id')
                                                ->whereHas('client', fn (Builder $clientQuery) => $clientQuery
                                                    ->whereColumn('clients.site_id', $siteColumn));
                                        });
                                });
                            });
                    });
            })
                // With no direct site, the client site is authoritative. A
                // linked shift must be for that same client or the row is
                // treated as conflicting legacy data and hidden.
                ->orWhere(function (Builder $clientFallback) use (
                    $siteIds,
                    $siteColumn,
                    $clientColumn,
                    $shiftColumn,
                ) {
                    $clientFallback->whereNull($siteColumn)
                        ->whereNotNull($clientColumn)
                        ->whereHas('client', fn (Builder $clientQuery) => $clientQuery
                            ->whereIn('site_id', $siteIds))
                        ->where(function (Builder $shiftAgreement) use ($shiftColumn, $clientColumn) {
                            $shiftAgreement->whereNull($shiftColumn)
                                ->orWhereHas('shift', fn (Builder $shiftQuery) => $shiftQuery
                                    ->whereColumn('shifts.client_id', $clientColumn));
                        });
                })
                // Shift provenance is the final fallback. The shift's direct
                // site wins; its client site is used only when that is absent.
                ->orWhere(function (Builder $shiftFallback) use (
                    $siteIds,
                    $siteColumn,
                    $clientColumn,
                ) {
                    $shiftFallback->whereNull($siteColumn)
                        ->whereNull($clientColumn)
                        ->whereHas('shift', function (Builder $shiftQuery) use ($siteIds) {
                            $shiftQuery->where(function (Builder $resolvedShiftSite) use ($siteIds) {
                                $resolvedShiftSite->whereIn('shifts.site_id', $siteIds)
                                    ->orWhere(function (Builder $shiftClientFallback) use ($siteIds) {
                                        $shiftClientFallback->whereNull('shifts.site_id')
                                            ->whereHas('client', fn (Builder $clientQuery) => $clientQuery
                                                ->whereIn('site_id', $siteIds));
                                    });
                            });
                        });
                });
        });
    }

    private function applySessionIntrinsicIntegrity(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        $row = "`{$table}`";
        $clientSite = "(SELECT `site_id` FROM `clients` WHERE `clients`.`id` = {$row}.`client_id` LIMIT 1)";
        $shiftSite = "(SELECT COALESCE(`lw_shift`.`site_id`, `lw_shift_client`.`site_id`) FROM `shifts` AS `lw_shift` LEFT JOIN `clients` AS `lw_shift_client` ON `lw_shift_client`.`id` = `lw_shift`.`client_id` WHERE `lw_shift`.`id` = {$row}.`shift_id` LIMIT 1)";
        $authoritativeSite = "COALESCE({$row}.`site_id`, {$clientSite}, {$shiftSite})";
        $today = now()->toDateString();

        return $query
            ->whereRaw("{$authoritativeSite} IS NOT NULL")
            ->whereRaw("EXISTS (SELECT 1 FROM `sites` AS `lw_site` WHERE `lw_site`.`id` = {$authoritativeSite} AND `lw_site`.`is_active` = 1 AND `lw_site`.`archived` = 0 AND `lw_site`.`archived_at` IS NULL)")
            ->whereRaw("EXISTS (SELECT 1 FROM `users` AS `lw_worker` JOIN `hr_employee_profiles` AS `lw_profile` ON `lw_profile`.`user_id` = `lw_worker`.`id` AND `lw_profile`.`deleted_at` IS NULL WHERE `lw_worker`.`id` = {$row}.`user_id` AND `lw_worker`.`approved_at` IS NOT NULL AND `lw_profile`.`is_active` = 1 AND (`lw_profile`.`start_date` IS NULL OR DATE(`lw_profile`.`start_date`) <= ?) AND (`lw_profile`.`end_date` IS NULL OR DATE(`lw_profile`.`end_date`) >= ?) AND (`lw_profile`.`primary_site_id` = {$authoritativeSite} OR JSON_CONTAINS(COALESCE(`lw_profile`.`secondary_site_ids`, JSON_ARRAY()), JSON_ARRAY({$authoritativeSite}))))", [$today, $today])
            ->whereRaw("({$row}.`client_id` IS NULL OR EXISTS (SELECT 1 FROM `clients` AS `lw_client` WHERE `lw_client`.`id` = {$row}.`client_id` AND `lw_client`.`site_id` = {$authoritativeSite}))")
            ->whereRaw("({$row}.`shift_id` IS NULL OR EXISTS (SELECT 1 FROM `shifts` AS `lw_linked_shift` LEFT JOIN `clients` AS `lw_linked_client` ON `lw_linked_client`.`id` = `lw_linked_shift`.`client_id` WHERE `lw_linked_shift`.`id` = {$row}.`shift_id` AND `lw_linked_shift`.`user_id` = {$row}.`user_id` AND (`lw_linked_shift`.`client_id` <=> {$row}.`client_id`) AND (`lw_linked_shift`.`client_id` IS NULL OR (`lw_linked_client`.`site_id` IS NOT NULL AND (`lw_linked_shift`.`site_id` IS NULL OR `lw_linked_shift`.`site_id` = `lw_linked_client`.`site_id`))) AND COALESCE(`lw_linked_shift`.`site_id`, `lw_linked_client`.`site_id`) = {$authoritativeSite}))");
    }

    private function lockedActor(Request $request): User
    {
        return User::query()
            ->whereKey($request->user()->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockedSessionContext(int $sessionId): LoneWorkerSession
    {
        $session = LoneWorkerSession::query()
            ->whereKey($sessionId)
            ->lockForUpdate()
            ->firstOrFail();
        $worker = User::query()
            ->whereKey($session->user_id)
            ->lockForUpdate()
            ->first();
        $client = $session->client_id
            ? Client::query()->whereKey($session->client_id)->lockForUpdate()->first()
            : null;
        $shift = $session->shift_id
            ? Shift::query()->whereKey($session->shift_id)->lockForUpdate()->first()
            : null;
        $shiftClient = $shift?->client_id
            ? ($client && (int) $client->id === (int) $shift->client_id
                ? $client
                : Client::query()->whereKey($shift->client_id)->lockForUpdate()->first())
            : null;
        $shiftWorker = $shift?->user_id
            ? ($worker && (int) $worker->id === (int) $shift->user_id
                ? $worker
                : User::query()->whereKey($shift->user_id)->lockForUpdate()->first())
            : null;

        if ($shift) {
            $shift->setRelation('client', $shiftClient);
            $shift->setRelation('staff', $shiftWorker);
        }

        $authoritativeSiteId = $session->site_id
            ?: $client?->site_id
            ?: $shift?->site_id
            ?: $shiftClient?->site_id;
        $authoritativeSite = $authoritativeSiteId
            ? Site::query()->whereKey($authoritativeSiteId)->lockForUpdate()->first()
            : null;

        $session->setRelation('user', $worker);
        $session->setRelation('client', $client);
        $session->setRelation('shift', $shift);
        $session->setRelation(
            'site',
            $session->site_id && $authoritativeSite
                && (int) $session->site_id === (int) $authoritativeSite->id
                    ? $authoritativeSite
                    : null,
        );

        return $session;
    }

    /** @param array<string, mixed> $extra */
    private function auditSessionMutation(
        string $action,
        LoneWorkerSession $session,
        User $actor,
        array $extra = [],
    ): void {
        AuditLogger::logOrFail($action, $session, array_merge([
            'actor_id' => $actor->id,
            'worker_user_id' => $session->user_id,
            'site_id' => $this->resolvedSessionSiteId($session),
            'shift_id' => $session->shift_id,
            'client_id' => $session->client_id,
        ], $extra));
    }

    private function assertCanAccessSession(
        User $user,
        LoneWorkerSession $session,
        bool $allowOwnerWithoutSiteAssignment = false,
    ): void {
        $session->loadMissing([
            'user:id,approved_at',
            'site:id,is_active,archived,archived_at',
            'client:id,site_id',
            'shift:id,site_id,client_id,user_id',
            'shift.client:id,site_id',
        ]);

        abort_unless(
            $session->user,
            403,
            UserSiteAccessService::DEFAULT_MESSAGE,
        );

        $clientSiteId = null;
        if ($session->client_id !== null) {
            abort_unless(
                $session->client
                    && $session->client->site_id !== null,
                403,
                UserSiteAccessService::DEFAULT_MESSAGE,
            );
            $clientSiteId = (int) $session->client->site_id;
        }

        $shiftSiteId = null;
        $shiftClientSiteId = null;
        if ($session->shift_id !== null) {
            abort_unless(
                $session->shift
                    && $this->nullablePositiveId($session->shift->user_id) === $this->nullablePositiveId($session->user_id)
                    && $this->nullablePositiveId($session->shift->client_id) === $this->nullablePositiveId($session->client_id),
                403,
                UserSiteAccessService::DEFAULT_MESSAGE,
            );

            if ($session->shift->client_id !== null) {
                abort_unless(
                    $session->shift->client
                        && $session->shift->client->site_id !== null,
                    403,
                    UserSiteAccessService::DEFAULT_MESSAGE,
                );
                $shiftClientSiteId = (int) $session->shift->client->site_id;
                abort_if(
                    $session->shift->site_id !== null
                        && (int) $session->shift->site_id !== $shiftClientSiteId,
                    403,
                    UserSiteAccessService::DEFAULT_MESSAGE,
                );
            }

            $shiftSiteId = $session->shift->site_id !== null
                ? (int) $session->shift->site_id
                : $shiftClientSiteId;
            abort_if($shiftSiteId === null, 403, UserSiteAccessService::DEFAULT_MESSAGE);

        }

        $siteIds = collect([
            $session->site_id,
            $clientSiteId,
            $session->shift?->site_id,
            $shiftClientSiteId,
        ])
            ->filter(fn ($siteId) => $siteId !== null)
            ->map(fn ($siteId) => (int) $siteId)
            ->unique()
            ->values();

        abort_unless($siteIds->count() === 1, 403, UserSiteAccessService::DEFAULT_MESSAGE);
        $siteId = (int) $siteIds->first();
        abort_unless(
            Site::query()
                ->whereKey($siteId)
                ->active()
                ->notArchived()
                ->whereNull('archived_at')
                ->exists(),
            403,
            UserSiteAccessService::DEFAULT_MESSAGE,
        );
        abort_unless(
            in_array($siteId, $this->siteAccess->accessibleSiteIds($session->user), true),
            403,
            UserSiteAccessService::DEFAULT_MESSAGE,
        );

        if ($this->siteAccess->canBypass($user, self::SITE_BYPASS_PERMISSIONS)) {
            return;
        }

        if ($allowOwnerWithoutSiteAssignment) {
            abort_unless(
                (int) $session->user_id === (int) $user->id,
                403,
                UserSiteAccessService::DEFAULT_MESSAGE,
            );

            return;
        }

        $this->siteAccess->assertCanAccessSiteId(
            $user,
            $siteId,
            self::SITE_BYPASS_PERMISSIONS,
        );
    }

    /** @return array{ControlRoomAlert, LoneWorkerSession} */
    private function lockedCanonicalAlertContext(int $alertId, User $actor): array
    {
        $alertQuery = ControlRoomAlert::query()
            ->whereKey($alertId)
            ->where('source', 'lone_worker');
        $this->siteAccess->applyAlertScope($alertQuery, $actor, self::SITE_BYPASS_PERMISSIONS);
        $alert = $alertQuery->lockForUpdate()->firstOrFail();
        $sessionId = $this->nullablePositiveId(
            data_get($alert->context, 'normalized_data.lone_worker_session_id'),
        );
        abort_if($sessionId === null, 409, 'Canonical lone-worker provenance is incomplete.');

        $session = $this->lockedSessionContext($sessionId);
        $this->assertCanAccessSession($actor, $session);
        abort_unless(
            $this->canonicalAlertMatchesSession($alert, $session)
                && $this->canonicalOperationalTypeMatches($alert),
            409,
            'Canonical lone-worker provenance does not match the session.',
        );

        $matchesQuery = ControlRoomAlert::query()
            ->where('source', 'lone_worker')
            ->where('alert_type', $alert->alert_type)
            ->where('context->normalized_data->lone_worker_session_id', $session->id);
        $this->siteAccess->applyAlertScope($matchesQuery, $actor, self::SITE_BYPASS_PERMISSIONS);
        $matches = $matchesQuery->lockForUpdate()->get()->filter(
            fn (ControlRoomAlert $candidate): bool => $this->canonicalAlertMatchesSession($candidate, $session)
                && $this->canonicalOperationalTypeMatches($candidate),
        );
        abort_unless($matches->count() === 1, 409, 'This response does not own one canonical alert.');

        return [$alert, $session];
    }

    private function verifiedSessionForCanonicalAlert(
        ControlRoomAlert $alert,
        User $user,
    ): ?LoneWorkerSession {
        $sessionId = $this->nullablePositiveId(
            data_get($alert->context, 'normalized_data.lone_worker_session_id'),
        );
        if ($sessionId === null) {
            return null;
        }

        $query = LoneWorkerSession::query()->with($this->canonicalSessionSecurityRelations());
        $this->applySessionScope($query, $user);
        $session = $query->find($sessionId);

        return $session && $this->canonicalAlertMatchesSession($alert, $session)
            ? $session
            : null;
    }

    private function canonicalAlertMatchesSession(
        ControlRoomAlert $alert,
        LoneWorkerSession $session,
    ): bool {
        if ($alert->source !== 'lone_worker') {
            return false;
        }

        $context = data_get($alert->context, 'normalized_data', []);
        if (! is_array($context)
            || $this->nullablePositiveId($context['lone_worker_session_id'] ?? null) !== (int) $session->id) {
            return false;
        }

        $session->loadMissing($this->canonicalSessionSecurityRelations());
        $sessionSiteId = $this->resolvedSessionSiteId($session);
        if ($sessionSiteId === null
            || $this->nullablePositiveId($alert->site_id) !== $sessionSiteId
            || ! $this->nullableIdMatches($alert->client_id, $this->nullablePositiveId($session->client_id))) {
            return false;
        }

        $expectedContextIds = [
            'worker_user_id' => $this->nullablePositiveId($session->user_id),
            'site_id' => $sessionSiteId,
            'client_id' => $this->nullablePositiveId($session->client_id),
        ];
        foreach ($expectedContextIds as $key => $expectedId) {
            if (! array_key_exists($key, $context)) {
                continue;
            }

            $matches = $key === 'client_id'
                ? $this->nullableIdMatches($context[$key], $expectedId)
                : $this->nullablePositiveId($context[$key]) === $expectedId;
            if (! $matches) {
                return false;
            }
        }

        return true;
    }

    private function canonicalOperationalTypeMatches(ControlRoomAlert $alert): bool
    {
        $signalType = data_get($alert->context, 'normalized_data.signal_type')
            ?? data_get($alert->context, 'signal_type_code');
        if (! is_string($signalType)) {
            return false;
        }

        $expected = LoneWorkerSignalService::canonicalAlertType($signalType);

        return $expected !== null && hash_equals($expected, (string) $alert->alert_type);
    }

    private function verifiedIncidentId(
        ControlRoomAlert $alert,
        LoneWorkerSession $session,
    ): ?int {
        $incident = $alert->clientIncident;
        if (! $incident
            || (int) $incident->control_room_alert_id !== (int) $alert->id
            || ! $this->nullableIdMatches($incident->client_id, $this->nullablePositiveId($session->client_id))) {
            return null;
        }

        $incidentSiteId = $this->nullablePositiveId($incident->site_id)
            ?? $this->nullablePositiveId($incident->client?->site_id);
        if ($incidentSiteId === null || $incidentSiteId !== $this->resolvedSessionSiteId($session)) {
            return null;
        }

        return (int) $incident->id;
    }

    private function resolvedSessionSiteId(LoneWorkerSession $session): ?int
    {
        $siteId = $session->site_id
            ?: $session->client?->site_id
            ?: $session->shift?->site_id
            ?: $session->shift?->client?->site_id;

        return $this->nullablePositiveId($siteId);
    }

    private function nullableIdMatches(mixed $actual, ?int $expected): bool
    {
        return $expected === null
            ? $actual === null
            : $this->nullablePositiveId($actual) === $expected;
    }

    private function nullablePositiveId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value)
            || preg_match('/^[1-9][0-9]*$/D', $value) !== 1
            || (string) (int) $value !== $value) {
            return null;
        }

        return (int) $value;
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
            'id' => 'legacy_'.$alert->id,
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
                    'name' => trim($alert->session->client->first_name.' '.$alert->session->client->last_name),
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
            'status' => 'historical',
            'source' => 'legacy_history',
            'notes' => $alert->resolution_notes ?? null,
            'historical_label' => 'Historical record — read only',
        ];
    }

    /**
     * Map a canonical ControlRoomAlert to the shared alert display shape (source of truth).
     */
    /**
     * @param  Collection<int, ControlRoomAlert>  $alerts
     * @return Collection<int, LoneWorkerSession>
     */
    private function scopedSessionsForCanonicalAlerts(Collection $alerts, User $user): Collection
    {
        $sessionIds = $alerts
            ->map(fn (ControlRoomAlert $alert): ?int => $this->nullablePositiveId(
                data_get($alert->context, 'normalized_data.lone_worker_session_id'),
            ))
            ->filter()
            ->unique()
            ->values();
        if ($sessionIds->isEmpty()) {
            return new Collection;
        }

        $query = LoneWorkerSession::query()
            ->with($this->canonicalSessionSecurityRelations())
            ->whereIn('id', $sessionIds);
        $this->applySessionScope($query, $user);

        return $query->get()->keyBy('id');
    }

    /** @return array<int, string> */
    private function canonicalSessionSecurityRelations(): array
    {
        return [
            'user:id,name,approved_at',
            'site:id,name,is_active,archived,archived_at',
            'client:id,first_name,last_name,site_id',
            'shift:id,site_id,client_id,user_id,starts_at,ends_at,status,is_on_call',
            'shift.client:id,first_name,last_name,site_id',
        ];
    }

    private function mapCanonicalAlert(
        ControlRoomAlert $alert,
        ?LoneWorkerSession $candidate,
    ): array {
        $session = $candidate && $this->canonicalAlertMatchesSession($alert, $candidate)
            ? $candidate
            : null;

        return [
            'id' => 'cr_'.$alert->id,
            'session' => $session ? $this->mapSession($session) : null,
            'type' => $alert->alert_type,
            'triggered_at' => $alert->triggered_at,
            'status' => $this->mapCrStatus($alert->status),
            'source' => 'control_room',
            'notes' => $alert->notes,
        ];
    }
}
