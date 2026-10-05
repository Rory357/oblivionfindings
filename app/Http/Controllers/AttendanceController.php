<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Enums\AttendanceTimesheetSyncOutcome;
use App\Domain\Hr\Exceptions\AttendanceClockOutBlockedException;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Services\AttendanceService;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\User;
use App\Services\HandoverWorkerNotes;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Operations\HandoverPresenter;
use App\Services\ShiftHandoverService;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

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
            'page' => ['nullable', 'integer', 'min:1'],
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
        $selectedWeekStartUtc = $weekStart->copy()->utc();
        $selectedWeekEndUtc = $weekStart->copy()->addWeek()->utc();

        $selectedSessions = $scopeTargetAttendance(HrAttendanceSession::query())
            ->where('clock_in_at', '>=', $selectedWeekStartUtc)
            ->where('clock_in_at', '<', $selectedWeekEndUtc);
        $selectedTotal = (clone $selectedSessions)->count();
        $selectedClosed = (clone $selectedSessions)->where('status', 'closed')->count();
        $selectedOpen = (clone $selectedSessions)->open()->count();
        $sessionPage = (clone $selectedSessions)->with(['timesheet:id,attendance_session_id,status'])
            ->orderByDesc('clock_in_at')->orderByDesc('id')->paginate(100)->withQueryString();
        $sessions = $sessionPage->getCollection()
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
            ->where('status', 'closed')
            ->where('clock_in_at', '>=', $todayStartUtc)
            ->where('clock_in_at', '<', $tomorrowStartUtc)
            ->select(['id', 'clock_in_at', 'clock_out_at', 'break_minutes'])->lazyById(100)
            ->sum(fn (HrAttendanceSession $session) => $session->worked_hours);

        $weekHours = (clone $selectedSessions)->where('status', 'closed')
            ->select(['id', 'clock_in_at', 'clock_out_at', 'break_minutes'])->lazyById(100)
            ->sum(fn (HrAttendanceSession $session) => $session->worked_hours);

        // Managers get a live "who is on the clock now" board for canonical
        // attendance Sites they can access. Sessions open for 16h+ are flagged
        // as likely missed clock-outs.
        $staleCutoff = now()->subHours(16);
        $onClockNow = collect();
        $onClockTotal = null;
        $staleTotal = null;
        if ($canManageAny) {
            $onClockNowQuery = HrAttendanceSession::query()
                ->with(['user:id,name', 'shift:id,client_id,location,ends_at'])
                ->whereIn('user_id', User::query()->staff()->select('id'));
            $this->siteAccess->applyAttendanceSessionScope(
                $onClockNowQuery,
                $auth,
                UserSiteAccessService::ATTENDANCE_SITE_BYPASS_PERMISSIONS,
            );
            $onClockNowQuery->open();
            $onClockTotal = (clone $onClockNowQuery)->count();
            $staleTotal = (clone $onClockNowQuery)->where('clock_in_at', '<', $staleCutoff)->count();
            $onClockNow = $onClockNowQuery->latest('clock_in_at')
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
        $handoverQuery = $canAccessHandovers
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
            : null;
        $handoverTotal = $handoverQuery ? (clone $handoverQuery)->count() : null;
        $handovers = $handoverQuery
            ? $handoverQuery->orderByDesc('created_at')
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
            'sessionPagination' => [
                'current_page' => $sessionPage->currentPage(), 'last_page' => $sessionPage->lastPage(),
                'per_page' => $sessionPage->perPage(), 'total' => $sessionPage->total(),
                'from' => $sessionPage->firstItem(), 'to' => $sessionPage->lastItem(),
                'links' => $sessionPage->linkCollection()->all(),
            ],
            'totalSessions' => $totalSessions,
            'summary' => [
                'user_id' => $targetUserId, 'sessions_total' => $selectedTotal,
                'closed_sessions' => $selectedClosed, 'open_sessions' => $selectedOpen,
                'closed_hours' => round((float) $weekHours, 2),
                'on_clock_now_total' => $onClockTotal, 'stale_on_clock_total' => $staleTotal,
            ],
            'evidence' => [
                'state' => $selectedTotal === 0 ? 'no_records' : 'recorded',
                'basis' => 'closed_sessions_clocked_in_during_selected_week',
                'scope' => 'viewed_person_with_existing_attendance_access',
                'period_start' => $selectedWeekStartUtc->toIso8601String(),
                'period_end_exclusive' => $selectedWeekEndUtc->toIso8601String(),
                'timezone' => $tz, 'checked_at' => now()->toIso8601String(),
                'today' => $workerNow->toDateString(),
                'today_period_start' => $todayStartUtc->toIso8601String(),
                'today_period_end_exclusive' => $tomorrowStartUtc->toIso8601String(),
                'total_sessions_basis' => 'all_recorded_sessions_for_viewed_person',
                'on_clock_basis' => $canManageAny ? 'current_open_sessions_in_permitted_sites' : 'restricted',
                'handovers_basis' => $canAccessHandovers ? 'all_recorded_submitted_or_acknowledged_handovers_for_viewed_person' : 'restricted',
            ],
            'lists' => [
                'sessions' => ['total' => $selectedTotal, 'shown' => $sessions->count(), 'limit' => 100,
                    'truncated' => $selectedTotal > $sessions->count()],
                'onClockNow' => ['total' => $onClockTotal, 'shown' => $onClockNow->count(), 'limit' => 50,
                    'truncated' => $onClockTotal !== null && $onClockTotal > $onClockNow->count()],
                'handovers' => ['total' => $handoverTotal, 'shown' => $handovers->count(), 'limit' => 50,
                    'truncated' => $handoverTotal !== null && $handoverTotal > $handovers->count()],
            ],
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
        $request->session()->forget('attendance_result');
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

        $receiptCanCommit = $this->isOutsideTransaction();
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
            return $this->attendanceSuccess("{$name} corrected. Timesheet #{$corrected->timesheet->id} recalculated.", 'correct', $corrected, $receiptCanCommit);
        }
        if ($corrected->timesheetSyncOutcome() === AttendanceTimesheetSyncOutcome::SkippedFollowUp) {
            return $this->attendanceSuccess("{$name} corrected. Payroll follow-up is required; no Timesheet was changed.", 'correct', $corrected, $receiptCanCommit);
        }

        return $this->attendanceSuccess("{$name} corrected. The reason was recorded in the audit log.", 'correct', $corrected, $receiptCanCommit);
    }

    public function clockIn(Request $request)
    {
        $request->session()->forget('attendance_result');
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

        $receiptCanCommit = $this->isOutsideTransaction();
        try {
            $session = $this->attendanceService->clockIn($auth, $data);
        } catch (\LogicException $exception) {
            return redirect()->back()->withErrors(['clock_in' => $exception->getMessage()]);
        }

        return $this->attendanceSuccess('Clocked in successfully.', 'clock_in', $session, $receiptCanCommit);
    }

    public function clockOut(Request $request)
    {
        $request->session()->forget('attendance_result');
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
            ...HandoverWorkerNotes::rules('handover.'),
            'handover.expected_version' => ['nullable', 'integer', 'min:0'],
            'handover.follow_up_needed' => ['required_with:handover', 'boolean'],
            'handover.tasks_pending' => ['nullable', 'array', 'max:20'],
            'handover.tasks_pending.*' => ['string', 'max:255'],
            'task_updates' => ['nullable', 'array'],
            'task_updates.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'task_updates.*.is_completed' => ['required', 'boolean'],
            'task_updates.*.expected_version' => ['sometimes', 'integer', 'min:0'],
        ]);
        $data['session_id'] = $session?->id;

        $receiptCanCommit = $this->isOutsideTransaction();
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
            return $this->attendanceSuccess("Clocked out. Draft timesheet #{$closed->timesheet->id} synced.", 'clock_out', $closed, $receiptCanCommit);
        }
        if ($closed->timesheetSyncOutcome() === AttendanceTimesheetSyncOutcome::SkippedFollowUp) {
            return $this->attendanceSuccess('Clocked out. Payroll follow-up is required; no Timesheet was changed.', 'clock_out', $closed, $receiptCanCommit);
        }

        return $this->attendanceSuccess('Clocked out successfully.', 'clock_out', $closed, $receiptCanCommit);
    }

    /**
     * Manager force-close of someone else's open session, from the
     * "On the clock now" board. Gated by the same permission as the board.
     */
    public function endSession(Request $request, HrAttendanceSession $session)
    {
        $request->session()->forget('attendance_result');
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('timesheets.manageAny'), 403);

        $session = $this->attendanceService->resolveManageableSession($auth, (int) $session->id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000', 'not_regex:/^\s*$/'],
        ]);

        if ($session->status !== 'open' || $session->clock_out_at) {
            return redirect()->back()->with('info', 'This session was already closed.');
        }

        $receiptCanCommit = $this->isOutsideTransaction();
        try {
            $closed = $this->attendanceService->adminEndSession($auth, $session, trim($data['reason']));
        } catch (\LogicException $exception) {
            return redirect()->back()->withErrors(['end_session' => $exception->getMessage()]);
        }

        $name = $closed->user?->name ?? 'staff member';
        if ($closed->timesheetSyncOutcome()->wasSynced() && $closed->timesheet) {
            return $this->attendanceSuccess("Session ended for {$name}. Draft timesheet #{$closed->timesheet->id} synced.", 'end_session', $closed, $receiptCanCommit);
        }
        if ($closed->timesheetSyncOutcome() === AttendanceTimesheetSyncOutcome::SkippedFollowUp) {
            return $this->attendanceSuccess("Session ended for {$name}. Payroll follow-up is required; no Timesheet was changed.", 'end_session', $closed, $receiptCanCommit);
        }

        return $this->attendanceSuccess("Session ended for {$name}.", 'end_session', $closed, $receiptCanCommit);
    }

    private function isOutsideTransaction(): bool
    {
        $connection = DB::connection();

        return $connection->transactionLevel() === 0 && ! $connection->getPdo()->inTransaction();
    }

    private function attendanceSuccess(string $message, string $action, HrAttendanceSession $session, bool $receiptCanCommit): RedirectResponse
    {
        $response = redirect()->back()->with('success', $message);

        try {
            // A nested transaction or a testing callback is not a physical root
            // commit. Project only the writer's returned evidence, never reload.
            if (! $receiptCanCommit || ! $this->isOutsideTransaction()) {
                return $response;
            }
            if (! in_array($session->status, ['open', 'closed'], true) || ! $session->clock_in_at
                || ($action === 'clock_out' && $session->handoverOutcome() === null)) {
                return $response;
            }

            $synced = $session->timesheetSyncOutcome()->wasSynced();
            $timesheet = $synced && $session->relationLoaded('timesheet') ? $session->getRelation('timesheet') : null;
            if ($synced && ! $timesheet) {
                return $response;
            }

            return $response->with('attendance_result', [
                'action' => $action,
                'session_id' => (int) $session->id,
                'shift_id' => $session->shift_id === null ? null : (int) $session->shift_id,
                'session_status' => $session->status,
                'clock_in_at' => $session->clock_in_at->toIso8601String(),
                'clock_out_at' => $session->clock_out_at?->toIso8601String(),
                'break_minutes' => (int) $session->break_minutes,
                'worked_hours' => $session->clock_out_at ? $session->worked_hours : null,
                'timesheet_sync_outcome' => $session->timesheetSyncOutcome()->value,
                'timesheet_id' => $synced ? (int) $timesheet->id : null,
                'timesheet_status' => $synced ? $timesheet->status : null,
                'handover_outcome' => $session->handoverOutcome()?->value,
            ]);
        } catch (Throwable $exception) {
            // Receipt delivery must not turn a committed command into a failed
            // attendance response, including when the logging channel fails.
            try {
                Log::warning('Committed attendance result could not be presented', [
                    'action' => $action, 'session_id' => (int) $session->id, 'exception_class' => $exception::class,
                ]);
            } catch (Throwable) {
                // The existing successful mutation response remains truthful.
            }

            return $response;
        }
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
            ...HandoverWorkerNotes::rules(),
            'expected_version' => ['nullable', 'integer', 'min:0'],
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
            'expected_version' => $data['expected_version'] ?? null,
        ];
        if (array_key_exists('worker_notes', $data)) {
            $payload['worker_notes'] = $data['worker_notes'];
        }

        if (
            ! array_key_exists('worker_notes', $data)
            && $auth->canDo('medications.controlled.view')
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
            $saved = $this->handoverService->save($shift, $auth, $payload);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            return redirect()->back()->withErrors($exception->errors());
        } catch (\DomainException) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'The handover draft could not be saved. Review it and try again.'], 409);
            }

            return redirect()->back()->withErrors([
                'handover' => 'The handover draft could not be saved. Review it and try again.',
            ]);
        }

        if ($request->expectsJson()) {
            $handover = $saved['handover'];

            return response()->json([
                'handover_id' => $handover->id,
                'expected_version' => (int) $handover->version,
                'status' => $handover->status,
                'saved_at' => $handover->updated_at->toIso8601String(),
                'review_url' => '/operations/handovers?'.http_build_query([
                    'week' => $shift->starts_at->copy()->timezone(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString(),
                    'handover' => $handover->id,
                ]),
            ])->header('Cache-Control', 'no-store, private');
        }

        return redirect()->back()->with('success', 'Handover draft saved. Assign the incoming shift before submitting.');
    }

    public function handoverDraft(Request $request, int $shift)
    {
        $actor = $request->user();
        abort_unless($this->canClock($actor), 403);
        $outgoing = $this->handoverService->writableOutgoingShift($actor, $shift);

        return response()->json(app(HandoverWorkerNotes::class)->editor($outgoing, $actor))
            ->header('Cache-Control', 'no-store, private');
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
