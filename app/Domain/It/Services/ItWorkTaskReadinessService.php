<?php

namespace App\Domain\It\Services;

use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItWorkTask;
use App\Models\ItWorkTaskCompletion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Single-organisation private work projection; callers retain canonical ticket ownership. */
final class ItWorkTaskReadinessService
{
    public function __construct(private readonly ItWorkTaskGraphEvaluator $graph, private readonly ItWorkAccessService $access) {}

    public function storageReady(): bool
    {
        return Schema::hasTable('it_work_task_completions')
            && Schema::hasColumns('it_work_tasks', ['current_completion_id', 'approval_id']);
    }

    /**
     * Reads one consistent graph. Mutating callers already hold the ticket lock.
     * No completion capture or historical repair is performed by this read.
     *
     * @return array{storage_ready:bool,tasks:Collection,verdicts:array,graph:array}
     */
    public function forTicket(ItTicket $ticket, User $viewer, bool $lock = false): array
    {
        if (! $this->access->canWork($viewer, $ticket)) {
            throw new AuthorizationException('You cannot view this ticket’s private work.');
        }
        if ($lock && DB::transactionLevel() < 1) {
            throw new LogicException('Locked task readiness requires the canonical ticket transaction.');
        }

        $canManage = $viewer->canDo('it.manage') && ! $ticket->isMerged() && in_array($ticket->status, ItTicket::OPEN_STATUSES, true);

        return $this->project($ticket, $lock, $canManage);
    }

    /** Internal automation only: no user payload or task action permissions. */
    public function forSettlement(ItTicket $ticket): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('System settlement requires the canonical ticket transaction.');
        }

        return $this->project($ticket, lock: true, canManage: false);
    }

    private function project(ItTicket $ticket, bool $lock, bool $canManage): array
    {
        return DB::transaction(function () use ($ticket, $lock, $canManage): array {
            $ready = $this->storageReady();
            $query = $ticket->tasks()->orderBy('id');
            if ($lock) {
                $query->lockForUpdate();
            }
            $tasks = $query->with(['dependencies:id,ticket_id,title,status', 'team:id,name', 'assignee:id,name', 'completedBy:id,name'])->get();
            $rows = $tasks->map(fn (ItWorkTask $task): array => [
                'id' => (int) $task->id, 'status' => $task->status,
                'dependency_ids' => $task->dependencies->modelKeys(),
                'current_completion_id' => $ready && $task->current_completion_id !== null ? (int) $task->current_completion_id : null,
                'approval_id' => $ready && $task->approval_id !== null ? (int) $task->approval_id : null,
            ])->all();
            $completionRows = [];
            $approvalRows = [];
            if ($ready) {
                $currentIds = array_values(array_filter(array_column($rows, 'current_completion_id')));
                $completions = ItWorkTaskCompletion::query()->whereIn('task_id', $tasks->modelKeys())->whereIn('id', $currentIds)
                    ->get(['id', 'task_id', 'source', 'prerequisite_completions', 'approval_id']);
                $referenced = $completions->flatMap(fn (ItWorkTaskCompletion $completion) => is_array($completion->prerequisite_completions)
                    ? array_column($completion->prerequisite_completions, 'completion_id') : [])
                    ->filter(fn ($id) => is_int($id) && $id > 0)->unique()->diff($currentIds)->values()->all();
                if ($referenced !== []) {
                    $completions = $completions->concat(ItWorkTaskCompletion::query()->whereIn('task_id', $tasks->modelKeys())->whereIn('id', $referenced)
                        ->get(['id', 'task_id', 'source', 'prerequisite_completions', 'approval_id']));
                }
                $completionRows = $completions->map(fn (ItWorkTaskCompletion $completion): array => [
                    'id' => (int) $completion->id, 'task_id' => (int) $completion->task_id, 'source' => $completion->source,
                    'prerequisite_completions' => $completion->prerequisite_completions, 'approval_id' => $completion->approval_id,
                ])->all();
                $approvalQuery = ItTicketApproval::query()->where('it_ticket_id', $ticket->id)
                    ->whereIn('id', array_filter(array_column($rows, 'approval_id')))->orderBy('id');
                if ($lock) {
                    $approvalQuery->lockForUpdate();
                }
                $approvalRows = $approvalQuery->get()->map(fn (ItTicketApproval $approval): array => ['id' => (int) $approval->id, 'status' => $approval->effectiveStatus()])->all();
            }
            $verdicts = $this->graph->evaluate($rows, $completionRows, $approvalRows);
            $taskMap = $tasks->keyBy('id');
            foreach ($verdicts as $id => &$verdict) {
                $task = $taskMap->get($id);
                $verdict['storage_ready'] = $ready;
                $verdict['can_start'] = $canManage && $ready && in_array($task->status, ['pending', 'blocked', 'in_progress'], true) && $verdict['prerequisites'] !== 'blocked';
                $verdict['can_complete'] = $canManage && $ready && ! in_array($task->status, ['completed', 'cancelled'], true) && $verdict['prerequisites'] !== 'blocked';
                $verdict['can_reopen'] = $canManage && $ready && $task->status === 'completed';
                $verdict['can_edit'] = $canManage && $ready && $task->status !== 'completed';
                $verdict['can_cancel'] = $canManage && $ready && ! in_array($task->status, ['completed', 'cancelled'], true) && ! $task->is_required;
                $verdict['can_restore'] = $canManage && $ready && $task->status === 'cancelled';
                foreach (['blockers', 'warnings'] as $kind) {
                    $verdict[$kind] = array_map(fn (array $issue): array => [...$issue, 'message' => $this->message($issue['code'])], $verdict[$kind]);
                }
            }
            unset($verdict);

            return ['storage_ready' => $ready, 'tasks' => $tasks, 'verdicts' => $verdicts, 'graph' => $rows];
        });
    }

    /** Call after proposed task/dependency changes and legacy capture, within the ticket lock. */
    public function guardAction(ItTicket $ticket, ItWorkTask $task, User $actor, string $action): void
    {
        if (! in_array($action, ['start', 'complete'], true)) {
            throw new LogicException('Unknown task readiness action.');
        }
        $state = $this->forTicket($ticket, $actor, lock: true);
        $verdict = $state['verdicts'][$task->id] ?? null;
        if (! $state['storage_ready']) {
            throw ValidationException::withMessages(['form' => 'Task history is unavailable. Your work has been kept; retry after IT setup is complete.']);
        }
        if ($verdict === null || ! $verdict['can_'.$action]) {
            $message = $verdict['blockers'][0]['message'] ?? 'This task cannot '.$action.' in its current state.';
            throw ValidationException::withMessages([$action === 'start' ? 'status' : 'form' => $message]);
        }
    }

    public function message(string $code): string
    {
        return match ($code) {
            'dependency_unavailable' => 'A prerequisite is unavailable. Review this task’s prerequisites.',
            'dependency_cancelled' => 'Restore and complete the cancelled prerequisite, or review its replacement.',
            'dependency_incomplete' => 'Complete the prerequisite before continuing this task.',
            'dependency_completion_invalid' => 'A prerequisite’s completion needs review before this work can continue.',
            'dependency_completion_changed' => 'A prerequisite was reopened or completed again. Reopen and complete this dependent task again.',
            'dependency_definition_changed' => 'The prerequisites differ from this completion’s recorded evidence. Review and complete the task again.',
            'dependency_cycle' => 'This task depends on a circular prerequisite chain. Review the linked work.',
            'completion_unavailable', 'completion_provenance_invalid' => 'The current completion does not match its recorded evidence. Review this task’s history.',
            'completion_history_unknown' => 'Historical completion evidence was not recorded. The existing completion status has been preserved.',
            'dependency_history_unknown' => 'An earlier prerequisite’s historical evidence is unavailable.',
            'approval_pending' => 'The linked approval is still awaiting a decision.',
            'approval_rejected' => 'The linked approval was rejected. Review the request before continuing.',
            'approval_expired' => 'The linked approval expired. Request a new decision before continuing.',
            'approval_cancelled' => 'The linked approval was cancelled. Review the request before continuing.',
            'approval_changed' => 'This completion refers to a different approval request. Review and complete the task again.',
            'approval_unavailable' => 'The linked approval is unavailable. Review this task’s approval requirement.',
            default => 'Review this task’s requirements before continuing.',
        };
    }
}
