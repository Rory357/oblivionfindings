<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\It\Services\ItWorkTaskGraphEvaluator;
use App\Domain\It\Services\ItWorkTaskReadinessService;
use App\Http\Controllers\Controller;
use App\Models\ItTicket;
use App\Models\ItWorkTask;
use App\Models\ItWorkTaskCompletion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ItWorkTaskHistoryController extends Controller
{
    public function __invoke(Request $request, ItTicket $ticket, ItWorkTask $task, ItWorkAccessService $access, ItWorkTaskReadinessService $readiness, ItWorkTaskGraphEvaluator $graph): JsonResponse
    {
        $input = $request->validate([
            'actor_user_id' => ['required', 'integer', 'min:1'],
            'review_nonce' => ['required', 'uuid'],
            'before_sequence' => ['sometimes', 'integer', 'min:1'],
        ]);

        $data = DB::transaction(function () use ($request, $ticket, $task, $access, $readiness, $graph, $input): array {
            $actor = User::query()->find($request->user()->id);
            abort_unless($actor && $actor->approved_at !== null && (int) $input['actor_user_id'] === (int) $actor->id, 403);
            $current = ItTicket::query()->findOrFail($ticket->id);
            abort_unless($access->canView($actor, $current), 404);
            abort_unless($access->canWork($actor, $current), 403);
            abort_unless((int) $task->ticket_id === (int) $current->id, 404);
            $work = $readiness->forTicket($current, $actor);
            abort_unless($work['storage_ready'], 503);
            $currentTask = $work['tasks']->firstWhere('id', $task->id);
            abort_unless($currentTask, 404);
            $query = $currentTask->completions()->orderByDesc('sequence');
            if (isset($input['before_sequence'])) {
                $query->where('sequence', '<', $input['before_sequence']);
            }
            $entries = $query->with(['completedBy:id,name', 'recordedBy:id,name'])->limit(21)->get();
            $hasMore = $entries->count() > 20;
            $entries = $entries->take(20)->values();
            $affectedIds = $graph->affectedDescendants($work['graph'], (int) $task->id);

            return [
                'viewer_user_id' => (int) $actor->id, 'ticket_id' => (int) $current->id, 'task_id' => (int) $currentTask->id,
                'lock_version' => (int) $current->lock_version, 'review_nonce' => $input['review_nonce'],
                'current_completion_id' => $currentTask->current_completion_id,
                'readiness' => $work['verdicts'][$currentTask->id],
                'affected_tasks' => $work['tasks']->whereIn('id', $affectedIds)->sortBy('id')
                    ->map(fn (ItWorkTask $affected): array => ['id' => (int) $affected->id, 'title' => $affected->title, 'status' => $affected->status])->values()->all(),
                'history' => [
                    'entries' => $entries->map(fn (ItWorkTaskCompletion $completion): array => [
                        'id' => (int) $completion->id, 'sequence' => $completion->sequence, 'source' => $completion->source,
                        'recorded_at' => $completion->recorded_at->toIso8601String(),
                        'completed_at' => $completion->completed_at?->toIso8601String(),
                        'completed_by_user_id' => $completion->completed_by_user_id,
                        'completed_by' => $completion->completedBy ? ['id' => (int) $completion->completedBy->id, 'name' => $completion->completedBy->name] : null,
                        'recorded_by_user_id' => $completion->recorded_by_user_id,
                        'recorded_by' => $completion->recordedBy ? ['id' => (int) $completion->recordedBy->id, 'name' => $completion->recordedBy->name] : null,
                        'task_definition' => $completion->task_definition,
                        'prerequisite_completions' => $completion->prerequisite_completions,
                        'approval_id' => $completion->approval_id, 'completion_note' => $completion->completion_note, 'evidence' => $completion->evidence,
                    ])->all(),
                    'has_more' => $hasMore, 'next_before_sequence' => $hasMore ? $entries->last()->sequence : null,
                    'total_count' => $currentTask->completions()->count(),
                ],
            ];
        });

        return response()->json(['status' => 'ok', 'data' => $data], 200, ['Cache-Control' => 'no-store, private']);
    }
}
