<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Enums\AttendanceTimesheetSyncOutcome;
use App\Domain\Hr\Exceptions\AttendanceClockOutBlockedException;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Services\AttendanceService;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Operations\HandoverPresenter;
use App\Services\ShiftHandoverService;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AttendanceController extends Controller
{
    public function __construct(
        protected AttendanceService $attendanceService,
        protected ShiftHandoverService $handoverService,
        protected HandoverPresenter $handoverPresenter,
        protected UserSiteAccessService $siteAccess,
    ) {}

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canViewAttendance($auth), 403);

        $request->validate([
            'week' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $canManageAny = $auth->canDo('timesheets.manageAny');
        $requestedTargetUserId = $canManageAny
            ? (int) ($request->integer('user_id') ?: $auth->id)
            : (int) $auth->id;
        $targetUser = $requestedTargetUserId === (int) $auth->id
            ? $auth
            : $this->siteAccess->applyStaffScope(
                User::query()->whereKey($requestedTargetUserId),
                $auth,
                UserSiteAccessService::ATTENDANCE_SITE_BYPASS_PERMISSIONS,
            )->first();
        abort_unless($targetUser, 404);
        $targetUserId = (int) $targetUser->id;

        $scopeTargetAttendance = function (Builder $query) use ($auth, $targetUserId): Builder {
            $query->where('user_id', $targetUserId);

            if ($targetUserId !== (int) $auth->id) {
                $this->siteAccess->applyAttendanceSessionScope(
                    $query,
                    $auth,
                    UserSiteAccessService::ATTENDANCE_SITE_BYPASS_PERMISSIONS,
                );
            }

            return $query;
        };

        // Week is the unit of navigation for the sessions list (Mon–Sun, hero
        // week stepper). Compute the window in the worker timezone, then query
        // the UTC-stored clock_in_at column with UTC bounds.
        $tz = config('app.worker_timezone') ?: config('app.timezone', 'UTC');
        $weekStart = $request->filled('week')
            ? Carbon::parse($request->string('week'), $tz)->startOfWeek(Carbon::MONDAY)
            : Carbon::now($tz)->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
        $workerNow = Carbon::now($tz);
        $todayStartUtc = $workerNow->copy()->startOfDay()->utc();
        $tomorrowStartUtc = $workerNow->copy()->addDay()->startOfDay()->utc();
        $currentWeekStartUtc = $workerNow->copy()->startOfWeek(Carbon::MONDAY)->utc();
        $nextWeekStartUtc = $workerNow->copy()->startOfWeek(Carbon::MONDAY)->addWeek()->utc();

        $sessions = $scopeTargetAttendance(HrAttendanceSession::query()
            ->with(['timesheet:id,attendance_session_id,status']))
            ->whereBetween('clock_in_at', [$weekStart->copy()->utc(), $weekEnd->copy()->utc()])
            ->orderByDesc('clock_in_at')
            ->limit(100)
            ->get()
            ->map(fn (HrAttendanceSession $session) => [
                'id' => $session->id,
                'clock_in_at' => optional($session->clock_in_at)->toIso8601String(),
                'clock_out_at' => optional($session->clock_out_at)->toIso8601String(),
                'break_minutes' => (int) $session->break_minutes,
                'status' => $session->status,
                'source' => $session->source,
                'location' => $session->location,
                'worked_hours' => $session->worked_hours,
                'timesheet_id' => $session->timesheet?->id,
                'timesheet_status' => $session->timesheet?->status,
            ])->values();

        $totalSessions = $scopeTargetAttendance(HrAttendanceSession::query())->count();

        $openSession = $scopeTargetAttendance(HrAttendanceSession::query()
            ->with([
                'shift:id,client_id,starts_at,ends_at,location',
                'shift.client:id,first_name,last_name',
                'timesheet:id,attendance_session_id,status',
                'breakEvents' => fn ($query) => $query->orderBy('started_at'),
            ]))
            ->open()
            ->latest('clock_in_at')
            ->first();

        $eligibleShifts = $targetUser
            ? $this->attendanceService->eligibleShiftsForUser($targetUser, now(), $auth)
            : collect();
        $activeShift = $eligibleShifts->count() === 1 ? $eligibleShifts->first() : null;

        $staff = $canManageAny
            ? $this->siteAccess->applyStaffScope(
                User::query(),
                $auth,
                UserSiteAccessService::ATTENDANCE_SITE_BYPASS_PERMISSIONS,
            )->orderBy('name')->get(['id', 'name', 'email'])
            : collect();

        $todayHours = $scopeTargetAttendance(HrAttendanceSession::query())
            ->where('clock_in_at', '>=', $todayStartUtc)
            ->where('clock_in_at', '<', $tomorrowStartUtc)
            ->get()
            ->sum(fn (HrAttendanceSession $session) => $session->worked_hours);

        $weekHours = $scopeTargetAttendance(HrAttendanceSession::query())
            ->where('clock_in_at', '>=', $currentWeekStartUtc)
            ->where('clock_in_at', '<', $nextWeekStartUtc)
            ->get()
            ->sum(fn (HrAttendanceSession $session) => $session->worked_hours);

        // Managers get a live "who is on the clock now" board for canonical
        // attendance Sites they can access. Sessions open for 16h+ are flagged
        // as likely missed clock-outs.
        $staleCutoff = now()->subHours(16);
        $onClockNow = collect();
        if ($canManageAny) {
            $onClockNowQuery = HrAttendanceSession::query()
                ->with(['user:id,name', 'shift:id,client_id,location,ends_at'])
                ->whereIn('user_id', User::query()->staff()->select('id'));
            $this->siteAccess->applyAttendanceSessionScope(
                $onClockNowQuery,
                $auth,
                UserSiteAccessService::ATTENDANCE_SITE_BYPASS_PERMISSIONS,
            );
            $onClockNow = $onClockNowQuery
                ->open()
                ->latest('clock_in_at')
                ->limit(50)
                ->get()
                ->map(fn (HrAttendanceSession $session) => [
                    'id' => $session->id,
                    'user_id' => $session->user_id,
                    'user_name' => $session->user?->name,
                    'clock_in_at' => optional($session->clock_in_at)->toIso8601String(),
                    'shift_id' => $session->shift_id,
                    'shift_location' => $session->shift?->location,
                    'shift_ends_at' => optional($session->shift?->ends_at)->toIso8601String(),
                    'is_stale' => $session->clock_in_at !== null && $session->clock_in_at->lt($staleCutoff),
                ])->values();
        }

        // Handovers involving the VIEWED user (both directions) feed the
        // Handovers tab — same row shape as the Shift Handovers workspace.
        // Workers always view themselves; a manager filtering to a staff
        // member sees that person's handovers (page is person-centric).
        // Action flags (can_acknowledge/can_edit) stay relative to the
        // signed-in user, as does the `incoming` treatment only when viewing
        // yourself.
        $canAccessHandovers = $this->handoverService->canAccessWorkflow($auth);
        $handovers = $canAccessHandovers
            ? ShiftHandover::query()
                ->tap(fn ($query) => $this->siteAccess->applyHandoverScope(
                    $query,
                    $auth,
                    UserSiteAccessService::ATTENDANCE_SITE_BYPASS_PERMISSIONS,
                ))
                ->whereIn('status', [ShiftHandoverService::STATUS_SUBMITTED, ShiftHandoverService::STATUS_ACKNOWLEDGED])
                ->where(function ($involving) use ($targetUserId) {
                    $involving->where('outgoing_staff_id', $targetUserId)
                        ->orWhere('incoming_staff_id', $targetUserId)
                        ->orWhereHas('outgoingShift', fn ($shift) => $shift->where('user_id', $targetUserId))
                        ->orWhereHas('incomingShift', fn ($shift) => $shift->where('user_id', $targetUserId));
                })
                ->with($this->handoverPresenter->mapEagerLoads())
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(function (ShiftHandover $handover) use ($auth, $targetUserId) {
                    $mapped = $this->handoverPresenter->mapHandover(
                        $handover,
                        $auth,
                        $auth->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
                    );
                    // `incoming` is deliberately live acknowledgement
                    // authority, not the immutable submit-time recipient.
                    $mapped['incoming'] = (int) ($handover->incomingShift?->user_id ?? 0) === (int) $targetUserId;
                    $mapped['submitted_recipient'] = (int) $handover->incoming_staff_id === (int) $targetUserId;

                    return $mapped;
                })->values()
            : collect();

        return Inertia::render('attendance/index', [
            'sessions' => $sessions,
            'totalSessions' => $totalSessions,
            'openSession' => $openSession ? [
                'id' => $openSession->id,
                'clock_in_at' => optional($openSession->clock_in_at)->toIso8601String(),
                'shift_id' => $openSession->shift_id,
                'shift_starts_at' => optional($openSession->shift?->starts_at)->toIso8601String(),
                'shift_ends_at' => optional($openSession->shift?->ends_at)->toIso8601String(),
                'shift_location' => $openSession->shift?->location,
                'client_name' => trim((string) ($openSession->shift?->client?->first_name.' '.$openSession->shift?->client?->last_name)) ?: null,
                'client_id' => $openSession->shift?->client_id,
                'timesheet_id' => $openSession->timesheet?->id,
                'on_break' => $openSession->break_started_at !== null,
                'break_started_at' => optional($openSession->break_started_at)->toIso8601String(),
                'break_minutes' => (int) $openSession->break_minutes,
                'breaks' => $openSession->breakEvents->map(fn ($event) => [
                    'id' => $event->id,
                    'started_at' => optional($event->started_at)->toIso8601String(),
                    'ended_at' => optional($event->ended_at)->toIso8601String(),
                    'minutes' => $event->minutes !== null ? (int) $event->minutes : null,
                ])->values(),
            ] : null,
            'activeShift' => $activeShift ? [
                'id' => $activeShift->id,
                'starts_at' => optional($activeShift->starts_at)->toIso8601String(),
                'ends_at' => optional($activeShift->ends_at)->toIso8601String(),
                'status' => $activeShift->status,
                'location' => $activeShift->location,
            ] : null,
            'eligibleShifts' => $eligibleShifts->map(fn (Shift $shift) => [
                'id' => $shift->id,
                'starts_at' => optional($shift->starts_at)->toIso8601String(),
                'ends_at' => optional($shift->ends_at)->toIso8601String(),
                'status' => $shift->status,
                'location' => $shift->location,
                'client_name' => trim((string) ($shift->client?->first_name.' '.$shift->client?->last_name)),
            ])->values(),
            'staff' => $staff,
            'filters' => [
                'user_id' => $canManageAny ? $targetUserId : null,
                'week' => $weekStart->toDateString(),
            ],
            'todayHours' => round((float) $todayHours, 2),
            'weekHours' => round((float) $weekHours, 2),
            'onClockNow' => $onClockNow,
            'handovers' => $handovers,
            'canManageAny' => $canManageAny,
            'canClock' => $this->canClock($auth),
            'canCreateHandovers' => $this->canCreateHandovers($auth),
            'currentUser' => ['id' => $auth->id, 'name' => $auth->name],
            // Heavy wizard catalogue — loaded on demand the first time the
            // Handover wizard opens (router.reload only:['catalogue']).
            'catalogue' => Inertia::optional(fn () => $canAccessHandovers
                ? $this->handoverPresenter->catalogue($auth)
                : [
                    'clients' => [],
                    'staff' => [],
                    'staffBySite' => [],
                    'sites' => [],
                    'serviceContexts' => [],
                    'shifts' => [],
                    'controlledWitnessesBySite' => [],
                    'capabilities' => [
                        'view_controlled' => false,
                        'record_controlled' => false,
                        'manage_any_shifts' => false,
                    ],
                ]),
        ]);
    }

    /**
     * Correct a session's clock-out (the "fix a missed clock-out" wizard).
     * Managers may correct sessions within their canonical attendance Site
     * scope; workers may correct only their own. The required reason lands in
     * the audit log and the linked timesheet is recalculated (submitted ones
     * return to draft).
     */
    public function correctSession(Request $request, $session)
    {
        $auth = $request->user();
        abort_unless(
            $auth && ($auth->canDo('timesheets.manageAny') || $this->canClock($auth)),
            403,
        );

        $sessionId = filter_var($session, FILTER_VALIDATE_INT);
        abort_unless(is_int($sessionId) && $sessionId > 0, 404);

        // Resolve before target-sensitive validation so missing, foreign-user,
        // and foreign-Site identifiers share the same concealed response. The
        // command repeats this authorization under the aggregate lock.
        $this->attendanceService->resolveCorrectableSession($auth, $sessionId);

        $data = $request->validate([
            'clock_out_at' => ['required', 'date'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'reason' => ['required', 'string', 'max:1000', 'not_regex:/^\s*$/'],
        ]);

        try {
            $corrected = $this->attendanceService->correctSession(
                $auth,
                $sessionId,
                Carbon::parse($data['clock_out_at']),
                (int) ($data['break_minutes'] ?? 0),
                trim($data['reason']),
            );
        } catch (\LogicException $exception) {
            return redirect()->back()->withErrors(['correct_session' => $exception->getMessage()]);
        }

        $ownSession = (int) $corrected->user_id === (int) $auth->id;
        $name = $ownSession ? 'Session' : "Session for {$corrected->user?->name}";
        if ($corrected->timesheetSyncOutcome()->wasSynced() && $corrected->timesheet) {
            return redirect()->back()->with('success', "{$name} corrected. Timesheet #{$corrected->timesheet->id} recalculated.");
        }
        if ($corrected->timesheetSyncOutcome() === AttendanceTimesheetSyncOutcome::SkippedFollowUp) {
            return redirect()->back()->with('success', "{$name} corrected. Payroll follow-up is required; no Timesheet was changed.");
        }

        return redirect()->back()->with('success', "{$name} corrected. The reason was recorded in the audit log.");
    }

    public function clockIn(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canClock($auth), 403);

        // Resolve the direct object through the worker's canonical attendance
        // scope before validating the remaining payload. Missing, foreign and
        // malformed Shift identities therefore share one concealed response.
        $shift = $this->attendanceService->resolveSelfClockInShift(
            $auth,
            $request->input('shift_id'),
        );

        $data = $request->validate([
            'shift_id' => ['nullable', 'integer', 'min:1'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['shift_id'] = $shift?->id;

        try {
            $session = $this->attendanceService->clockIn($auth, $data);
        } catch (\LogicException $exception) {
            return redirect()->back()->withErrors(['clock_in' => $exception->getMessage()]);
        }

        return redirect()->back()->with('success', 'Clocked in successfully.');
    }

    public function clockOut(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canClock($auth), 403);

        // Resolve the governing session before any nested Client/task
        // validation so a foreign session cannot be used as an existence
        // oracle for objects elsewhere in the application.
        $session = $this->attendanceService->resolveSelfAttendanceSession(
            $auth,
            $request->input('session_id'),
        );

        $data = $request->validate([
            'session_id' => ['nullable', 'integer', 'min:1'],
            'clock_out_at' => ['nullable', 'date'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'force' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'required_if:force,true', 'string', 'max:1000'],
            'handover' => ['nullable', 'array'],
            'handover.meds_completed' => ['required_with:handover', 'boolean'],
            'handover.shift_rating' => ['nullable', 'string', 'in:calm,mixed,challenging'],
            'handover.handover_notes' => ['nullable', 'string', 'max:2000'],
            'handover.follow_up_needed' => ['required_with:handover', 'boolean'],
            'handover.tasks_pending' => ['nullable', 'array', 'max:20'],
            'handover.tasks_pending.*' => ['string', 'max:255'],
            'task_updates' => ['nullable', 'array'],
            'task_updates.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'task_updates.*.is_completed' => ['required', 'boolean'],
        ]);
        $data['session_id'] = $session?->id;

        try {
            $closed = $this->attendanceService->clockOut($auth, $session, $data);
        } catch (AttendanceClockOutBlockedException $exception) {
            if ($request->header('X-Inertia')) {
                return redirect()->back()
                    ->withErrors(['clock_out' => $exception->getMessage()])
                    ->with('clock_out_blockers', $exception->blockers());
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'blockers' => $exception->blockers(),
                ], 422);
            }

            return redirect()->to(route('my-day').'#clock')
                ->withErrors(['clock_out' => $exception->getMessage()])
                ->with('clock_out_blockers', $exception->blockers());
        } catch (\LogicException $exception) {
            return redirect()->back()->withErrors(['clock_out' => $exception->getMessage()]);
        }

        if ($closed->timesheetSyncOutcome()->wasSynced() && $closed->timesheet) {
            return redirect()->back()->with('success', "Clocked out. Draft timesheet #{$closed->timesheet->id} synced.");
        }
        if ($closed->timesheetSyncOutcome() === AttendanceTimesheetSyncOutcome::SkippedFollowUp) {
            return redirect()->back()->with('success', 'Clocked out. Payroll follow-up is required; no Timesheet was changed.');
        }

        return redirect()->back()->with('success', 'Clocked out successfully.');
    }

    /**
     * Manager force-close of someone else's open session, from the
     * "On the clock now" board. Gated by the same permission as the board.
     */
    public function endSession(Request $request, HrAttendanceSession $session)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('timesheets.manageAny'), 403);

        $session = $this->attendanceService->resolveManageableSession($auth, (int) $session->id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000', 'not_regex:/^\s*$/'],
        ]);

        if ($session->status !== 'open' || $session->clock_out_at) {
            return redirect()->back()->with('info', 'This session was already closed.');
        }

        try {
            $closed = $this->attendanceService->adminEndSession($auth, $session, trim($data['reason']));
        } catch (\LogicException $exception) {
            return redirect()->back()->withErrors(['end_session' => $exception->getMessage()]);
        }

        $name = $closed->user?->name ?? 'staff member';
        if ($closed->timesheetSyncOutcome()->wasSynced() && $closed->timesheet) {
            return redirect()->back()->with('success', "Session ended for {$name}. Draft timesheet #{$closed->timesheet->id} synced.");
        }
        if ($closed->timesheetSyncOutcome() === AttendanceTimesheetSyncOutcome::SkippedFollowUp) {
            return redirect()->back()->with('success', "Session ended for {$name}. Payroll follow-up is required; no Timesheet was changed.");
        }

        return redirect()->back()->with('success', "Session ended for {$name}.");
    }

    public function startBreak(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canClock($auth), 403);

        $session = $this->attendanceService->resolveSelfAttendanceSession(
            $auth,
            $request->input('session_id'),
        );

        $data = $request->validate([
            'session_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $data['session_id'] = $session?->id;

        try {
            $this->attendanceService->startBreak($auth, $session, $data);
        } catch (\LogicException $exception) {
            return redirect()->back()->withErrors(['break' => $exception->getMessage()]);
        }

        return redirect()->back()->with('success', 'Break started.');
    }

    public function endBreak(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canClock($auth), 403);

        $session = $this->attendanceService->resolveSelfAttendanceSession(
            $auth,
            $request->input('session_id'),
        );

        $data = $request->validate([
            'session_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $data['session_id'] = $session?->id;

        try {
            $this->attendanceService->endBreak($auth, $session, $data);
        } catch (\LogicException $exception) {
            return redirect()->back()->withErrors(['break' => $exception->getMessage()]);
        }

        return redirect()->back()->with('success', 'Break ended.');
    }

    protected function canClock(?User $auth): bool
    {
        return (bool) $auth && (
            $auth->canDo('timesheets.create')
            || $auth->canDo('shifts.viewAssigned')
            || $auth->canDo('shifts.update')
            || $auth->canDo('shifts.manageAny')
        );
    }

    protected function canViewAttendance(?User $auth): bool
    {
        return (bool) $auth && (
            $auth->canDo('timesheets.viewAssigned')
            || $auth->canDo('timesheets.viewAny')
            || $this->canClock($auth)
        );
    }

    /**
     * Mirrors Operations\HandoverController::canCreateHandovers — gates the
     * "New handover" wizard entry points on this page (the wizard posts to the
     * operations route, so the same permission set must hold).
     */
    protected function canCreateHandovers(?User $auth): bool
    {
        return (bool) $auth && (
            $auth->canDo('handovers.create')
            || $auth->canDo('shifts.update')
            || $auth->canDo('shifts.manageAny')
        );
    }

    /**
     * PR 11 — Handover write on clock-out.
     *
     * Small structured handover captured at shift end from the frontline
     * clock card. It remains a draft until the outgoing worker reviews and
     * selects the exact bounded incoming Shift in the handover workflow.
     */
    public function submitHandover(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canClock($auth), 403);

        $shiftId = filter_var($request->input('shift_id'), FILTER_VALIDATE_INT);
        abort_unless(is_int($shiftId) && $shiftId > 0, 404);
        $shift = $this->handoverService->writableOutgoingShift($auth, $shiftId);

        $data = $request->validate([
            'shift_id' => ['required', 'integer', 'min:1'],
            'meds_completed' => ['required', 'boolean'],
            'shift_rating' => ['nullable', 'string', 'in:calm,mixed,challenging'],
            'handover_notes' => ['nullable', 'string', 'max:2000'],
            'follow_up_needed' => ['required', 'boolean'],
        ]);

        $notes = trim((string) ($data['handover_notes'] ?? ''));
        if ($notes === '') {
            $notes = $data['meds_completed']
                ? 'No specific items to flag for the next shift.'
                : 'Medications were not fully completed — please review on arrival.';
        }

        $payload = [
            'handover_notes' => $notes,
            'client_mood' => $data['shift_rating'] ?? null,
            'follow_up_items' => $data['follow_up_needed']
                ? [[
                    'label' => 'Follow-up flagged by outgoing worker',
                    'priority' => 'medium',
                ]]
                : null,
            'submit' => false,
        ];

        if (
            $auth->canDo('medications.controlled.view')
            && $auth->canDo('medications.controlled.record')
        ) {
            $payload['medications_due'] = $data['meds_completed']
                ? null
                : [[
                    'label' => ShiftHandoverService::OUTSTANDING_MEDICATION_DUE_LABEL,
                    'severity' => 'high',
                ]];
        }

        try {
            $this->handoverService->save($shift, $auth, $payload);
        } catch (ValidationException $exception) {
            return redirect()->back()->withErrors($exception->errors());
        } catch (\DomainException) {
            return redirect()->back()->withErrors([
                'handover' => 'The handover draft could not be saved. Review it and try again.',
            ]);
        }

        return redirect()->back()->with('success', 'Handover draft saved. Assign the incoming shift before submitting.');
    }

    /**
     * PR 11 — Acknowledge the handover read prompt shown at clock-in.
     *
     * Thin wrapper over `ShiftHandoverService::acknowledge` that reuses the
     * existing permission/invariant logic but is reachable from the frontline
     * `/my-day` handover-read card without depending on the operations-module
     * route.
     */
    public function acknowledgeHandover(Request $request, $handover)
    {
        $auth = $request->user();
        abort_unless($this->canClock($auth), 403);
        abort_unless(
            $auth->canDo('shifts.update') || $auth->canDo('shifts.viewAssigned'),
            403,
        );

        $handoverId = filter_var($handover, FILTER_VALIDATE_INT);
        abort_unless(is_int($handoverId) && $handoverId > 0, 404);
        $handover = ShiftHandover::query()
            ->tap(fn (Builder $query) => $this->siteAccess->applyHandoverScope(
                $query,
                $auth,
                MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
            ))
            ->whereKey($handoverId)
            ->where('status', ShiftHandoverService::STATUS_SUBMITTED)
            ->whereNotNull('incoming_shift_id')
            ->whereHas('incomingShift', fn (Builder $query) => $query
                ->where('user_id', $auth->id)
                ->whereIn('status', ['scheduled', 'in_progress']))
            ->with(['incomingShift:id,user_id,status', 'client:id,site_id'])
            ->firstOrFail();

        try {
            $this->handoverService->acknowledge($handover, $auth);
        } catch (ValidationException $exception) {
            // Context drift (client, Site, service context, or handoff window)
            // is a direct-object miss on this frontline route, not validation
            // detail the requester may use to probe a retained handover.
            if (array_key_exists('incoming_shift_id', $exception->errors())) {
                abort(404);
            }

            return redirect()->back()->withErrors($exception->errors());
        } catch (\DomainException) {
            return redirect()->back()->withErrors([
                'handover' => 'The handover could not be acknowledged. Refresh it and try again.',
            ]);
        }

        return redirect()->back()->with('success', 'Handover marked as read.');
    }
}
