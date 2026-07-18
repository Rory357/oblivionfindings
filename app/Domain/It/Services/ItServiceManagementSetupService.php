<?php

namespace App\Domain\It\Services;

use App\Domain\It\ItStaffDirectory;
use App\Models\ItQueue;
use App\Models\ItService;
use App\Models\ItTeam;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class ItServiceManagementSetupService
{
    /** @param array<string, mixed> $data */
    public function createTeam(User $actor, int $tenantId, array $data): ItTeam
    {
        return DB::transaction(function () use ($tenantId, $data): ItTeam {
            $this->guardAgents($tenantId, $this->teamUserIds($data));
            $team = ItTeam::query()->create([
                'tenant_id' => $tenantId,
                ...Arr::only($data, ['manager_user_id', 'name', 'description', 'is_active']),
            ]);
            $this->syncMembers($team, (array) ($data['members'] ?? []));
            AuditLogger::logOrFail('it.setup.team.created', $team, [
                'organization_id' => $tenantId,
                'member_count' => count((array) ($data['members'] ?? [])),
            ]);

            return $team->fresh(['manager', 'members']);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateTeam(ItTeam $team, User $actor, int $tenantId, array $data): ItTeam
    {
        return DB::transaction(function () use ($team, $actor, $tenantId, $data): ItTeam {
            $team = $this->lockedTeam($team, $actor, $tenantId);
            $this->guardAgents($tenantId, $this->teamUserIds($data));
            $before = $team->only(['manager_user_id', 'name', 'description', 'is_active']);
            $team->fill(Arr::only($data, ['manager_user_id', 'name', 'description', 'is_active']))->save();
            if (array_key_exists('members', $data)) {
                $this->syncMembers($team, (array) $data['members']);
            }
            AuditLogger::logOrFail('it.setup.team.updated', $team, [
                'organization_id' => $tenantId,
                'before' => $before,
                'changed_fields' => array_keys($team->getChanges()),
            ]);

            return $team->fresh(['manager', 'members']);
        });
    }

    /** @param array<string, mixed> $data */
    public function createQueue(User $actor, int $tenantId, array $data): ItQueue
    {
        return DB::transaction(function () use ($tenantId, $data): ItQueue {
            $this->guardQueueDefaults($tenantId, $data);
            $queue = ItQueue::query()->create([
                'tenant_id' => $tenantId,
                ...Arr::only($data, ['team_id', 'key', 'name', 'description', 'is_active']),
                'filter_rules' => $this->filterRules($data),
            ]);
            AuditLogger::logOrFail('it.setup.queue.created', $queue, [
                'organization_id' => $tenantId,
                'routing_fields' => array_keys(array_filter($queue->filter_rules ?? [], fn ($value) => $value !== [] && $value !== null)),
            ]);

            return $queue->fresh('team');
        });
    }

    /** @param array<string, mixed> $data */
    public function updateQueue(ItQueue $queue, User $actor, int $tenantId, array $data): ItQueue
    {
        return DB::transaction(function () use ($queue, $actor, $tenantId, $data): ItQueue {
            $queue = $this->lockedQueue($queue, $actor, $tenantId);
            $this->guardQueueDefaults($tenantId, [
                'team_id' => $data['team_id'] ?? $queue->team_id,
                'default_assignee_user_id' => array_key_exists('default_assignee_user_id', $data)
                    ? $data['default_assignee_user_id']
                    : ($queue->filter_rules['default_assignee_user_id'] ?? null),
            ]);
            $before = $queue->only(['team_id', 'key', 'name', 'description', 'filter_rules', 'is_active']);
            $queue->fill(Arr::only($data, ['team_id', 'key', 'name', 'description', 'is_active']));
            if ($this->hasRoutingData($data)) {
                $queue->filter_rules = $this->filterRules([
                    ...($queue->filter_rules ?? []),
                    ...$data,
                ]);
            }
            $queue->save();
            AuditLogger::logOrFail('it.setup.queue.updated', $queue, [
                'organization_id' => $tenantId,
                'before' => $before,
                'changed_fields' => array_keys($queue->getChanges()),
            ]);

            return $queue->fresh('team');
        });
    }

    /** @param array<string, mixed> $data */
    public function createService(User $actor, int $tenantId, array $data): ItService
    {
        return DB::transaction(function () use ($tenantId, $data): ItService {
            $this->guardAgents($tenantId, array_filter([$data['owner_user_id'] ?? null]));
            $service = ItService::query()->create([
                'tenant_id' => $tenantId,
                ...Arr::only($data, ['owner_user_id', 'key', 'name', 'description', 'status', 'criticality', 'is_active']),
            ]);
            AuditLogger::logOrFail('it.setup.service.created', $service, [
                'organization_id' => $tenantId,
                'owner_user_id' => $service->owner_user_id,
            ]);

            return $service->fresh('owner');
        });
    }

    /** @param array<string, mixed> $data */
    public function updateService(ItService $service, User $actor, int $tenantId, array $data): ItService
    {
        return DB::transaction(function () use ($service, $actor, $tenantId, $data): ItService {
            $service = $this->lockedService($service, $actor, $tenantId);
            if (array_key_exists('owner_user_id', $data)) {
                $this->guardAgents($tenantId, array_filter([$data['owner_user_id']]));
            }
            $before = $service->only(['owner_user_id', 'key', 'name', 'description', 'status', 'criticality', 'is_active']);
            $service->fill(Arr::only($data, ['owner_user_id', 'key', 'name', 'description', 'status', 'criticality', 'is_active']))->save();
            AuditLogger::logOrFail('it.setup.service.updated', $service, [
                'organization_id' => $tenantId,
                'before' => $before,
                'changed_fields' => array_keys($service->getChanges()),
            ]);

            return $service->fresh('owner');
        });
    }

    private function lockedTeam(ItTeam $team, User $actor, int $tenantId): ItTeam
    {
        $this->guardActor($actor);

        return ItTeam::query()->whereKey($team->id)->where('tenant_id', $tenantId)->lockForUpdate()->firstOrFail();
    }

    private function lockedQueue(ItQueue $queue, User $actor, int $tenantId): ItQueue
    {
        $this->guardActor($actor);

        return ItQueue::query()->whereKey($queue->id)->where('tenant_id', $tenantId)->lockForUpdate()->firstOrFail();
    }

    private function lockedService(ItService $service, User $actor, int $tenantId): ItService
    {
        $this->guardActor($actor);

        return ItService::query()->whereKey($service->id)->where('tenant_id', $tenantId)->lockForUpdate()->firstOrFail();
    }

    private function guardActor(User $actor): void
    {
        if (! $actor->canDo('it.manage')) {
            throw new DomainException('You are not allowed to configure IT service management.');
        }
    }

    /** @param array<int, mixed> $userIds */
    private function guardAgents(int $tenantId, array $userIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if ($ids === []) {
            return;
        }
        $agents = ItStaffDirectory::agents($tenantId)->pluck('id')->map(fn ($id) => (int) $id);
        if (collect($ids)->diff($agents)->isNotEmpty()) {
            throw new DomainException('Team managers, members, owners, and default assignees must be IT agents in this organisation.');
        }
    }

    /** @param array<string, mixed> $data @return array<int, mixed> */
    private function teamUserIds(array $data): array
    {
        return array_filter([
            $data['manager_user_id'] ?? null,
            ...array_column((array) ($data['members'] ?? []), 'user_id'),
        ]);
    }

    /** @param array<int, array<string, mixed>> $members */
    private function syncMembers(ItTeam $team, array $members): void
    {
        $sync = [];
        foreach ($members as $member) {
            $sync[(int) $member['user_id']] = ['role' => (string) $member['role']];
        }
        $team->members()->sync($sync);
    }

    /** @param array<string, mixed> $data */
    private function guardQueueDefaults(int $tenantId, array $data): void
    {
        $teamId = ! empty($data['team_id']) ? (int) $data['team_id'] : null;
        $assigneeId = ! empty($data['default_assignee_user_id']) ? (int) $data['default_assignee_user_id'] : null;
        if ($assigneeId === null) {
            return;
        }
        $this->guardAgents($tenantId, [$assigneeId]);
        if ($teamId !== null && ! ItTeam::query()
            ->whereKey($teamId)
            ->where('tenant_id', $tenantId)
            ->whereHas('members', fn ($query) => $query->whereKey($assigneeId))
            ->exists()) {
            throw new DomainException('The default assignee must be a member of the queue team.');
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function filterRules(array $data): array
    {
        return [
            'routing_priority' => (int) ($data['routing_priority'] ?? 0),
            'is_default' => (bool) ($data['is_default'] ?? false),
            'work_types' => array_values((array) ($data['work_types'] ?? [])),
            'categories' => array_values((array) ($data['categories'] ?? [])),
            'priorities' => array_values((array) ($data['priorities'] ?? [])),
            'service_ids' => array_values(array_map('intval', (array) ($data['service_ids'] ?? []))),
            'site_ids' => array_values(array_map('intval', (array) ($data['site_ids'] ?? []))),
            'default_assignee_user_id' => ! empty($data['default_assignee_user_id'])
                ? (int) $data['default_assignee_user_id']
                : null,
        ];
    }

    /** @param array<string, mixed> $data */
    private function hasRoutingData(array $data): bool
    {
        return array_intersect(array_keys($data), [
            'routing_priority', 'is_default', 'work_types', 'categories', 'priorities',
            'service_ids', 'site_ids', 'default_assignee_user_id',
        ]) !== [];
    }
}
