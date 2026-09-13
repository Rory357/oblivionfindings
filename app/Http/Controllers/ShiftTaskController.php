<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\ShiftTask;
use App\Services\MyDay\ShiftTaskWorkService;
use App\Support\ShiftTaskSupport;
use Illuminate\Http\Request;

class ShiftTaskController extends Controller
{
    public function update(Request $request, Shift $shift, ShiftTask $task)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('shifts.update') || $auth->canDo('shifts.tasks.updateSelf') || $auth->canDo('shifts.manageAny')), 403);

        abort_unless($task->shift_id === $shift->id, 404);

        $data = $request->validate([
            'is_completed' => ['required', 'boolean'],
            'expected_version' => ['sometimes', 'integer', 'min:0'],
        ]);

        $saved = app(ShiftTaskWorkService::class)->complete($auth, $task, (bool) $data['is_completed'],
            (int) ($data['expected_version'] ?? $task->version ?? 0), allowManageAny: true);

        return response()->json(['ok' => true, 'task' => ShiftTaskSupport::workPayload($saved)]);
    }
}
