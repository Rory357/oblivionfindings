<?php

namespace App\Http\Controllers\HealthSafety;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Sites\SiteGeocodingController;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\LoneWorkerAlert;
use App\Models\LoneWorkerSession;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\AuditLogger;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\LoneWorkerCoverageService;
use App\Services\HealthSafety\LoneWorkerSessionAccessService;
use App\Services\HealthSafety\LoneWorkerSessionScope;
use App\Services\HealthSafety\LoneWorkerSessionService;
use App\Services\HealthSafety\LoneWorkerSignalService;
use App\Services\HealthSafety\ShiftGpsAccessService;
use App\Services\Queclink\LocateNowService;
use App\Services\UserSiteAccessService;
use App\Support\HealthSafety\LoneWorkerSessionPresenter;
use App\Support\HealthSafety\LoneWorkerSessionReturn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
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

    /** This adapter reports transport failures without changing the shared Site endpoint. */
    public function searchAddress(Request $request): JsonResponse
    {
        $authorize = fn () => app(LoneWorkerSessionAccessService::class)->withCurrentViewer($request->user(), function ($current) {
            abort_unless($current->canDo('hazards.view'), 403);
        });
        $authorize();
        try {
            $result = app(SiteGeocodingController::class)->search($request, true);
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable) {
            return response()->json(['message' => 'Address search is temporarily unavailable. Try again.', 'retryable' => true], 503)
                ->header('Cache-Control', 'private, no-store');
        }
        $authorize();

        return $result->header('Cache-Control', 'private, no-store');
    }

    private function clientForViewer(?int $clientId, User $viewer): ?array
    {
        if ($clientId === null) {
            return null;
        }

        return app(LoneWorkerSessionAccessService::class)->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($clientId) {
            $client = $reads->query(Client::query())->whereIn('site_id', $sites)->find($clientId);

            return $client && app(ClientPolicy::class)->viewFromCurrentEvidence($current, $client, $reads)
                ? ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name)] : null;
        });
    }

    /**
     * The coordinator watch-tower: live register, alerts, hero KPIs, detail.
     */
    public function index(Request $request): Response|RedirectResponse|JsonResponse
    {
        return app(LoneWorkerSessionAccessService::class)->withCurrentViewer($request->user(), function ($current, $reads, $sites) use ($request) {
            abort_unless($current->canDo('hazards.view'), 403);

            return $this->currentIndex($request, $current, $reads, $sites);
        });
    }

    private function currentIndex(Request $request, User $user, CurrentAuthorizationReads $reads, array $sites): Response|RedirectResponse|JsonResponse
    {
        if ($request->has('session')) {
            abort_unless($this->nullablePositiveId($request->input('session')) !== null, 404);
        }
        if ($request->has('alert')) {
            $rawAlert = $request->input('alert');
            abort_unless(is_string($rawAlert) && preg_match('/^(?:cr|legacy)_([1-9][0-9]*)$/D', $rawAlert, $alertId) === 1 && $this->nullablePositiveId($alertId[1]) !== null, 404);
        }
        $request->validate(['site_id' => ['nullable', 'integer', 'min:1'], 'user_id' => ['nullable', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:200']]);
        if ($request->has('session') && ! $request->expectsJson() && ! $request->headers->has('X-Inertia')) {
            $selected = $reads->query(app(LoneWorkerSessionScope::class)->applyForSites(LoneWorkerSession::query(), $sites))->findOrFail($request->integer('session'));
            app(LoneWorkerSessionAccessService::class)->assertRecord($selected, $user, $sites);

            return redirect(LoneWorkerSessionReturn::href($selected->id, LoneWorkerSessionReturn::fromRegister($request->except(['session', 'return_to']))));
        }
        if ($request->has('alert') && ! $request->expectsJson() && ! $request->headers->has('X-Inertia') && is_string($request->input('alert')) && str_starts_with($request->input('alert'), 'cr_')) {
            $raw = $request->input('alert');
            abort_unless(preg_match('/^cr_([1-9][0-9]*)$/D', $raw, $match) === 1 && $this->nullablePositiveId($match[1]) !== null, 404);
            $alertQuery = ControlRoomAlert::query()->where('source', 'lone_worker');
            $this->siteAccess->applyAlertScope($alertQuery, $user, self::SITE_BYPASS_PERMISSIONS);
            app(ControlRoomAlertAccessService::class)->applyReadableScope($alertQuery, $user);
            $selectedAlert = $reads->query($alertQuery)->findOrFail((int) $match[1]);
            $source = $this->verifiedSessionForCanonicalAlert($selectedAlert, $user);
            abort_unless($source, 404);
            $back = LoneWorkerSessionReturn::href($source->id, LoneWorkerSessionReturn::fromRegister($request->except(['session', 'alert', 'return_to'])), 'response');
            $destination = app(ControlRoomAlertAccessService::class)->destinationFor($selectedAlert, $user, $back);
            abort_unless(is_string($destination['href'] ?? null), 404);

            return redirect($destination['href']);
        }
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
            default => now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay()->utc(),
        };

        $readableClientIds = $filters['q'] ? $reads->query(Client::query())->whereIn('site_id', $sites)->get()
            ->filter(fn ($client) => app(ClientPolicy::class)->viewFromCurrentEvidence($user, $client, $reads))->modelKeys() : [];

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
                ->when($filters['status'], fn ($q) => $filters['status'] === 'overdue' ? $q->checkInOverdue() : $q->where('status', $filters['status']))
                ->when($filters['user_id'], fn ($q) => $q->where('user_id', $filters['user_id']))
                ->when($boundary, fn ($q) => $q->where(function ($w) use ($boundary) {
                    $w->where('started_at', '>=', $boundary)
                        ->orWhereIn('status', self::LIVE_STATUSES);
                }))
                ->when($filters['q'], fn ($q) => $q->where(function ($w) use ($filters, $readableClientIds) {
                    $term = '%'.$filters['q'].'%';
                    $w->whereHas('user', fn ($u) => $u->where('name', 'like', $term))
                        ->orWhereHas('client', fn ($c) => $c->whereIn('id', $readableClientIds)
                            ->where(fn ($name) => $name->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)))
                        ->orWhere('activity_description', 'like', $term)
                        ->orWhere('location', 'like', $term);
                }))
                // Most-urgent first: emergency → overdue → active → completed, then newest.
                ->orderByRaw("FIELD(status, 'emergency', 'overdue', 'active', 'completed')")
                ->orderByDesc('started_at');
            $this->applySessionScope($sessionsQuery, $user);
            $sessions = $reads->query($sessionsQuery)->paginate(25)
                ->withQueryString()
                ->through(fn ($s) => app(LoneWorkerSessionPresenter::class)->row($s, $user, LoneWorkerSessionReturn::fromRegister($request->except(['session', 'return_to']))));
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
                ->when($filters['q'], fn ($q) => $q->where(function ($w) use ($filters, $readableClientIds) {
                    $term = '%'.$filters['q'].'%';
                    $w->where('notes', 'like', $term)
                        ->orWhereHas('client', fn ($c) => $c->whereIn('id', $readableClientIds)
                            ->where(fn ($name) => $name->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)))
                        ->orWhereHas('site', fn ($s) => $s->where('name', 'like', $term));
                }))
                ->orderByDesc('triggered_at');
            $this->siteAccess->applyAlertScope($alertsQuery, $user, self::SITE_BYPASS_PERMISSIONS);
            app(ControlRoomAlertAccessService::class)->applyReadableScope($alertsQuery, $user);
            $alerts = $reads->query($alertsQuery)->paginate(25)
                ->withQueryString();
            $sessionsById = $this->scopedSessionsForCanonicalAlerts(
                $alerts->getCollection(),
                $user,
            );
            $alerts->through(function (ControlRoomAlert $alert) use ($sessionsById, $user): array {
                $sessionId = $this->nullablePositiveId(
                    data_get($alert->context, 'normalized_data.lone_worker_session_id'),
                );

                return $this->mapCanonicalAlert(
                    $alert,
                    $sessionId === null ? null : $sessionsById->get($sessionId),
                    $user,
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
        $pickers = app(LoneWorkerSessionPresenter::class)->pickers($user);

        $props = [
            'tab' => $tab,
            'sessions' => $sessions,
            'alerts' => $alerts,
            'detail' => $detail,
            'lone_worker_confirmation' => app(LoneWorkerSessionService::class)->confirmationForViewer($user, $request->session()->get('lone_worker_confirmation')),
            'tabCounts' => $this->tabCounts($user),
            'hero' => $this->heroBlock($shiftData['unmonitored_lone'], $user, $reads, $sites),
            'coverage' => app(LoneWorkerCoverageService::class)->summary($user, $requestedSiteId),
            'pickers' => $pickers,
            'filters' => $filters,
            'options' => [
                'sites' => $pickers['sites'],
                'staff' => $pickers['workers'],
                'clients' => $pickers['clients'],
                'shifts' => $shiftData['shifts'],
            ],
            'can' => [
                'manage' => $user->canDo('hazards.view') && $user->canDo('hazards.manage'),
                'view' => $user->canDo('hazards.view'),
                'view_control_room' => $user->canDo('controlRoom.alerts.view') || $user->canDo('controlRoom.alerts.manage'),
            ],
        ];

        return $request->expectsJson() ? response()->json($props) : Inertia::render('health-safety/lone-workers/index', $props);
    }

    /**
     * Start a new lone worker session (coordinator, on a worker's behalf).
     */
    public function startSession(Request $request): RedirectResponse
    {
        $row = app(LoneWorkerSessionService::class)->create($request->user(), $request->all());

        return $this->confirmedSession($request, $row, 'Session started. No check-in has been received yet.');
    }

    /**
     * Extend / edit an active or overdue session (new endpoint for the redesign).
     * Extending an overdue session clears it back to active.
     */
    public function updateSession(Request $request, LoneWorkerSession $session): RedirectResponse
    {
        $row = app(LoneWorkerSessionService::class)->update($session, $request->user(), $request->all());

        return $this->confirmedSession($request, $row, 'Session updated.');
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
        $row = app(LoneWorkerSessionService::class)->checkIn($session, $request->user(), $request->all());

        return $this->confirmedSession($request, $row, $row->status === 'emergency' ? 'Emergency request recorded. Review delivery and the response separately.' : 'Check-in recorded.');
    }

    /**
     * End a lone worker session.
     */
    public function endSession(Request $request, LoneWorkerSession $session): RedirectResponse
    {
        $row = app(LoneWorkerSessionService::class)->end($session, $request->user(), $request->all());

        return $this->confirmedSession($request, $row, 'Session ended.');
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
        $confirmed = app(LoneWorkerSessionService::class)->remove($session, $request->user(), $request->all());

        return redirect()->route('health-safety.lone-workers.index')->with('success', 'Session removed from the register; its safety record is retained.')->with('lone_worker_confirmation', ['id' => (int) $confirmed->id, 'lock_version' => (int) $confirmed->lock_version, 'idempotency_key' => $request->input('idempotency_key')]);
    }

    /**
     * Trigger emergency for an active session.
     */
    public function triggerEmergency(Request $request, LoneWorkerSession $session): RedirectResponse
    {
        $row = app(LoneWorkerSessionService::class)->panic($session, $request->user(), $request->all());

        return $this->confirmedSession($request, $row, 'Emergency request recorded. Review delivery and the response separately.');
    }

    /**
     * Acknowledge the canonical operational alert. Replays are idempotent.
     */
    public function acknowledgeAlert(Request $request, ControlRoomAlert $alert, ControlRoomAlertLifecycleService $lifecycle): RedirectResponse
    {
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        $row = DB::transaction(function () use ($request, $alert, $lifecycle, $validated) {
            $actor = $this->lockedActor($request);
            abort_unless($actor->canDo('hazards.manage') && $actor->canDo('controlRoom.alerts.manage'), 403);
            [$canonical, $session] = $this->lockedCanonicalAlertContext((int) $alert->id, $actor);
            abort_unless(app(LoneWorkerSessionAccessService::class)->canViewCanonicalAlert($session, $canonical, $actor), 403);

            return app(LoneWorkerSessionService::class)->external($session, $actor, [...$request->all(), 'alert_id' => $canonical->id], 'response', 'responseAcknowledged', function () use ($canonical, $actor, $lifecycle, $validated) {
                if ($canonical->status === ControlRoomAlert::STATUS_OPEN) {
                    $lifecycle->acknowledge($canonical, $actor, $validated['notes'] ?? null);
                } else {
                    abort_unless(in_array($canonical->status, [ControlRoomAlert::STATUS_ACK, ControlRoomAlert::STATUS_TRIAGING, ControlRoomAlert::STATUS_CONFIRMED, ControlRoomAlert::STATUS_RESOLVED, ControlRoomAlert::STATUS_CLOSED], true), 409);
                }

                return ['alert_id' => $canonical->id, 'note' => $validated['notes'] ?? null];
            });
        }, 3);

        return $this->confirmedSession($request, $row, 'Operational response acknowledged. This does not record worker safety or a check-in.');
    }

    /**
     * Resolve the canonical operational alert. Replays are idempotent.
     */
    public function resolveAlert(Request $request, ControlRoomAlert $alert, ControlRoomAlertLifecycleService $lifecycle): RedirectResponse
    {
        $validated = $request->validate(['resolution_notes' => ['required', 'string', 'max:2000']]);
        abort_if(trim($validated['resolution_notes']) === '', 422);
        $row = DB::transaction(function () use ($request, $alert, $validated, $lifecycle) {
            $actor = $this->lockedActor($request);
            abort_unless($actor->canDo('hazards.manage') && $actor->canDo('controlRoom.alerts.manage'), 403);
            [$canonical, $session] = $this->lockedCanonicalAlertContext((int) $alert->id, $actor);
            abort_unless(app(LoneWorkerSessionAccessService::class)->canViewCanonicalAlert($session, $canonical, $actor), 403);

            return app(LoneWorkerSessionService::class)->external($session, $actor, [...$request->all(), 'alert_id' => $canonical->id], 'response', 'responseResolved', function ($row) use ($canonical, $actor, $validated, $lifecycle) {
                if (! in_array($canonical->status, [ControlRoomAlert::STATUS_RESOLVED, ControlRoomAlert::STATUS_CLOSED], true)) {
                    if ($canonical->status === ControlRoomAlert::STATUS_OPEN) {
                        $canonical = $lifecycle->acknowledge($canonical, $actor);
                    }
                    if ($canonical->status === ControlRoomAlert::STATUS_ACK) {
                        $canonical = $lifecycle->startTriage($canonical, $actor);
                    }
                    if (in_array($canonical->status, [ControlRoomAlert::STATUS_TRIAGING, ControlRoomAlert::STATUS_CONFIRMED], true)) {
                        $canonical = $lifecycle->resolve($canonical, $actor, $validated['resolution_notes'], 'resolved_in_health_safety');
                    }
                    abort_unless($canonical->status === ControlRoomAlert::STATUS_RESOLVED, 409);
                }
                $this->resumeResolvedEmergencySession($canonical, $row, $actor);

                return ['alert_id' => $canonical->id, 'note' => $validated['resolution_notes']];
            });
        }, 3);

        return $this->confirmedSession($request, $row, 'Operational response resolved. Session monitoring uses the last actual checkpoint.');
    }

    /**
     * Hand the worker's paired GPS tracker to the governed Device command plane.
     */
    public function locateNow(Request $request, LoneWorkerSession $session, LocateNowService $locateNow): RedirectResponse
    {
        $row = DB::transaction(function () use ($request, $session, $locateNow) {
            $actor = $this->lockedActor($request);
            abort_unless($actor->canDo('hazards.manage') && $actor->canDo('assets.telemetry.view'), 403);
            [$lockedSession, $device] = $this->lockedTrackerSessionContext((int) $session->id);
            $this->assertCanAccessSession($actor, $lockedSession);
            if (! $device) {
                throw ValidationException::withMessages(['tracker' => 'This worker has no available paired tracker.']);
            }

            return app(LoneWorkerSessionService::class)->external($lockedSession, $actor, $request->all(), 'locate', 'locationManagementOpened', fn () => ['device_id' => $device->id, 'management_href' => $locateNow->managementUrlForDevice($device)]);
        }, 3);

        return redirect()->to($row->getRelation('commandResult')['management_href'])->with('success', 'Review the governed location refresh, confirm your identity and record the reason before dispatch.');
    }

    /**
     * Acknowledge a tracker panic: clear the device panic flag and ack the open
     * Control Room lone-worker alerts for this session.
     */
    public function acknowledgePanic(Request $request, LoneWorkerSession $session, ControlRoomAlertLifecycleService $lifecycle): RedirectResponse
    {
        $row = DB::transaction(function () use ($request, $session, $lifecycle) {
            $actor = $this->lockedActor($request);
            abort_unless($actor->canDo('hazards.manage') && $actor->canDo('controlRoom.alerts.manage') && $actor->canDo('assets.telemetry.view'), 403);
            [$lockedSession, $device] = $this->lockedTrackerSessionContext((int) $session->id);
            $this->assertCanAccessSession($actor, $lockedSession);

            return app(LoneWorkerSessionService::class)->external($lockedSession, $actor, $request->all(), 'acknowledge_panic', 'trackerPanicAcknowledged', function () use ($lockedSession, $device, $actor, $lifecycle) {
                if ($device) {
                    $meta = $device->meta ?? [];
                    $meta['panic_active'] = false;
                    $meta['panic_acknowledged_at'] = now()->toISOString();
                    $meta['panic_acknowledged_by'] = $actor->id;
                    $device->forceFill(['meta' => $meta])->save();
                }
                $query = ControlRoomAlert::query()->where('source', 'lone_worker')->where('alert_type', LoneWorkerSignalService::canonicalAlertType(LoneWorkerSignalService::TYPE_EMERGENCY))
                    ->where('context->normalized_data->lone_worker_session_id', $lockedSession->id)->where('status', ControlRoomAlert::STATUS_OPEN);
                $this->siteAccess->applyAlertScope($query, $actor, self::SITE_BYPASS_PERMISSIONS);
                $alerts = $query->lockForUpdate()->get()->filter(fn ($alert) => app(LoneWorkerSessionAccessService::class)->alertMatchesSession($alert, $lockedSession));
                abort_if($alerts->count() > 1, 409, 'This emergency has contradictory canonical alerts.');
                if ($alerts->isNotEmpty()) {
                    $lifecycle->acknowledge($alerts->first(), $actor);
                }

                return ['device_id' => $device?->id, 'alert_id' => $alerts->first()?->id];
            });
        }, 3);

        return $this->confirmedSession($request, $row, 'Tracker panic acknowledged. This does not confirm worker safety or end the session.');
    }

    /**
     * Tab badge counts (application-wide totals, never filter-scoped).
     */
    public function options(Request $request): JsonResponse
    {
        return app(LoneWorkerSessionAccessService::class)->withCurrentViewer($request->user(), function ($current) {
            $pickers = app(LoneWorkerSessionPresenter::class)->pickers($current);

            return response()->json(['pickers' => $pickers, 'can' => ['start' => $current->canDo('hazards.view') && $current->canDo('hazards.manage'),
                'start_own' => collect($pickers['shifts'])->contains(fn ($shift) => (int) $shift['user_id'] === (int) $current->id)]]);
        });
    }

    public function startOwnSession(Request $request): RedirectResponse
    {
        $row = app(LoneWorkerSessionService::class)->create($request->user(), $request->all(), true);

        return $this->confirmedSession($request, $row, 'Session started. No check-in has been received yet.');
    }

    public function endOwnSession(Request $request, LoneWorkerSession $session): RedirectResponse
    {
        $row = app(LoneWorkerSessionService::class)->end($session, $request->user(), $request->all(), true);

        return $this->confirmedSession($request, $row, 'Session ended.');
    }

    public function show(Request $request, LoneWorkerSession $session): Response|JsonResponse
    {
        $access = app(LoneWorkerSessionAccessService::class);
        $row = $access->recordForViewer($session, $request->user());
        $returnTo = LoneWorkerSessionReturn::sanitize($request->input('return_to'));
        $presenter = app(LoneWorkerSessionPresenter::class);
        $props = ['lone_worker_confirmation' => app(LoneWorkerSessionService::class)->confirmationForViewer($request->user(), $request->session()->get('lone_worker_confirmation')), 'detail' => $presenter->detail($row, $request->user(), $returnTo), 'pickers' => $presenter->pickers($request->user(), $row),
            'return_to' => $returnTo, 'section' => in_array($request->input('section'), ['overview', 'check-ins', 'response', 'location', 'history', 'connections'], true) ? $request->input('section') : 'overview'];

        return $request->expectsJson() ? response()->json($props) : Inertia::render('health-safety/lone-workers/show', $props);
    }

    private function confirmedSession(Request $request, LoneWorkerSession $row, string $message): RedirectResponse
    {
        $section = in_array($request->input('section'), ['overview', 'check-ins', 'response', 'location', 'history', 'connections'], true) ? $request->input('section') : null;
        $href = LoneWorkerSessionReturn::href((int) $row->id, $request->input('return_to'), $section);
        if (in_array($request->input('action'), ['edit', 'check-in', 'end', 'panic', 'locate', 'acknowledge-panic'], true)) {
            $href .= (str_contains($href, '?') ? '&' : '?').'action='.rawurlencode($request->input('action'));
        }

        return redirect($href)->with('success', $message)->with('lone_worker_confirmation', ['id' => $row->id, 'lock_version' => $row->lock_version, 'idempotency_key' => $request->input('idempotency_key')]);
    }

    private function tabCounts(User $user): array
    {
        $sessionsQuery = LoneWorkerSession::whereIn('status', self::LIVE_STATUSES)->whereNull('ended_at');
        $this->applySessionScope($sessionsQuery, $user);
        $alertsQuery = ControlRoomAlert::where('source', 'lone_worker')->actionable();
        $this->siteAccess->applyAlertScope($alertsQuery, $user, self::SITE_BYPASS_PERMISSIONS);
        app(ControlRoomAlertAccessService::class)->applyReadableScope($alertsQuery, $user);

        return [
            'sessions' => $sessionsQuery->count(),
            'alerts' => $alertsQuery->count(),
        ];
    }

    /**
     * Hero KPI clusters + NZ compliance badge counts/booleans.
     * Counts & booleans only — the page formats the copy (never pre-format here).
     */
    private function heroBlock(int $loneShiftsUnmonitored, User $user, CurrentAuthorizationReads $reads, array $sites): array
    {
        // Load active sessions once; derive the check-in freshness counts in PHP via the
        // model's Carbon helper (UTC-correct) rather than SQL NOW(), which can sit in a
        // different timezone than the stored UTC datetimes and skew the comparison.
        $activeRowsQuery = LoneWorkerSession::where('status', 'active')->whereNull('ended_at');
        app(LoneWorkerSessionScope::class)->applyForSites($activeRowsQuery, $sites);
        $activeRows = $reads->query($activeRowsQuery)->get([
            'id', 'status', 'ended_at', 'last_check_in_at', 'started_at', 'check_in_interval_minutes',
        ]);
        $overdueQuery = LoneWorkerSession::query()->checkInOverdue();
        app(LoneWorkerSessionScope::class)->applyForSites($overdueQuery, $sites);
        $overdueIds = $reads->query($overdueQuery)->pluck('id');
        $overdue = $overdueIds->count();
        $emergencyQuery = LoneWorkerSession::where('status', 'emergency')->whereNull('ended_at');
        app(LoneWorkerSessionScope::class)->applyForSites($emergencyQuery, $sites);
        $emergency = $reads->query($emergencyQuery)->count();

        $endingSoonQuery = LoneWorkerSession::where('status', 'active')->whereNull('ended_at')
            ->whereBetween('expected_end_at', [now(), now()->addHour()]);
        app(LoneWorkerSessionScope::class)->applyForSites($endingSoonQuery, $sites);
        $endingSoon = $reads->query($endingSoonQuery)->count();

        // Active sessions already past their check-in window (not yet flipped by the 5-min job).
        $checks = $activeRows->mapWithKeys(fn ($row) => [$row->id => $reads->query($row->latestReceivedCheckInQuery())->first()]);
        $noRecentCheckIn = $activeRows->filter(fn ($row) => $row->isCheckInOverdue($row->checkInDueAt($checks->get($row->id))))->count();
        // The live buckets are exclusive: elapsed active deadlines are counted once as overdue.
        $active = $activeRows->whereNotIn('id', $overdueIds)->count();
        // Actual recent check-in records; startup scheduling evidence never counts as receipt.
        $checkedIn = $activeRows->filter(fn ($row) => $reads->query($row->checkIns()->getQuery())->where('checked_in_at', '<=', now())->where('checked_in_at', '>', now()->subMinutes($row->check_in_interval_minutes))->where(fn ($receipt) => $receipt->whereNull('received_at')->orWhere('received_at', '<=', now()))->exists())->count();

        $alertBase = ControlRoomAlert::where('source', 'lone_worker');
        $this->siteAccess->applyAlertScope($alertBase, $user, self::SITE_BYPASS_PERMISSIONS);
        app(ControlRoomAlertAccessService::class)->applyReadableScope($alertBase, $user);
        $alertsToday = $reads->query(clone $alertBase)
            ->where('triggered_at', '>=', now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay()->utc())
            ->count();
        $awaitingAck = $reads->query(clone $alertBase)->where('status', 'open')->count();
        $unresolved = $reads->query(clone $alertBase)->actionable()->count();

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
        return app(LoneWorkerCoverageService::class)->monitorableShifts($user);
    }

    /**
     * Shared session display shape (list rows + detail).
     */
    private function mapSession(LoneWorkerSession $source, User $viewer): array
    {
        return app(LoneWorkerSessionAccessService::class)->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($source) {
            $s = $reads->query(LoneWorkerSession::query())->findOrFail($source->id);
            app(LoneWorkerSessionAccessService::class)->assertRecord($s, $current, $sites);
            $check = $reads->query($s->latestReceivedCheckInQuery())->first();

            return ['id' => $s->id, 'user' => $s->user ? ['id' => $s->user->id, 'name' => $s->user->name] : null,
                'site' => $s->site ? ['id' => $s->site->id, 'name' => $s->site->name] : null,
                'client' => $this->clientForViewer($s->client_id, $current),
                'shift_id' => $s->shift_id, 'started_at' => $s->started_at, 'expected_end_at' => $s->expected_end_at, 'ended_at' => $s->ended_at,
                'last_check_in_at' => $check?->checked_in_at, 'status' => $s->status, 'activity_description' => $s->activity_description,
                'check_in_interval_minutes' => $s->check_in_interval_minutes, 'location' => $s->location, 'lock_version' => $s->lock_version,
                'href' => LoneWorkerSessionReturn::href($s->id), 'is_check_in_overdue' => $s->isCheckInOverdue($s->checkInDueAt($check))];
        });
    }

    /**
     * Hydrate a session for the detail modal (plan + check-in timeline + alerts + shift).
     */
    private function sessionDetail(int $id, User $user): ?array
    {
        $row = app(LoneWorkerSessionAccessService::class)->readableQuery($user)->find($id);

        return $row ? ['_type' => 'session', ...app(LoneWorkerSessionPresenter::class)->detail($row, $user)] : null;
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

        if ((int) $session->emergency_episode_number > 0) {
            $episode = $session->emergencyEpisodes()->where('episode_number', $session->emergency_episode_number)->lockForUpdate()->first();
            if (! $episode || $episode->status !== 'delivered' || (int) $episode->control_room_alert_id !== (int) $alert->id) {
                return;
            }
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
            app(ControlRoomAlertAccessService::class)->applyReadableScope($alertQuery, $request->user());
            $alert = $alertQuery->find((int) substr($rawId, 3));
            if (! $alert) {
                return null;
            }
            $session = $this->verifiedSessionForCanonicalAlert($alert, $request->user());
            if (! $session) {
                return null;
            }
            $data = $this->mapCanonicalAlert($alert, $session, $request->user());
            $data['_type'] = 'alert';
            $data['cr_id'] = $alert->id;
            $data['can_view_control_room'] = app(LoneWorkerSessionAccessService::class)->canViewCanonicalAlert($session, $alert, $request->user());
            $incidentId = $this->verifiedIncidentId($alert, $session);
            $data['incident_id'] = app(LoneWorkerSessionAccessService::class)->readableIncidentForAlert($session, $alert, $request->user())?->id;

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
            $data = $this->mapLegacyAlert($alert, $request->user());
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
        return app(LoneWorkerSessionScope::class)->apply($query, $user);
    }

    private function lockedActor(Request $request): User
    {
        return app(LoneWorkerSessionAccessService::class)->withCurrentViewer($request->user(), fn ($current) => $current);
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

    private function assertCanAccessSession(User $user, LoneWorkerSession $session, bool $allowOwnerWithoutSiteAssignment = false): void
    {
        app(LoneWorkerSessionAccessService::class)
            ->assertCanAccessSession($user, $session, $allowOwnerWithoutSiteAssignment);
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

    private function canonicalAlertMatchesSession(ControlRoomAlert $alert, LoneWorkerSession $session): bool
    {
        return app(LoneWorkerSessionAccessService::class)->alertMatchesSession($alert, $session);
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
    private function mapLegacyAlert(LoneWorkerAlert $alert, User $viewer): array
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
                'client' => $this->clientForViewer($alert->session->client_id, $viewer),
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

        return CurrentAuthorizationReads::within(fn ($reads) => $reads->query($query)->get()->keyBy('id'));
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
        User $viewer,
    ): array {
        $session = $candidate && $this->canonicalAlertMatchesSession($alert, $candidate)
            ? $candidate
            : null;

        return [
            'id' => 'cr_'.$alert->id,
            'session' => $session ? $this->mapSession($session, $viewer) : null,
            'type' => $alert->alert_type,
            'triggered_at' => $alert->triggered_at,
            'status' => $this->mapCrStatus($alert->status),
            'canonical_status' => $alert->status,
            'source' => 'control_room',
            'notes' => $session ? $alert->notes : null,
        ];
    }
}
