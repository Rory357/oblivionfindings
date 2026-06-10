<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Exceptions\AttendanceClockOutBlockedException;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Services\AttendanceService;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Operations\HandoverPresenter;
use App\Services\ShiftHandoverService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AttendanceController extends Controller
{
    public function __construct(
        protected AttendanceService $attendanceService,
        protected ShiftHandoverService $handoverService,
        protected HandoverPresenter $handoverPresenter,
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
        $targetUserId = $canManageAny ? (int) ($request->integer('user_id') ?: $auth->id) : $auth->id;
        $targetUser = $targetUserId === $auth->id
            ? $auth
            : User::query()->find($targetUserId);

        // Week is the unit of navigation for the sessions list (Mon–Sun, hero
        // week stepper). Compute the window in the worker timezone, then query
        // the UTC-stored clock_in_at column with UTC bounds.
        $tz = config('app.worker_timezone') ?: config('app.timezone', 'UTC');
        $weekStart = $request->filled('week')
            ? Carbon::parse($request->string('week'), $tz)->startOfWeek(Carbon::MONDAY)
            : Carbon::now($tz)->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

        $sessions = HrAttendanceSession::query()
            ->with(['timesheet:id,attendance_session_id,status'])
            ->where('user_id', $targetUserId)
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

        $totalSessions = HrAttendanceSession::query()
            ->where('user_id', $targetUserId)
            ->count();

        $openSession = HrAttendanceSession::query()
            ->with([
                'shift:id,client_id,starts_at,ends_at,location',
                'shift.client:id,first_name,last_name',
                'timesheet:id,attendance_session_id,status',
                'breakEvents' => fn ($query) => $query->orderBy('started_at'),
            ])
            ->where('user_id', $targetUserId)
            ->open()
            ->latest('clock_in_at')
            ->first();

        $eligibleShifts = $targetUser
            ? $this->attendanceService->eligibleShiftsForUser($targetUser, now())
            : collect();
        $activeShift = $eligibleShifts->count() === 1 ? $eligibleShifts->first() : null;

        $staff = $canManageAny
            ? User::query()->staff()->orderBy('name')->get(['id', 'name', 'email'])
            : collect();

        $todayHours = HrAttendanceSession::query()
            ->where('user_id', $targetUserId)
            ->whereDate('clock_in_at', now()->toDateString())
            ->get()
            ->sum(fn (HrAttendanceSession $session) => $session->worked_hours);

        $weekHours = HrAttendanceSession::query()
            ->where('user_id', $targetUserId)
            ->whereBetween('clock_in_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->get()
            ->sum(fn (HrAttendanceSession $session) => $session->worked_hours);

        // Managers get a live "who is on the clock now" board — open sessions
        // across the staff they can already see in the picker. Sessions open
        // for 16h+ are flagged as likely missed clock-outs.
        $staleCutoff = now()->subHours(16);
        $onClockNow = $canManageAny
            ? HrAttendanceSession::query()
                ->with(['user:id,name', 'shift:id,client_id,location,ends_at'])
                ->whereIn('user_id', User::staff()->select('id'))
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
                ])->values()
            : collect();

        // Handovers involving the signed-in user (both directions) feed the
        // Handovers tab — same row shape as the Shift Handovers workspace, plus
        // an `incoming` flag for the "awaiting YOUR acknowledgement" treatment.
        $handovers = ShiftHandover::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->whereIn('status', [ShiftHandoverService::STATUS_SUBMITTED, ShiftHandoverService::STATUS_ACKNOWLEDGED])
            ->where(function ($involving) use ($auth) {
                $involving->where('outgoing_staff_id', $auth->id)
                    ->orWhere('incoming_staff_id', $auth->id)
                    ->orWhereHas('outgoingShift', fn ($shift) => $shift->where('user_id', $auth->id))
                    ->orWhereHas('incomingShift', fn ($shift) => $shift->where('user_id', $auth->id));
            })
            ->with($this->handoverPresenter->mapEagerLoads())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(function (ShiftHandover $handover) use ($auth) {
                $mapped = $this->handoverPresenter->mapHandover($handover, $auth);
                $mapped['incoming'] = (int) $handover->incoming_staff_id === (int) $auth->id
                    || (int) ($handover->incomingShift?->user_id ?? 0) === (int) $auth->id;

                return $mapped;
            })->values();

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
            'catalogue' => Inertia::optional(fn () => $this->handoverPresenter->catalogue($auth)),
        ]);
    }

    /**
     * Correct a session's clock-out (the "fix a missed clock-out" wizard).
     * Managers may correct anyone's session; workers only their own. The
     * required reason lands in the audit log and the linked timesheet is
     * recalculated (submitted ones return to draft).
     */
    public function correctSession(Request $request, HrAttendanceSession $session)
    {
        $auth = $request->user();
        $ownSession = $auth && (int) $session->user_id === (int) $auth->id;
        abort_unless(
            $auth && ($auth->canDo('timesheets.manageAny') || ($ownSession && $this->canClock($auth))),
            403,
        );

        $data = $request->validate([
            'clock_out_at' => ['required', 'date'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $corrected = $this->attendanceService->correctSession(
                $auth,
                $session,
                Carbon::parse($data['clock_out_at']),
                (int) ($data['break_minutes'] ?? 0),
                trim($data['reason']),
            );
        } catch (\LogicException $exception) {
            return redirect()->back()->withErrors(['correct_session' => $exception->getMessage()]);
        }

        $name = $ownSession ? 'Session' : "Session for {$corrected->user?->name}";
        if ($corrected->timesheet) {
            return redirect()->back()->with('success', "{$name} corrected. Timesheet #{$corrected->timesheet->id} recalculated.");
        }

        return redirect()->back()->with('success', "{$name} corrected. The reason was recorded in the audit log.");
    }

    public function clockIn(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canClock($auth), 403);

        $data = $request->validate([
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! empty($data['shift_id'])) {
            $shift = Shift::query()->findOrFail((int) $data['shift_id']);

            if ((int) $shift->user_id !== (int) $auth->id) {
                AuditLogger::log('attendance.clockIn.unauthorized', $shift, [
                    'shift_id' => $shift->id,
                    'shift_user_id' => $shift->user_id,
                    'attempted_by_user_id' => $auth->id,
                ], $request);

                abort(403);
            }
        }

        try {
            $this->attendanceService->clockIn($auth, $data);
        } catch (\LogicException $exception) {
            return redirect()->back()->withErrors(['clock_in' => $exception->getMessage()]);
        }

        return redirect()->back()->with('success', 'Clocked in successfully.');
    }

    public function clockOut(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canClock($auth), 403);

        $data = $request->validate([
            'session_id' => ['nullable', 'integer', 'exists:hr_attendance_sessions,id'],
            'clock_out_at' => ['nullable', 'date'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
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
            'task_updates.*.id' => ['required', 'integer', 'distinct', 'exists:shift_tasks,id'],
            'task_updates.*.is_completed' => ['required', 'boolean'],
        ]);

        $session = null;
        if (! empty($data['session_id'])) {
            $session = HrAttendanceSession::query()->findOrFail($data['session_id']);

            if ((int) $session->user_id !== (int) $auth->id) {
                AuditLogger::log('attendance.clockOut.unauthorized', $session, [
                    'attendance_session_id' => $session->id,
                    'session_user_id' => $session->user_id,
                    'shift_id' => $session->shift_id,
                    'attempted_by_user_id' => $auth->id,
                ], $request);

                abort(403);
            }
        }

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

        if ($closed->timesheet) {
            return redirect()->back()->with('success', "Clocked out. Draft timesheet #{$closed->timesheet->id} synced.");
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

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
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
        if ($closed->timesheet) {
            return redirect()->back()->with('success', "Session ended for {$name}. Draft timesheet #{$closed->timesheet->id} synced.");
        }

        return redirect()->back()->with('success', "Session ended for {$name}.");
    }

    public function startBreak(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canClock($auth), 403);

        $data = $request->validate([
            'session_id' => ['nullable', 'integer', 'exists:hr_attendance_sessions,id'],
        ]);

        $session = null;
        if (! empty($data['session_id'])) {
            $session = HrAttendanceSession::query()->findOrFail($data['session_id']);
        }

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

        $data = $request->validate([
            'session_id' => ['nullable', 'integer', 'exists:hr_attendance_sessions,id'],
        ]);

        $session = null;
        if (! empty($data['session_id'])) {
            $session = HrAttendanceSession::query()->findOrFail($data['session_id']);
        }

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
     * clock card. Persisted as a submitted `ShiftHandover` so the next
     * worker sees it in the clock-in read prompt, and so it joins the
     * existing handover timeline/audit pipeline (no parallel storage).
     */
    public function submitHandover(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canClock($auth), 403);

        $data = $request->validate([
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'meds_completed' => ['required', 'boolean'],
            'shift_rating' => ['nullable', 'string', 'in:calm,mixed,challenging'],
            'handover_notes' => ['nullable', 'string', 'max:2000'],
            'follow_up_needed' => ['required', 'boolean'],
        ]);

        $shift = Shift::query()->findOrFail((int) $data['shift_id']);

        // Only the worker who worked the shift — or a manager — may leave a
        // handover for it. Avoids strangers writing on someone else's shift.
        if (
            ! $auth->canDo('shifts.manageAny')
            && (int) $shift->user_id !== (int) $auth->id
        ) {
            abort(403);
        }

        $notes = trim((string) ($data['handover_notes'] ?? ''));
        if ($notes === '') {
            $notes = $data['meds_completed']
                ? 'No specific items to flag for the next shift.'
                : 'Medications were not fully completed — please review on arrival.';
        }

        $payload = [
            'handover_notes' => $notes,
            'client_mood' => $data['shift_rating'] ?? null,
            'medications_due' => $data['meds_completed']
                ? null
                : [[
                    'label' => 'Review outstanding medications from previous shift',
                    'severity' => 'high',
                ]],
            'follow_up_items' => $data['follow_up_needed']
                ? [[
                    'label' => 'Follow-up flagged by outgoing worker',
                    'priority' => 'medium',
                ]]
                : null,
            'submit' => true,
        ];

        try {
            $this->handoverService->save($shift, $auth, $payload);
        } catch (\Throwable $exception) {
            return redirect()->back()->withErrors([
                'handover' => $exception->getMessage(),
            ]);
        }

        return redirect()->back()->with('success', 'Handover saved for the next shift.');
    }

    /**
     * PR 11 — Acknowledge the handover read prompt shown at clock-in.
     *
     * Thin wrapper over `ShiftHandoverService::acknowledge` that reuses the
     * existing permission/invariant logic but is reachable from the frontline
     * `/my-day` handover-read card without depending on the operations-module
     * route.
     */
    public function acknowledgeHandover(Request $request, ShiftHandover $handover)
    {
        $auth = $request->user();
        abort_unless($this->canClock($auth), 403);

        $handover->loadMissing(['incomingShift:id,user_id', 'client:id,site_id']);
        $isUnassigned = $handover->incoming_staff_id === null && $handover->incoming_shift_id === null;
        $relatedToUser = (int) $handover->incoming_staff_id === (int) $auth->id
            || (int) $handover->incomingShift?->user_id === (int) $auth->id
            || ($isUnassigned && $this->workerHasMatchingShiftForUnassignedHandover($auth, $handover));

        abort_unless(
            $relatedToUser || $auth->canDo('shifts.manageAny') || $auth->canDo('handovers.viewAny'),
            403,
        );

        try {
            // `acknowledge` requires an assigned incoming shift. If the
            // read surface found the handover via client match without a
            // linked incoming shift, attach the arriving worker so the
            // acknowledgement can settle.
            if ($isUnassigned) {
                $handover->forceFill(['incoming_staff_id' => $auth->id])->save();
            }

            $this->handoverService->acknowledge($handover, $auth);
        } catch (\Throwable $exception) {
            return redirect()->back()->withErrors([
                'handover' => $exception->getMessage(),
            ]);
        }

        return redirect()->back()->with('success', 'Handover marked as read.');
    }

    protected function workerHasMatchingShiftForUnassignedHandover(User $auth, ShiftHandover $handover): bool
    {
        $clientId = $handover->client_id ? (int) $handover->client_id : null;

        if (! $clientId) {
            return false;
        }

        $workerNow = now(config('app.worker_timezone', 'Pacific/Auckland'));
        $windowStart = $workerNow->copy()->subHours(4)->utc();
        $windowEnd = $workerNow->copy()->addHours(36)->utc();

        return Shift::query()
            ->where('user_id', $auth->id)
            ->where('client_id', $clientId)
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->where(function ($query) use ($windowStart, $windowEnd) {
                $query
                    ->whereBetween('starts_at', [$windowStart, $windowEnd])
                    ->orWhereBetween('ends_at', [$windowStart, $windowEnd])
                    ->orWhere(function ($overlap) use ($windowStart, $windowEnd) {
                        $overlap
                            ->where('starts_at', '<=', $windowStart)
                            ->where('ends_at', '>=', $windowEnd);
                    });
            })
            ->exists();
    }
}
