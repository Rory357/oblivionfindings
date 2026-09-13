<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\ShiftTaskDraft;
use App\Services\MyDay\ShiftTaskWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MyDayTaskDraftController extends Controller
{
    public function __construct(private readonly ShiftTaskWorkService $work) {}

    public function show(Request $request, Shift $shift): JsonResponse
    {
        $this->work->authorize($request->user(), $shift, create: true);
        $draft = ShiftTaskDraft::where('shift_id', $shift->id)->where('user_id', $request->user()->id)->first();

        return $this->response($draft);
    }

    public function update(Request $request, Shift $shift): JsonResponse
    {
        $this->work->authorize($request->user(), $shift, create: true);
        $input = $request->validate([
            'expected_version' => ['required', 'integer', 'min:0'],
            'content' => ['nullable', 'array:request_id,label,person,when,scheduled_for,steps'],
            'content.steps' => ['sometimes', 'array', 'max:20'],
            'content.steps.*' => ['array:id,label'],
            'content.steps.*.id' => ['required', 'uuid', 'distinct'],
            'content.steps.*.label' => ['nullable', 'string', 'max:180'],
            'content.request_id' => ['required_with:content', 'uuid'],
            'content.label' => ['nullable', 'string', 'max:180'],
            'content.person' => ['nullable', 'string', 'max:20'],
            'content.when' => ['nullable', 'in:anytime,now,time'],
            'content.scheduled_for' => ['nullable', 'string', 'max:40'],
        ]);

        $draft = DB::transaction(function () use ($request, $shift, $input): ShiftTaskDraft {
            $shift = Shift::lockForUpdate()->findOrFail($shift->id);
            $this->work->authorize($request->user(), $shift, create: true);
            $draft = ShiftTaskDraft::firstOrNew(['shift_id' => $shift->id, 'user_id' => $request->user()->id]);
            if ((int) $draft->version !== $input['expected_version']) {
                // Retrying the same snapshot after a lost response is safe.
                if ($draft->content === ($input['content'] ?? null)) {
                    return $draft;
                }
                throw ValidationException::withMessages(['draft' => 'Your draft changed in another window. Reopen Add task to load the saved draft.']);
            }
            $draft->fill(['content' => $input['content'] ?? null, 'version' => ((int) $draft->version) + 1])->save();

            return $draft;
        }, attempts: 3);

        return $this->response($draft);
    }

    private function response(?ShiftTaskDraft $draft): JsonResponse
    {
        $createdId = $draft?->content ? ShiftTask::query()
            ->where('shift_id', $draft->shift_id)
            ->where('created_by', $draft->user_id)
            ->where('creation_key', $draft->content['request_id'] ?? null)->value('id') : null;

        return response()->json([
            'content' => $draft?->content,
            'version' => $draft?->version ?? 0,
            'saved_at' => $draft?->updated_at?->toIso8601String(),
            'created_task_id' => $createdId,
        ])->header('Cache-Control', 'private, no-store');
    }
}
