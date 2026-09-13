<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\ShiftTask;
use App\Services\MyDay\ShiftTaskHelpService;
use App\Services\MyDay\ShiftTaskWorkService;
use App\Services\ShiftHandoverService;
use App\Support\ShiftTaskSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MyDayTaskController extends Controller
{
    public function __construct(private readonly ShiftTaskWorkService $work) {}

    public function followUp(Request $request, ShiftHandover $handover, ShiftHandoverService $service): JsonResponse
    {
        $input = $request->validate(['item_key' => ['required', 'string', 'size:64']]);
        $task = $service->addMyDayFollowUp($handover, $request->user(), $input['item_key']);

        return response()->json(['task' => ShiftTaskSupport::workPayload($task)], $task->wasRecentlyCreated ? 201 : 200)
            ->header('Cache-Control', 'private, no-store');
    }

    public function step(Request $request, ShiftTask $task): JsonResponse
    {
        $input = $request->validate([
            'id' => ['required', 'uuid'],
            'action' => ['required', 'in:add,remove,complete'],
            'label' => ['required_if:action,add', 'string', 'max:180'],
            'is_completed' => ['required_if:action,complete', 'boolean'],
            'expected_version' => ['required', 'integer', 'min:0'],
        ]);

        return response()->json(['task' => ShiftTaskSupport::workPayload($this->work->updateStep($request->user(), $task, $input))]);
    }

    public function helpRecipients(Request $request, ShiftTask $task, ShiftTaskHelpService $help): JsonResponse
    {
        return response()->json(['recipients' => $help->recipients($request->user(), $task)])->header('Cache-Control', 'private, no-store');
    }

    public function requestHelp(Request $request, ShiftTask $task, ShiftTaskHelpService $help): JsonResponse
    {
        $input = $request->validate([
            'recipient_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:1000'],
            'expected_version' => ['required', 'integer', 'min:0'],
        ]);

        return response()->json(['task' => ShiftTaskSupport::workPayload($help->request($request->user(), $task, $input['recipient_id'], $input['reason'], $input['expected_version']))]);
    }

    public function respondHelp(Request $request, ShiftTask $task, ShiftTaskHelpService $help): JsonResponse
    {
        $input = $request->validate(['response' => ['required', 'in:accepted,declined'], 'expected_version' => ['required', 'integer', 'min:0']]);

        return response()->json(['task' => ShiftTaskSupport::workPayload($help->respond($request->user(), $task, $input['response'], $input['expected_version']))]);
    }

    public function store(Request $request, Shift $shift): JsonResponse
    {
        $this->work->authorize($request->user(), $shift, create: true);
        $input = $request->validate([
            'request_id' => ['required', 'uuid'],
            'label' => ['required', 'string', 'max:180'],
            'task_scope' => ['required', Rule::in(['client', 'site'])],
            'client_id' => ['required_if:task_scope,client', 'prohibited_if:task_scope,site', 'nullable', 'integer'],
            'when' => ['required', Rule::in(['anytime', 'now', 'time'])],
            'scheduled_for' => ['required_if:when,time', 'prohibited_unless:when,time', 'nullable', 'date'],
            'steps' => ['sometimes', 'array', 'max:20'],
            'steps.*' => ['array:id,label'],
            'steps.*.id' => ['required', 'uuid', 'distinct'],
            'steps.*.label' => ['required', 'string', 'max:180'],
        ]);
        $task = $this->work->create($request->user(), $shift, $input);

        return response()->json(['task' => ShiftTaskSupport::workPayload($task)], $task->wasRecentlyCreated ? 201 : 200);
    }

    public function complete(Request $request, ShiftTask $task): JsonResponse
    {
        $input = $request->validate([
            'is_completed' => ['required', 'boolean'],
            'expected_version' => ['required', 'integer', 'min:0'],
        ]);
        $task = $this->work->complete($request->user(), $task, (bool) $input['is_completed'], (int) $input['expected_version']);

        return response()->json(['task' => ShiftTaskSupport::workPayload($task)]);
    }
}
