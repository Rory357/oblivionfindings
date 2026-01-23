<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\ShiftTask;
use Illuminate\Http\Request;

class ShiftTaskController extends Controller
{
    public function update(Request $request, Shift $shift, ShiftTask $task)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('shifts.update') || $auth->canDo('shifts.tasks.updateSelf') || $auth->canDo('shifts.manageAny')), 403);

        // Staff can update only their own shifts unless manageAny
        if (!$auth->canDo('shifts.manageAny') && $shift->user_id !== $auth->id) {
            abort(403);
        }

        abort_unless($task->shift_id === $shift->id, 404);

        $data = $request->validate([
            'is_completed' => ['required', 'boolean'],
        ]);

        if ($data['is_completed']) {
            $task->update([
                'is_completed' => true,
                'completed_at' => now(),
                'completed_by' => $auth->id,
            ]);
        } else {
            $task->update([
                'is_completed' => false,
                'completed_at' => null,
                'completed_by' => null,
            ]);
        }

        return response()->json(['ok' => true, 'task' => $task->fresh()]);
    }
}
