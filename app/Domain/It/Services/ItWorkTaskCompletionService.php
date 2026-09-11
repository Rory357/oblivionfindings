<?php

namespace App\Domain\It\Services;

use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItWorkTask;
use App\Models\ItWorkTaskCompletion;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Called only by the authorized, ticket/task-locked canonical mutation. */
final class ItWorkTaskCompletionService
{
    private ?string $readyConnection = null;

    public function requireStorage(): void
    {
        $connection = DB::connection();
        $identity = spl_object_id($connection).':'.$connection->getDatabaseName();
        if ($this->readyConnection === $identity) {
            return;
        }
        if (! Schema::hasTable('it_work_task_completions')
            || ! Schema::hasColumns('it_work_tasks', ['current_completion_id', 'approval_id'])) {
            throw ValidationException::withMessages(['form' => 'Task completion history setup is incomplete. Keep your work and retry after setup is complete.']);
        }
        // The helper lives with one canonical command. Avoid repeating schema
        // inspection for every prerequisite in the locked graph.
        $this->readyConnection = $identity;
    }

    /** Capture extant legacy facts only; never called from a read projection. */
    public function captureLegacy(ItTicket $ticket, ItWorkTask $task, User $actor): ?ItWorkTaskCompletion
    {
        $this->assertContext($ticket, $task);
        if ($task->status !== 'completed') {
            return null;
        }
        if ($task->current_completion_id !== null) {
            return $this->current($task);
        }
        if ($task->completions()->exists()) {
            throw new DomainException('The current completion does not match its recorded history. Review this task before changing it.');
        }
        $completion = $task->completions()->create([
            'sequence' => 1,
            'source' => ItWorkTaskCompletion::SOURCE_LEGACY,
            'recorded_at' => now(),
            'completed_at' => $task->completed_at,
            'completed_by_user_id' => $task->completed_by_user_id,
            'recorded_by_user_id' => $actor->id,
            // For legacy rows this is the definition observed now, not a
            // claim about the definition at their earlier completion time.
            'task_definition' => $this->definition($task),
            'prerequisite_completions' => null,
            'approval_id' => null,
            'completion_note' => $task->completion_note,
            'evidence' => $task->evidence,
        ]);
        $task->forceFill(['current_completion_id' => $completion->id])->save();

        return $completion;
    }

    /** The caller has evaluated current prerequisite validity under the same aggregate lock. */
    public function record(ItTicket $ticket, ItWorkTask $task, User $actor, array $evidence, ?string $note, iterable $dependencies): ItWorkTaskCompletion
    {
        $this->assertContext($ticket, $task);
        if ($task->status === 'completed' || $task->current_completion_id !== null) {
            throw new DomainException('Reopen the current completion before recording another one.');
        }
        $bindings = [];
        foreach ($dependencies as $dependency) {
            $this->assertContext($ticket, $dependency);
            if ($dependency->status !== 'completed') {
                throw new DomainException('Complete every prerequisite before completing this task.');
            }
            $completion = $this->current($dependency);
            $bindings[] = ['task_id' => (int) $dependency->id, 'completion_id' => (int) $completion->id];
        }
        usort($bindings, fn (array $a, array $b): int => $a['task_id'] <=> $b['task_id']);
        if ($task->approval_id !== null) {
            $approval = ItTicketApproval::query()->whereKey($task->approval_id)
                ->where('it_ticket_id', $ticket->id)->lockForUpdate()->first();
            if (! $approval || $approval->status !== 'approved') {
                throw new DomainException('This task needs its linked approval before it can be completed.');
            }
        }

        return $task->completions()->create([
            'sequence' => (int) $task->completions()->max('sequence') + 1,
            'source' => ItWorkTaskCompletion::SOURCE_COMMAND,
            'recorded_at' => $completedAt = now(),
            'completed_at' => $completedAt,
            'completed_by_user_id' => $actor->id,
            'recorded_by_user_id' => $actor->id,
            'task_definition' => $this->definition($task),
            'prerequisite_completions' => $bindings,
            'approval_id' => $task->approval_id,
            'completion_note' => $note,
            'evidence' => $evidence,
        ]);
    }

    public function current(ItWorkTask $task): ItWorkTaskCompletion
    {
        $completion = $task->completions()->whereKey($task->current_completion_id)->first();
        if (! $completion) {
            throw new DomainException('The current completion does not belong to this task. Review its recorded history.');
        }

        return $completion;
    }

    private function assertContext(ItTicket $ticket, ItWorkTask $task): void
    {
        $this->requireStorage();
        if (DB::transactionLevel() < 1 || (int) $task->ticket_id !== (int) $ticket->id) {
            throw new LogicException('Completion recording requires the locked canonical ticket and its task.');
        }
    }

    private function definition(ItWorkTask $task): array
    {
        return [
            'title' => $task->title, 'description' => $task->description,
            'is_required' => (bool) $task->is_required, 'evidence_required' => (bool) $task->evidence_required,
            'team_id' => $task->team_id === null ? null : (int) $task->team_id,
            'assigned_to_user_id' => $task->assigned_to_user_id === null ? null : (int) $task->assigned_to_user_id,
            'due_at' => $task->due_at?->toIso8601String(),
        ];
    }
}
