<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Services\AttendanceService;
use App\Domain\Shifts\Timesheets\Drafts\DraftTimesheetService;
use App\Domain\Shifts\Timesheets\TimesheetAllocationService;
use App\Domain\Shifts\Timesheets\TimesheetApprovalService;
use App\Http\Controllers\ControlRoom\ControlRoomAlertController;
use App\Models\ControlRoomAlert;
use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Services\MyDay\ShiftTaskWorkService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Legacy My Day helpers — now trimmed to the two safe, still-used actions:
 * completing a shift task and submitting a timesheet draft.
 *
 * The old shortcut `clockIn`/`clockOut` methods were removed in PR 4.5 so
 * the frontline clock flow has a single trusted path through
 * {@see AttendanceController} + {@see AttendanceService}.
 * Do not re-add quick-clock endpoints here.
 */
class MyDayActionsController extends Controller
{
    public function completeShiftTask(Request $request, ShiftTask $task)
    {
        abort_unless($request->user(), 403);
        $data = $request->validate([
            'is_completed' => ['sometimes', 'boolean'],
            'expected_version' => ['sometimes', 'integer', 'min:0'],
        ]);
        // Legacy empty requests mean complete, never toggle. New clients send
        // the desired state and the version they actually displayed.
        $task = app(ShiftTaskWorkService::class)->complete(
            $request->user(), $task, $data['is_completed'] ?? true,
            $data['expected_version'] ?? (int) $task->version,
        );

        return back()->with('success', $task->is_completed ? 'Task completed.' : 'Task reopened.');
    }

    /**
     * Find-or-create today's draft timesheet for the worker so the /my-day
     * "Today's timesheet" button can open the review popup immediately, even
     * before the worker has clocked out.
     *
     * Historically a Timesheet row was only written by AttendanceService on
     * clock-out, which meant clicking "Today's timesheet" mid-shift had
     * nothing to show. The popup-driven UX needs an existing draft, so this
     * endpoint:
     *
     *   1. Locates the worker's active (in-progress) shift for today.
     *   2. Looks up the (shift_id, user_id) timesheet — Timesheet enforces a
     *      unique pair, so AttendanceService's eventual clock-out call will
     *      update the SAME row instead of conflicting.
     *   3. Creates the row with the shift's planned start/end/break if it
     *      doesn't exist yet.
     *   4. Flashes `open_timesheet_id` so the front-end knows which draft to
     *      open in the popup once props refresh.
     */
    public function ensureTodayTimesheet(Request $request)
    {
        $request->validate(['shift_id' => ['nullable', 'integer']]);
        $user = $request->user();
        abort_unless($user && $user->canDo('timesheets.create'), 403);
        $timesheet = DB::transaction(function () use ($request, $user) {
            abort_unless(DB::table('hr_payroll_run_mutexes')->where('key', 'application')->lockForUpdate()->first(), 503);
            $user = app(AuthorizationEvidenceLockService::class)->lockForUser($user, ['timesheets.create']);
            abort_unless($user->canDo('timesheets.create'), 403);
            $tz = config('app.worker_timezone', 'Pacific/Auckland');
            $now = Carbon::now($tz);
            $sessionShiftId = HrAttendanceSession::query()->where('user_id', $user->id)->whereNull('clock_out_at')->latest('clock_in_at')->value('shift_id');
            $wantedId = $request->integer('shift_id') ?: $sessionShiftId;
            $query = Shift::query()->where('user_id', $user->id)->visibleToFrontline()
                ->whereIn('status', ['scheduled', 'in_progress', 'completed'])
                ->tap(fn ($query) => app(UserSiteAccessService::class)->applyShiftScope($query, $user));
            if ($wantedId) {
                $query->whereKey($wantedId);
            } else {
                $query->where('starts_at', '<=', $now->copy()->endOfDay()->utc())
                    ->where('ends_at', '>=', $now->copy()->startOfDay()->utc());
            }
            $shift = $query->orderBy('starts_at')->lockForUpdate()->first();
            if (! $shift) {
                throw ValidationException::withMessages(['timesheet' => 'No authorised current shift is available. Refresh My Day and check your shift.']);
            }
            // An explicit request cannot be used to create arbitrary future or ancient drafts.
            $current = (int) $sessionShiftId === (int) $shift->id
                || ($shift->starts_at->copy()->timezone($tz)->isSameDay($now))
                || $now->betweenIncluded($shift->starts_at, $shift->ends_at);
            abort_unless($current, 403);
            app(UserSiteAccessService::class)->assertCanAccessShift($user, $shift);
            $existing = Timesheet::query()->where('shift_id', $shift->id)->where('user_id', $user->id)->first();
            if ($existing) {
                return $existing;
            }
            $result = app(DraftTimesheetService::class)->fromShift($shift, $user->id);
            if (! $result['success'] || ! $result['timesheet']) {
                throw ValidationException::withMessages(['timesheet' => $result['reason'] ?? 'Could not prepare your timesheet.']);
            }
            AuditLogger::logOrFail('timesheet.draft.ensure', $result['timesheet'], ['shift_id' => $shift->id, 'actor_id' => $user->id]);

            return $result['timesheet'];
        }, attempts: 3);

        return back()->with('open_timesheet_id', $timesheet->id);
    }

    public function submitTimesheet(Request $request, Timesheet $timesheet)
    {
        abort_unless($request->user() && (int) $timesheet->user_id === (int) $request->user()->id, 403);
        if (! $request->has('client_allocations')) {
            app(TimesheetApprovalService::class)->submit($timesheet, $request->user());

            return back()->with('success', 'Timesheet submitted for approval.');
        }

        return $this->reviewTimesheetAllocations($request, $timesheet, true);
    }

    public function saveTimesheetAllocations(Request $request, Timesheet $timesheet)
    {
        return $this->reviewTimesheetAllocations($request, $timesheet, false);
    }

    private function reviewTimesheetAllocations(Request $request, Timesheet $timesheet, bool $submit)
    {
        abort_unless($request->user() && (int) $timesheet->user_id === (int) $request->user()->id, 403);
        $input = $request->validate([
            'expected_revision' => ['required', 'string', 'size:64'],
            'client_allocations' => ['required', 'array', 'min:1', 'max:50'],
            'client_allocations.*.client_id' => ['required', 'integer'],
        ]);
        $result = app(TimesheetApprovalService::class)->reviewAllocations(
            $timesheet, $request->user(), $request->input('client_allocations'), $input['expected_revision'], $submit,
        );
        if ($request->expectsJson()) {
            return response()->json([
                'saved' => true, 'status' => $result->timesheet->status,
                'revision' => app(TimesheetAllocationService::class)->revision($result->timesheet),
            ])->header('Cache-Control', 'private, no-store');
        }

        return back()->with('success', $submit ? 'Timesheet submitted for approval.' : 'Time split saved to your draft.');
    }

    /**
     * Frontline acknowledge — lets the assigned worker mark a control-room
     * alert as seen from /my-day.
     *
     * Distinct from {@see ControlRoomAlertController::acknowledge}
     * which is gated to CR operators with `controlRoom.alerts.manage`. Here we
     * gate strictly on the alert's assignee so a frontline worker can clear
     * their own item without inheriting operator permissions. The canonical
     * lifecycle owns the open → ack mutation and reports stale actions without
     * overwriting a newer operator state.
     */
    public function acknowledgeAlert(
        Request $request,
        ControlRoomAlert $alert,
        ControlRoomAlertLifecycleService $lifecycle,
        UserSiteAccessService $siteAccess,
    ) {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($alert->assigned_to_user_id === $user->id, 403);
        $currentUser = User::query()->find($user->id);
        abort_unless($currentUser, 403);
        $siteAccess->assertCanAccessAlert($currentUser, $alert);

        try {
            $acknowledged = $lifecycle->acknowledge($alert, $user, null, $user->id);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['alert' => $exception->getMessage()]);
        }

        $acknowledged->forceFill([
            'snoozed_until' => null,
            'snoozed_by_user_id' => null,
        ])->save();

        return back()->with('success', 'Alert acknowledged.');
    }

    /**
     * Frontline snooze — hides the alert from the assignee's /my-day open
     * items until the window elapses. The alert stays open (CR status and
     * SLA untouched) so nothing is silenced for operators.
     *
     * Accepts one of three preset windows; invalid values fall through to the
     * shortest window. Critical alerts can't be snoozed — they must be
     * opened or acknowledged.
     */
    public function snoozeAlert(
        Request $request,
        ControlRoomAlert $alert,
        ControlRoomAlertLifecycleService $lifecycle,
        UserSiteAccessService $siteAccess,
    ) {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($alert->assigned_to_user_id === $user->id, 403);
        $currentUser = User::query()->find($user->id);
        abort_unless($currentUser, 403);
        $siteAccess->assertCanAccessAlert($currentUser, $alert);

        $window = $request->input('window', '15m');
        $until = match ($window) {
            '1h' => now()->addHour(),
            'shift' => $this->endOfShiftFor($user),
            default => now()->addMinutes(15),
        };

        try {
            $lifecycle->snoozeForAssignee($alert, $user, $until, $window);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['alert' => $exception->getMessage()]);
        }

        return back()->with('success', 'Snoozed.');
    }

    /**
     * Best-effort "end of shift" resolution for snooze windows.
     *
     * Uses the user's open attendance session or next eligible shift if
     * either is available; otherwise falls back to end-of-day so the snooze
     * always has a finite window and can't be abused to hide work forever.
     */
    private function endOfShiftFor($user): Carbon
    {
        try {
            $openShift = HrAttendanceSession::query()
                ->where('user_id', $user->id)
                ->open()
                ->with('shift:id,ends_at')
                ->latest('clock_in_at')
                ->first();

            if ($openShift?->shift?->ends_at) {
                $end = Carbon::parse($openShift->shift->ends_at);
                if ($end->isFuture()) {
                    return $end;
                }
            }
        } catch (\Throwable) {
            // fall through to default
        }

        return now()->endOfDay();
    }
}
