<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Services\AttendanceService;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AttendanceController extends Controller
{
    public function __construct(
        protected AttendanceService $attendanceService,
    ) {}

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('timesheets.viewAssigned') || $auth->canDo('timesheets.viewAny')), 403);

        $canManageAny = $auth->canDo('timesheets.manageAny');
        $targetUserId = $canManageAny ? (int) ($request->integer('user_id') ?: $auth->id) : $auth->id;
        $targetUser = $targetUserId === $auth->id
            ? $auth
            : User::query()->find($targetUserId);

        $query = HrAttendanceSession::query()
            ->with(['timesheet:id,attendance_session_id,status'])
            ->where('user_id', $targetUserId)
            ->orderByDesc('clock_in_at');

        $sessions = $query->paginate(20)->withQueryString();

        $openSession = HrAttendanceSession::query()
            ->with(['shift:id,client_id,starts_at,ends_at', 'timesheet:id,attendance_session_id,status'])
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

        $sessions->through(function (HrAttendanceSession $session) {
            return [
                'id' => $session->id,
                'clock_in_at' => optional($session->clock_in_at)->toDateTimeString(),
                'clock_out_at' => optional($session->clock_out_at)->toDateTimeString(),
                'break_minutes' => (int) $session->break_minutes,
                'status' => $session->status,
                'source' => $session->source,
                'location' => $session->location,
                'worked_hours' => $session->worked_hours,
                'timesheet_id' => $session->timesheet?->id,
                'timesheet_status' => $session->timesheet?->status,
            ];
        });

        $todayHours = HrAttendanceSession::query()
            ->where('user_id', $targetUserId)
            ->whereDate('clock_in_at', now()->toDateString())
            ->get()
            ->sum(fn (HrAttendanceSession $session) => $session->worked_hours);

        return Inertia::render('attendance/index', [
            'sessions' => $sessions,
            'openSession' => $openSession ? [
                'id' => $openSession->id,
                'clock_in_at' => optional($openSession->clock_in_at)->toDateTimeString(),
                'shift_id' => $openSession->shift_id,
                'shift_starts_at' => optional($openSession->shift?->starts_at)->toDateTimeString(),
                'shift_ends_at' => optional($openSession->shift?->ends_at)->toDateTimeString(),
                'timesheet_id' => $openSession->timesheet?->id,
            ] : null,
            'activeShift' => $activeShift ? [
                'id' => $activeShift->id,
                'starts_at' => optional($activeShift->starts_at)->toDateTimeString(),
                'ends_at' => optional($activeShift->ends_at)->toDateTimeString(),
                'status' => $activeShift->status,
                'location' => $activeShift->location,
            ] : null,
            'eligibleShifts' => $eligibleShifts->map(fn (Shift $shift) => [
                'id' => $shift->id,
                'starts_at' => optional($shift->starts_at)->toDateTimeString(),
                'ends_at' => optional($shift->ends_at)->toDateTimeString(),
                'status' => $shift->status,
                'location' => $shift->location,
                'client_name' => trim((string) ($shift->client?->first_name.' '.$shift->client?->last_name)),
            ])->values(),
            'staff' => $staff,
            'filters' => [
                'user_id' => $canManageAny ? $targetUserId : null,
            ],
            'todayHours' => round((float) $todayHours, 2),
            'canManageAny' => $canManageAny,
            'canClock' => $this->canClock($auth),
        ]);
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
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ]);

        $session = null;
        if (! empty($data['session_id'])) {
            $session = HrAttendanceSession::query()->findOrFail($data['session_id']);
        }

        try {
            $closed = $this->attendanceService->clockOut($auth, $session, $data);
        } catch (\LogicException $exception) {
            return redirect()->back()->withErrors(['clock_out' => $exception->getMessage()]);
        }

        if ($closed->timesheet) {
            return redirect()->back()->with('success', "Clocked out. Draft timesheet #{$closed->timesheet->id} synced.");
        }

        return redirect()->back()->with('success', 'Clocked out successfully.');
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
}
