<?php

namespace App\Http\Controllers;

use App\Models\ShiftTask;
use App\Models\Timesheet;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

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
}
