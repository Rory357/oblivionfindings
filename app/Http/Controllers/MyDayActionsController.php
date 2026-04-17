<?php

namespace App\Http\Controllers;

use App\Models\ControlRoomAlert;
use App\Models\ShiftTask;
use App\Models\Timesheet;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Legacy My Day helpers — now trimmed to the two safe, still-used actions:
 * completing a shift task and submitting a timesheet draft.
 *
 * The old shortcut `clockIn`/`clockOut` methods were removed in PR 4.5 so
 * the frontline clock flow has a single trusted path through
 * {@see \App\Http\Controllers\AttendanceController} + {@see \App\Domain\Hr\Services\AttendanceService}.
 * Do not re-add quick-clock endpoints here.
 */
class MyDayActionsController extends Controller
{
    public function completeShiftTask(Request $request, ShiftTask $task)
    {
        abort_unless($request->user(), 403);
        // Verify the task belongs to a shift assigned to this user
        abort_unless($task->shift && $task->shift->user_id === $request->user()->id, 403);

        if ($task->is_completed) {
            $task->update([
                'is_completed' => false,
                'completed_at' => null,
                'completed_by' => null,
            ]);
        } else {
            $task->update([
                'is_completed' => true,
                'completed_at' => now(),
                'completed_by' => $request->user()->id,
            ]);
        }

        return back()->with('success', $task->is_completed ? 'Task completed.' : 'Task reopened.');
    }

    public function submitTimesheet(Request $request, Timesheet $timesheet)
    {
        abort_unless($request->user(), 403);
        abort_unless($timesheet->user_id === $request->user()->id, 403);
        abort_unless(in_array($timesheet->status, ['draft', 'returned']), 422);

        $timesheet->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'submitted_by' => $request->user()->id,
        ]);

        AuditLogger::log('timesheet.submit', $timesheet, ['timesheet_id' => $timesheet->id]);

        return back()->with('success', 'Timesheet submitted for approval.');
    }

    /**
     * Frontline acknowledge — lets the assigned worker mark a control-room
     * alert as seen from /my-day.
     *
     * Distinct from {@see \App\Http\Controllers\ControlRoom\ControlRoomAlertController::acknowledge}
     * which is gated to CR operators with `controlRoom.alerts.manage`. Here we
     * gate strictly on the alert's assignee so a frontline worker can clear
     * their own item without inheriting operator permissions. Transitions
     * open → ack via the same lifecycle check; a no-op otherwise so repeated
     * taps stay safe.
     */
    public function acknowledgeAlert(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($alert->assigned_to_user_id === $user->id, 403);

        if ($alert->isTerminal()) {
            return back();
        }

        if (! $alert->canTransitionTo(ControlRoomAlert::STATUS_ACK)) {
            // Already ack'd / triaging — treat as a successful no-op so the
            // frontline button stays idempotent.
            return back()->with('success', 'Alert already acknowledged.');
        }

        $alert->update([
            'status' => ControlRoomAlert::STATUS_ACK,
            'acknowledged_at' => now(),
            'acknowledged_by_user_id' => $user->id,
            'snoozed_until' => null,
            'snoozed_by_user_id' => null,
        ]);

        $alert->sla?->recordAcknowledge();

        AuditLogger::log('controlRoom.alert.acknowledge', $alert, [
            'alert_id' => $alert->id,
            'acknowledged_by' => $user->id,
            'via' => 'my-day',
        ]);

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
    public function snoozeAlert(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($alert->assigned_to_user_id === $user->id, 403);

        if ($alert->isTerminal()) {
            return back();
        }

        if (strtolower((string) $alert->severity) === 'critical') {
            return back()->withErrors([
                'alert' => 'Critical alerts can\'t be snoozed. Open or acknowledge it.',
            ]);
        }

        $window = $request->input('window', '15m');
        $until = match ($window) {
            '1h' => now()->addHour(),
            'shift' => $this->endOfShiftFor($user),
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
            'window' => $window,
        ]);

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
            $openShift = \App\Domain\Hr\Models\HrAttendanceSession::query()
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
