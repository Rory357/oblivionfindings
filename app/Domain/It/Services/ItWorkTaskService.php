<?php

namespace App\Domain\It\Services;

use App\Domain\It\ItStaffDirectory;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\ItWorkTask;
use App\Models\User;
use App\Support\LegacyStorageContext;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ItWorkTaskService
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(ItTicket $ticket, User $actor, array $data): ItWorkTask
    {
        return DB::transaction(function () use ($ticket, $actor, $data): ItWorkTask {
            $ticket = $this->lockedTicket($ticket, $actor);
            $this->guardAssignment($data, $ticket);
            $dependencies = $this->dependencies($ticket, (array) ($data['dependency_ids'] ?? []));
            $sortOrder = array_key_exists('sort_order', $data)
                ? (int) $data['sort_order']
                : ((int) $ticket->tasks()->max('sort_order') + 10);

            $task = $ticket->tasks()->create([
                ...Arr::except($data, ['dependency_ids']),
                'tenant_id' => LegacyStorageContext::id(),
                'status' => 'pending',
                'is_required' => (bool) ($data['is_required'] ?? true),
                'evidence_required' => (bool) ($data['evidence_required'] ?? false),
                'sort_order' => $sortOrder,
            ]);
            $task->dependencies()->sync($dependencies->modelKeys());

            ItTicketEvent::record($ticket, 'work_task_created', $actor->id, [
                'task_id' => $task->id,
                'title' => $task->title,
                'is_required' => $task->is_required,
                'dependency_ids' => $dependencies->modelKeys(),
            ]);

            return $task->fresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ItTicket $ticket, ItWorkTask $task, User $actor, array $data): ItWorkTask
    {
        return DB::transaction(function () use ($ticket, $task, $actor, $data): ItWorkTask {
            $ticket = $this->lockedTicket($ticket, $actor);
            $task = $this->lockedTask($ticket, $task);
            if ($task->status === 'completed') {
                throw new DomainException('Reopen a completed task before changing it.');
            }

            $this->guardAssignment($data, $ticket);
            $dependencyChanged = array_key_exists('dependency_ids', $data);
            $dependencies = $dependencyChanged
                ? $this->dependencies($ticket, (array) $data['dependency_ids'], $task)
                : collect();

            $task->fill(Arr::except($data, ['dependency_ids']));
            $changed = array_keys($task->getDirty());
            $task->save();
            if ($dependencyChanged) {
                $before = $task->dependencies()->pluck('it_work_tasks.id')->map(fn ($id) => (int) $id)->all();
                $after = array_map('intval', $dependencies->modelKeys());
                sort($before);
                sort($after);
                $dependencyChanged = $before !== $after;
                $task->dependencies()->sync($after);
            }

            if ($changed !== [] || $dependencyChanged) {
                ItTicketEvent::record($ticket, 'work_task_updated', $actor->id, [
                    'task_id' => $task->id,
                    'title' => $task->title,
                    'changed_fields' => $changed,
                    'dependency_ids' => $dependencyChanged ? $after : null,
                ]);
            }

            return $task->fresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function complete(ItTicket $ticket, ItWorkTask $task, User $actor, array $data): ItWorkTask
    {
        return DB::transaction(function () use ($ticket, $task, $actor, $data): ItWorkTask {
            $ticket = $this->lockedTicket($ticket, $actor);
            $task = $this->lockedTask($ticket, $task);
            if ($task->status === 'completed') {
                throw new DomainException('This task is already completed.');
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

            $task->forceFill([
                'status' => 'completed',
                'evidence' => $evidence === [] ? null : $evidence,
                'completion_note' => $data['completion_note'] ?? null,
                'completed_by_user_id' => $actor->id,
                'completed_at' => now(),
            ])->save();

            ItTicketEvent::record($ticket, 'work_task_completed', $actor->id, [
                'task_id' => $task->id,
                'title' => $task->title,
                'evidence_count' => count($evidence),
            ]);

            return $task->fresh();
        });
    }

    public function reopen(ItTicket $ticket, ItWorkTask $task, User $actor, string $reason): ItWorkTask
    {
        return DB::transaction(function () use ($ticket, $task, $actor, $reason): ItWorkTask {
            $ticket = $this->lockedTicket($ticket, $actor);
            $task = $this->lockedTask($ticket, $task);
            if ($task->status !== 'completed') {
                throw new DomainException('Only a completed task can be reopened.');
            }

            $task->forceFill([
                'status' => 'pending',
                'evidence' => null,
                'completion_note' => null,
                'completed_by_user_id' => null,
                'completed_at' => null,
            ])->save();

            ItTicketEvent::record($ticket, 'work_task_reopened', $actor->id, [
                'task_id' => $task->id,
                'title' => $task->title,
                'reason' => $reason,
            ]);

            return $task->fresh();
        });
    }

    private function lockedTicket(ItTicket $ticket, User $actor): ItTicket
    {
        $locked = ItTicket::query()
            ->whereKey($ticket->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (! $this->workAccess->canWork($actor, $locked)) {
            throw new DomainException('You are not allowed to manage IT work tasks.');
        }

        return $locked;
    }

    private function lockedTask(ItTicket $ticket, ItWorkTask $task): ItWorkTask
    {
        $scoped = ItWorkTask::query()
            ->whereKey($task->id)
            ->where('ticket_id', $ticket->id)
            ->lockForUpdate()
            ->first();
        if (! $scoped) {
            throw new DomainException('That task does not belong to this IT work item.');
        }

        return $scoped;
    }

    /** @param array<string, mixed> $data */
    private function guardAssignment(array $data, ItTicket $ticket): void
    {
        if (array_key_exists('team_id', $data) && $data['team_id'] !== null
            && ! ItTeam::query()->whereKey($data['team_id'])->where('is_active', true)->exists()) {
            throw new DomainException('Choose a current IT team.');
        }

        if (! array_key_exists('assigned_to_user_id', $data) || $data['assigned_to_user_id'] === null) {
            return;
        }

        if (! ItStaffDirectory::agentsForTicket($ticket)->contains(
            'id',
            (int) $data['assigned_to_user_id'],
        )) {
            throw new DomainException('Choose a current IT technician with access to this Site.');
        }
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return Collection<int, ItWorkTask>
     */
    private function dependencies(ItTicket $ticket, array $ids, ?ItWorkTask $task = null)
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $dependencies = ItWorkTask::query()
            ->where('ticket_id', $ticket->id)
            ->whereIn('id', $ids)
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
}
