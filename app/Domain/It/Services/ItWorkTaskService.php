<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItWorkTaskInput;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItTicketEvent;
use App\Models\ItWorkTask;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ItWorkTaskService
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly ItTicketRoutingEligibility $eligibility,
        private readonly ItWorkTaskCompletionService $completions,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(ItTicket $ticket, User $actor, array $data): ItWorkTask
    {
        return DB::transaction(function () use ($ticket, $actor, $data): ItWorkTask {
            [$ticket, $actor] = $this->lockContext($ticket, $actor, $data);
            $this->completions->requireStorage();
            $data = ItWorkTaskInput::normalize('create', $data);
            $this->guardAssignment($data, $ticket);
            $this->guardApproval($data, $ticket);
            $dependencies = $this->dependencies($ticket, (array) ($data['dependency_ids'] ?? []));
            $sortOrder = array_key_exists('sort_order', $data)
                ? (int) $data['sort_order']
                : ((int) $ticket->tasks()->max('sort_order') + 10);

            $task = $ticket->tasks()->create([
                ...Arr::except($data, ['dependency_ids']),
                'status' => 'pending',
                'is_required' => (bool) ($data['is_required'] ?? true),
                'evidence_required' => (bool) ($data['evidence_required'] ?? false),
                'sort_order' => $sortOrder,
            ]);
            $task->dependencies()->sync($dependencies->modelKeys());
            $this->advanceVersion($ticket);

            ItTicketEvent::record($ticket, 'work_task_created', $actor->id, [
                'task_id' => $task->id,
                'title' => $task->title,
                'is_required' => $task->is_required,
                'dependency_ids' => $dependencies->modelKeys(),
            ]);
            AuditLogger::logOrFail(
                'it.ticket.task.created',
                $ticket,
                $this->auditMeta($ticket, $task, $actor, [
                    'is_required' => $task->is_required,
                    'dependency_ids' => $dependencies->modelKeys(),
                ]),
            );

            return $task->fresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ItTicket $ticket, ItWorkTask $task, User $actor, array $data): ItWorkTask
    {
        return DB::transaction(function () use ($ticket, $task, $actor, $data): ItWorkTask {
            [$ticket, $actor] = $this->lockContext($ticket, $actor, $data);
            $this->completions->requireStorage();
            $task = $this->lockedTask($ticket, $task);
            $data = ItWorkTaskInput::normalize('update', $data);
            if ($task->status === 'completed') {
                throw new DomainException('Reopen a completed task before changing it.');
            }

            $this->guardAssignment($data, $ticket, $task);
            $this->guardApproval($data, $ticket, $task);
            $dependencyChanged = array_key_exists('dependency_ids', $data);
            $dependencies = $dependencyChanged
                ? $this->dependencies($ticket, (array) $data['dependency_ids'], $task)
                : collect();

            $before = $task->dependencies()->pluck('it_work_tasks.id')->map(fn ($id) => (int) $id)->all();
            $after = $dependencyChanged ? array_map('intval', $dependencies->modelKeys()) : $before;
            sort($before);
            sort($after);
            $dependencyChanged = $before !== $after;
            $beforeStatus = $task->status;
            $beforeRequired = (bool) $task->is_required;
            $beforeApprovalId = $task->approval_id;
            $willBeRequired = (bool) ($data['is_required'] ?? $task->is_required);
            $approvalChanged = array_key_exists('approval_id', $data)
                && $data['approval_id'] !== $task->approval_id;

            $nextStatus = (string) ($data['status'] ?? $task->status);
            if ($nextStatus === 'cancelled') {
                if ($willBeRequired) {
                    throw new DomainException('Required tasks cannot be cancelled. Make the task optional or complete it.');
                }
            }
            $cancelling = $beforeStatus !== 'cancelled' && $nextStatus === 'cancelled';
            $restoring = $beforeStatus === 'cancelled' && $nextStatus !== 'cancelled';
            if ($restoring && $nextStatus !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Restore this task to Pending, then review its prerequisites before starting work.']);
            }
            $requiresReason = $cancelling || $restoring || $dependencyChanged
                || $beforeRequired !== $willBeRequired || $approvalChanged;
            if ($requiresReason && blank($data['reason'] ?? null)) {
                throw ValidationException::withMessages(['reason' => $cancelling
                    ? 'Record why this optional task is being cancelled.'
                    : ($restoring ? 'Record why this cancelled task is being restored.'
                        : 'Explain this change to required work or prerequisites before saving.')]);
            }

            $task->fill(Arr::except($data, ['dependency_ids', 'reason']));
            $changed = array_keys($task->getDirty());
            if ($changed !== []) {
                $task->save();
            }
            if ($dependencyChanged) {
                $task->dependencies()->sync($after);
            }
            if ($nextStatus === 'in_progress'
                && ($beforeStatus !== 'in_progress' || $dependencyChanged || $approvalChanged)) {
                app(ItWorkTaskReadinessService::class)->guardAction($ticket, $task, $actor, 'start');
            }

            if ($changed !== [] || $dependencyChanged) {
                $this->advanceVersion($ticket);
                $event = $cancelling ? 'work_task_cancelled' : ($restoring ? 'work_task_restored' : 'work_task_updated');
                $provenance = [
                    'from' => $beforeStatus, 'to' => $nextStatus,
                    'was_required' => $beforeRequired, 'is_required' => $willBeRequired,
                    'previous_dependency_ids' => $dependencyChanged ? $before : null,
                    'dependency_ids' => $dependencyChanged ? $after : null,
                    'previous_approval_id' => $approvalChanged ? $beforeApprovalId : null,
                    'approval_id' => $approvalChanged ? $task->approval_id : null,
                    'reason' => $requiresReason ? $data['reason'] : null,
                ];
                ItTicketEvent::record($ticket, $event, $actor->id, [
                    'task_id' => $task->id,
                    'title' => $task->title,
                    'changed_fields' => $changed,
                    ...$provenance,
                ]);
                AuditLogger::logOrFail(
                    'it.ticket.task.updated',
                    $ticket,
                    $this->auditMeta($ticket, $task, $actor, [
                        'changed_fields' => $changed,
                        ...$provenance,
                    ]),
                );
            }

            return $task->fresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function complete(ItTicket $ticket, ItWorkTask $task, User $actor, array $data): ItWorkTask
    {
        return DB::transaction(function () use ($ticket, $task, $actor, $data): ItWorkTask {
            [$ticket, $actor] = $this->lockContext($ticket, $actor);
            $task = $this->lockedTask($ticket, $task);
            $data = ItWorkTaskInput::normalize('complete', $data);
            if ($task->status === 'completed') {
                throw new DomainException('This task is already completed.');
            }
            if ($task->status === 'cancelled') {
                throw new DomainException('Restore this cancelled task before completing it.');
            }
            if ($task->dependencies()->where('status', '!=', 'completed')->exists()) {
                throw new DomainException('Complete every dependency before completing this task.');
            }

            $evidence = array_values(array_filter(
                (array) ($data['evidence'] ?? []),
                fn (mixed $value) => filled($value),
            ));
            if ($task->evidence_required && $evidence === []) {
                throw new DomainException('Evidence is required before completing this task.');
            }

            $dependencies = $this->dependencies($ticket, $task->dependencies()->pluck('it_work_tasks.id')->all(), $task);
            $this->completions->requireStorage();
            foreach ($dependencies as $dependency) {
                $this->completions->captureLegacy($ticket, $dependency, $actor);
            }
            app(ItWorkTaskReadinessService::class)->guardAction($ticket, $task, $actor, 'complete');
            $completion = $this->completions->record($ticket, $task, $actor, $evidence, $data['completion_note'] ?? null, $dependencies);

            $task->forceFill([
                'current_completion_id' => $completion->id,
                'status' => 'completed',
                'evidence' => $evidence === [] ? null : $evidence,
                'completion_note' => $data['completion_note'] ?? null,
                'completed_by_user_id' => $actor->id,
                'completed_at' => $completion->completed_at,
            ])->save();
            $this->advanceVersion($ticket);

            ItTicketEvent::record($ticket, 'work_task_completed', $actor->id, [
                'task_id' => $task->id,
                'title' => $task->title,
                'evidence_count' => count($evidence),
                'completion_id' => $completion->id,
                'completion_sequence' => $completion->sequence,
            ]);
            AuditLogger::logOrFail(
                'it.ticket.task.completed',
                $ticket,
                $this->auditMeta($ticket, $task, $actor, [
                    'evidence_count' => count($evidence),
                    'completion_id' => $completion->id,
                    'completion_sequence' => $completion->sequence,
                ]),
            );

            return $task->fresh();
        });
    }

    public function reopen(ItTicket $ticket, ItWorkTask $task, User $actor, string $reason): ItWorkTask
    {
        return DB::transaction(function () use ($ticket, $task, $actor, $reason): ItWorkTask {
            [$ticket, $actor] = $this->lockContext($ticket, $actor);
            $task = $this->lockedTask($ticket, $task);
            $reason = ItWorkTaskInput::normalize('reopen', ['reason' => $reason])['reason'];
            if ($task->status !== 'completed') {
                throw new DomainException('Only a completed task can be reopened.');
            }

            $completion = $this->completions->captureLegacy($ticket, $task, $actor);

            $task->forceFill([
                'current_completion_id' => null,
                'status' => 'pending',
                'evidence' => null,
                'completion_note' => null,
                'completed_by_user_id' => null,
                'completed_at' => null,
            ])->save();
            $this->advanceVersion($ticket);

            ItTicketEvent::record($ticket, 'work_task_reopened', $actor->id, [
                'task_id' => $task->id,
                'title' => $task->title,
                'reason' => $reason,
                'completion_id' => $completion->id,
                'completion_sequence' => $completion->sequence,
            ]);
            AuditLogger::logOrFail(
                'it.ticket.task.reopened',
                $ticket,
                $this->auditMeta($ticket, $task, $actor, [
                    'reason' => $reason, 'completion_id' => $completion->id,
                    'completion_sequence' => $completion->sequence,
                ]),
            );

            return $task->fresh();
        });
    }

    public function reorder(ItTicket $ticket, User $actor, array $orderedIds): bool
    {
        return DB::transaction(function () use ($ticket, $actor, $orderedIds): bool {
            [$ticket, $actor] = $this->lockContext($ticket, $actor);
            $orderedIds = ItWorkTaskInput::normalize('reorder', ['ordered_ids' => $orderedIds])['ordered_ids'];
            $this->completions->requireStorage();
            $tasks = $ticket->tasks()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $expected = $tasks->modelKeys();
            $provided = $orderedIds;
            sort($expected);
            sort($provided);
            if ($expected !== $provided) {
                throw new DomainException('Use the complete current task list exactly once when changing its order.');
            }
            $current = $tasks->sortBy(fn (ItWorkTask $task) => [$task->sort_order, $task->id])->values()->modelKeys();
            if ($current === $orderedIds) {
                return false;
            }
            foreach ($orderedIds as $index => $id) {
                $tasks->get($id)->forceFill(['sort_order' => ($index + 1) * 10])->save();
            }
            $this->advanceVersion($ticket);
            ItTicketEvent::record($ticket, 'work_tasks_reordered', $actor->id, ['ordered_ids' => $orderedIds]);
            AuditLogger::logOrFail('it.ticket.tasks.reordered', $ticket, [
                'actor_id' => $actor->id, 'ordered_ids' => $orderedIds,
                'application_scope' => 'single_application',
            ]);

            return true;
        });
    }

    private function advanceVersion(ItTicket $ticket): void
    {
        $ticket->forceFill(['lock_version' => (int) $ticket->lock_version + 1])->save();
    }

    /** Caller must already own a transaction. All participating people lock in ID order. */
    public function lockContext(ItTicket $ticket, User $actor, array $input = [], bool $mutation = true): array
    {
        $locked = ItTicket::query()
            ->whereKey($ticket->id)
            ->lockForUpdate()
            ->firstOrFail();

        $ids = [(int) $actor->id];
        if (isset($input['assigned_to_user_id']) && filter_var($input['assigned_to_user_id'], FILTER_VALIDATE_INT) !== false) {
            $ids[] = (int) $input['assigned_to_user_id'];
        }
        $people = User::query()->whereIn('id', array_unique($ids))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $current = $people->get((int) $actor->id);
        if (! $current || $current->approved_at === null) {
            throw new AuthorizationException('Your staff access is no longer available.');
        }
        if (! $this->workAccess->canWork($current, $locked) || ! $current->canDo('it.manage')) {
            throw new AuthorizationException('You are not allowed to manage IT work tasks.');
        }
        if ($mutation && ($locked->isMerged() || ! in_array($locked->status, ItTicket::OPEN_STATUSES, true))) {
            throw new DomainException('Reopen this ticket before changing its work tasks.');
        }

        return [$locked, $current];
    }

    private function lockedTask(ItTicket $ticket, ItWorkTask $task): ItWorkTask
    {
        $scoped = ItWorkTask::query()
            ->whereKey($task->id)
            ->where('ticket_id', $ticket->id)
            ->lockForUpdate()
            ->first();
        if (! $scoped) {
            throw (new ModelNotFoundException)->setModel(ItWorkTask::class);
        }

        return $scoped;
    }

    /** @param array<string, mixed> $data */
    private function guardAssignment(array $data, ItTicket $ticket, ?ItWorkTask $task = null): void
    {
        if (array_key_exists('team_id', $data) && $data['team_id'] !== null
            && (int) $data['team_id'] !== (int) $task?->team_id
            && ! ItTeam::query()->whereKey($data['team_id'])->where('is_active', true)->exists()) {
            throw new DomainException('Choose a current IT team.');
        }

        if (! array_key_exists('assigned_to_user_id', $data) || $data['assigned_to_user_id'] === null) {
            return;
        }

        if ((int) $data['assigned_to_user_id'] !== (int) $task?->assigned_to_user_id
            && $this->eligibility->agent((int) $data['assigned_to_user_id'], $ticket) === null) {
            throw new DomainException('Choose a current IT technician with access to this Site.');
        }
    }

    private function guardApproval(array $data, ItTicket $ticket, ?ItWorkTask $task = null): void
    {
        if (! array_key_exists('approval_id', $data) || $data['approval_id'] === $task?->approval_id) {
            return;
        }
        $this->completions->requireStorage();
        if ($data['approval_id'] !== null && ! ItTicketApproval::query()
            ->whereKey($data['approval_id'])->where('it_ticket_id', $ticket->id)->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['approval_id' => 'Choose an approval request belonging to this ticket.']);
        }
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return Collection<int, ItWorkTask>
     */
    private function dependencies(ItTicket $ticket, array $ids, ?ItWorkTask $task = null)
    {
        $ids = array_values(array_map('intval', $ids));
        $dependencies = ItWorkTask::query()
            ->where('ticket_id', $ticket->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($dependencies->count() !== count($ids)) {
            throw new DomainException('Every dependency must belong to this IT work item.');
        }
        if ($task && (in_array((int) $task->id, $ids, true) || $this->wouldCycle($task, $ids))) {
            throw new DomainException('Task dependencies cannot contain a cycle.');
        }

        return $dependencies;
    }

    /** @param array<int, int> $dependencyIds */
    private function wouldCycle(ItWorkTask $task, array $dependencyIds): bool
    {
        $frontier = $dependencyIds;
        $visited = [];
        while ($frontier !== []) {
            if (in_array((int) $task->id, $frontier, true)) {
                return true;
            }
            $visited = array_values(array_unique([...$visited, ...$frontier]));
            $frontier = DB::table('it_work_task_dependencies')
                ->whereIn('task_id', $frontier)
                ->pluck('depends_on_task_id')
                ->map(fn ($id) => (int) $id)
                ->reject(fn (int $id) => in_array($id, $visited, true))
                ->values()
                ->all();
        }

        return false;
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function auditMeta(ItTicket $ticket, ItWorkTask $task, User $actor, array $extra = []): array
    {
        return [
            'actor_id' => $actor->id,
            'task_id' => $task->id,
            'task_status' => $task->status,
            'site_id' => $ticket->site_id,
            'is_organisation_wide' => (bool) $ticket->is_organisation_wide,
            'application_scope' => 'single_application',
            ...$extra,
        ];
    }
}
