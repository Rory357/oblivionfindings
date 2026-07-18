<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\ItWorkTask;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ItWorkTaskService
{
    /** @param array<string, mixed> $data */
    public function create(ItTicket $ticket, User $actor, int $tenantId, array $data): ItWorkTask
    {
        return DB::transaction(function () use ($ticket, $actor, $tenantId, $data): ItWorkTask {
            $ticket = $this->lockedTicket($ticket, $actor, $tenantId);
            $this->guardAssignment($data, $tenantId);
            $dependencies = $this->dependencies($ticket, (array) ($data['dependency_ids'] ?? []));
            $sortOrder = array_key_exists('sort_order', $data)
                ? (int) $data['sort_order']
                : ((int) $ticket->tasks()->max('sort_order') + 10);

            $task = $ticket->tasks()->create([
                ...Arr::except($data, ['dependency_ids']),
                'tenant_id' => $tenantId,
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
    public function update(ItTicket $ticket, ItWorkTask $task, User $actor, int $tenantId, array $data): ItWorkTask
    {
        return DB::transaction(function () use ($ticket, $task, $actor, $tenantId, $data): ItWorkTask {
            $ticket = $this->lockedTicket($ticket, $actor, $tenantId);
            $task = $this->lockedTask($ticket, $task, $tenantId);
            if ($task->status === 'completed') {
                throw new DomainException('Reopen a completed task before changing it.');
            }

            $this->guardAssignment($data, $tenantId);
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
    public function complete(ItTicket $ticket, ItWorkTask $task, User $actor, int $tenantId, array $data): ItWorkTask
    {
        return DB::transaction(function () use ($ticket, $task, $actor, $tenantId, $data): ItWorkTask {
            $ticket = $this->lockedTicket($ticket, $actor, $tenantId);
            $task = $this->lockedTask($ticket, $task, $tenantId);
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

    public function reopen(ItTicket $ticket, ItWorkTask $task, User $actor, int $tenantId, string $reason): ItWorkTask
    {
        return DB::transaction(function () use ($ticket, $task, $actor, $tenantId, $reason): ItWorkTask {
            $ticket = $this->lockedTicket($ticket, $actor, $tenantId);
            $task = $this->lockedTask($ticket, $task, $tenantId);
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

    private function lockedTicket(ItTicket $ticket, User $actor, int $tenantId): ItTicket
    {
        if (! $actor->canDo('it.manage')) {
            throw new DomainException('You are not allowed to manage IT work tasks.');
        }

        return ItTicket::query()
            ->whereKey($ticket->id)
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockedTask(ItTicket $ticket, ItWorkTask $task, int $tenantId): ItWorkTask
    {
        $scoped = ItWorkTask::query()
            ->whereKey($task->id)
            ->where('tenant_id', $tenantId)
            ->where('ticket_id', $ticket->id)
            ->lockForUpdate()
            ->first();
        if (! $scoped) {
            throw new DomainException('That task does not belong to this IT work item.');
        }

        return $scoped;
    }

    /** @param array<string, mixed> $data */
    private function guardAssignment(array $data, int $tenantId): void
    {
        if (array_key_exists('team_id', $data) && $data['team_id'] !== null
            && ! ItTeam::query()->whereKey($data['team_id'])->where('tenant_id', $tenantId)->exists()) {
            throw new DomainException('That IT team belongs to a different organisation.');
        }

        if (! array_key_exists('assigned_to_user_id', $data) || $data['assigned_to_user_id'] === null) {
            return;
        }

        $user = User::query()->find((int) $data['assigned_to_user_id']);
        $foreignOrganization = $user?->organization_id !== null
            && (int) $user->organization_id !== $tenantId;
        $foreignProfile = HrEmployeeProfile::query()
            ->where('user_id', $data['assigned_to_user_id'])
            ->whereNotNull('tenant_id')
            ->where('tenant_id', '!=', $tenantId)
            ->exists();
        if (! $user || $foreignOrganization || $foreignProfile) {
            throw new DomainException('That assignee belongs to a different organisation.');
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
            ->where('tenant_id', $ticket->tenant_id)
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
