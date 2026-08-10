<?php

namespace App\Domain\It\Services;

use App\Domain\It\ItStaffDirectory;
use App\Models\ItQueue;
use App\Models\ItService;
use App\Models\ItTeam;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class ItServiceManagementSetupService
{
    public function __construct(private readonly ItWorkAccessService $workAccess) {}

    /** @param array<string, mixed> $data */
    public function createTeam(User $actor, array $data): ItTeam
    {
        return DB::transaction(function () use ($actor, $data): ItTeam {
            $this->guardActor($actor);
            $this->guardAgents($this->teamUserIds($data));
            $team = ItTeam::query()->create([
                ...Arr::only($data, ['manager_user_id', 'name', 'description', 'is_active']),
            ]);
            $this->syncMembers($team, (array) ($data['members'] ?? []));
            AuditLogger::logOrFail('it.setup.team.created', $team, [
                'application_scope' => 'single_installation',
                'member_count' => count((array) ($data['members'] ?? [])),
            ]);

            return $team->fresh(['manager', 'members']);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateTeam(ItTeam $team, User $actor, array $data): ItTeam
    {
        return DB::transaction(function () use ($team, $actor, $data): ItTeam {
            $team = $this->lockedTeam($team, $actor);
            $this->guardAgents($this->teamUserIds($data));
            $before = $team->only(['manager_user_id', 'name', 'description', 'is_active']);
            $team->fill(Arr::only($data, ['manager_user_id', 'name', 'description', 'is_active']))->save();
            if (array_key_exists('members', $data)) {
                $this->syncMembers($team, (array) $data['members']);
            }
            AuditLogger::logOrFail('it.setup.team.updated', $team, [
                'application_scope' => 'single_installation',
                'before' => $before,
                'changed_fields' => array_keys($team->getChanges()),
            ]);

            return $team->fresh(['manager', 'members']);
        });
    }

    /** @param array<string, mixed> $data */
    public function createQueue(User $actor, array $data): ItQueue
    {
        return DB::transaction(function () use ($actor, $data): ItQueue {
            $this->guardActor($actor);
            $this->guardQueueDefaults($actor, $data);
            $this->guardRoutingScope($actor, $data);
            $queue = ItQueue::query()->create([
                ...Arr::only($data, ['team_id', 'key', 'name', 'description', 'is_active']),
                'filter_rules' => $this->filterRules($data),
            ]);
            AuditLogger::logOrFail('it.setup.queue.created', $queue, [
                'application_scope' => 'single_installation',
                'routing_fields' => array_keys(array_filter($queue->filter_rules ?? [], fn ($value) => $value !== [] && $value !== null)),
            ]);

            return $queue->fresh('team');
        });
    }

    /** @param array<string, mixed> $data */
    public function updateQueue(ItQueue $queue, User $actor, array $data): ItQueue
    {
        return DB::transaction(function () use ($queue, $actor, $data): ItQueue {
            $queue = $this->lockedQueue($queue, $actor);
            $this->guardQueueDefaults($actor, [
                'team_id' => $data['team_id'] ?? $queue->team_id,
                'default_assignee_user_id' => array_key_exists('default_assignee_user_id', $data)
                    ? $data['default_assignee_user_id']
                    : ($queue->filter_rules['default_assignee_user_id'] ?? null),
            ]);
            if (array_key_exists('site_ids', $data)) {
                $this->guardRoutingScope($actor, $data);
            }
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
                'application_scope' => 'single_installation',
                'before' => $before,
                'changed_fields' => array_keys($queue->getChanges()),
            ]);

            return $queue->fresh('team');
        });
    }

    /** @param array<string, mixed> $data */
    public function createService(User $actor, array $data): ItService
    {
        return DB::transaction(function () use ($actor, $data): ItService {
            $this->guardActor($actor);
            $this->guardAgents(array_filter([$data['owner_user_id'] ?? null]));
            $service = ItService::query()->create([
                ...Arr::only($data, ['owner_user_id', 'key', 'name', 'description', 'status', 'criticality', 'is_active']),
            ]);
            AuditLogger::logOrFail('it.setup.service.created', $service, [
                'application_scope' => 'single_installation',
                'owner_user_id' => $service->owner_user_id,
            ]);

            return $service->fresh('owner');
        });
    }

    /** @param array<string, mixed> $data */
    public function updateService(ItService $service, User $actor, array $data): ItService
    {
        return DB::transaction(function () use ($service, $actor, $data): ItService {
            $service = $this->lockedService($service, $actor);
            if (array_key_exists('owner_user_id', $data)) {
                $this->guardAgents(array_filter([$data['owner_user_id']]));
            }
            $before = $service->only(['owner_user_id', 'key', 'name', 'description', 'status', 'criticality', 'is_active']);
            $service->fill(Arr::only($data, ['owner_user_id', 'key', 'name', 'description', 'status', 'criticality', 'is_active']))->save();
            AuditLogger::logOrFail('it.setup.service.updated', $service, [
                'application_scope' => 'single_installation',
                'before' => $before,
                'changed_fields' => array_keys($service->getChanges()),
            ]);

            return $service->fresh('owner');
        });
    }

    private function lockedTeam(ItTeam $team, User $actor): ItTeam
    {
        $this->guardActor($actor);

        return ItTeam::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
    }

    private function lockedQueue(ItQueue $queue, User $actor): ItQueue
    {
        $this->guardActor($actor);

        return ItQueue::query()->whereKey($queue->id)->lockForUpdate()->firstOrFail();
    }

    private function lockedService(ItService $service, User $actor): ItService
    {
        $this->guardActor($actor);

        return ItService::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();
    }

    private function guardActor(User $actor): void
    {
        if (! $actor->canDo('it.manage')) {
            throw new DomainException('You are not allowed to configure IT service management.');
        }
    }

    /** @param array<int, mixed> $userIds */
    private function guardAgents(array $userIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if ($ids === []) {
            return;
        }
        $agents = ItStaffDirectory::agents()->pluck('id')->map(fn ($id) => (int) $id);
        if (collect($ids)->diff($agents)->isNotEmpty()) {
            throw new DomainException('Team managers, members, owners, and default assignees must be current IT agents.');
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
    private function guardQueueDefaults(User $actor, array $data): void
    {
        $teamId = ! empty($data['team_id']) ? (int) $data['team_id'] : null;
        $assigneeId = ! empty($data['default_assignee_user_id']) ? (int) $data['default_assignee_user_id'] : null;
        if ($assigneeId === null) {
            return;
        }
        $this->guardAgents([$assigneeId]);
        if ($teamId !== null && ! ItTeam::query()
            ->whereKey($teamId)
            ->whereHas('members', fn ($query) => $query->whereKey($assigneeId))
            ->exists()) {
            throw new DomainException('The default assignee must be a member of the queue team.');
        }
    }

    /** @param array<string, mixed> $data */
    private function guardRoutingScope(User $actor, array $data): void
    {
        $siteIds = array_values(array_unique(array_map('intval', (array) ($data['site_ids'] ?? []))));
        if ($siteIds === []) {
            return;
        }

        $operational = Site::query()
            ->whereKey($siteIds)
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);
        if ($operational->count() !== count($siteIds)) {
            throw new DomainException('Routing can only use active Sites.');
        }

        if (! $actor->canDo('it.organisationWide')
            && collect($siteIds)->diff($this->workAccess->approvedSiteIds($actor))->isNotEmpty()) {
            throw new DomainException('Routing can only use Sites in your approved Site access.');
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
