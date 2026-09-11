<?php

namespace App\Domain\It\Services;

use App\Domain\It\ItStaffDirectory;
use App\Models\ItQueue;
use App\Models\ItService;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ItServiceManagementSetupService
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly ItTicketRoutingEligibility $routingEligibility,
    ) {}

    /**
     * Authorize an exact RAM-only candidate without saving or returning its data.
     * Configuration completeness is deliberately left to the canonical save.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function authorizeCandidate(User $actor, array $data): array
    {
        $actor = $this->reviewActor($actor, (int) $data['actor_user_id']);
        $model = match ($data['resource']) {
            'teams' => ItTeam::class,
            'queues' => ItQueue::class,
            'services' => ItService::class,
        };
        $record = $data['record_id'] !== null ? $model::query()->findOrFail($data['record_id']) : null;
        Gate::forUser($actor)->authorize($record ? 'update' : 'create', $record ?? $model);
        $scopes = (array) $data['bound_scopes'];
        foreach ([$data['base_fields'], $data['fields']] as $fields) {
            $scopes[] = [
                'user_ids' => array_filter([
                    $fields['manager_user_id'] ?? null, $fields['owner_user_id'] ?? null,
                    $fields['default_assignee_user_id'] ?? null, $fields['cover_user_id'] ?? null,
                    ...array_column((array) ($fields['members'] ?? []), 'user_id'),
                ]),
                'site_ids' => $fields['site_ids'] ?? [],
                'team_ids' => array_filter([$fields['team_id'] ?? null]),
                'service_ids' => $fields['service_ids'] ?? [],
            ];
        }
        $bindings = [];
        foreach (['user_ids', 'site_ids', 'team_ids', 'service_ids'] as $key) {
            $bindings[$key] = array_values(array_unique(array_map('intval', array_merge(...array_map(
                fn (array $scope): array => (array) ($scope[$key] ?? []), $scopes,
            )))));
        }
        try {
            $this->guardAgents($bindings['user_ids']);
            $this->guardRoutingScope($actor, ['site_ids' => $bindings['site_ids']]);
            foreach (['team_ids' => ItTeam::class, 'service_ids' => ItService::class] as $key => $relatedModel) {
                $ids = $bindings[$key];
                if ($relatedModel::query()->whereKey($ids)->count() !== count($ids)) {
                    throw new DomainException('A selected configuration record is unavailable.');
                }
            }
        } catch (DomainException) {
            throw new AuthorizationException('This retained configuration is no longer available to your current access.');
        }
        $currentVersion = match (true) {
            $record instanceof ItTeam => $this->teamVersion($record),
            $record instanceof ItQueue => $this->queueVersion($record),
            $record instanceof ItService => $this->serviceVersion($record),
            default => null,
        };
        $stale = $record !== null && ! hash_equals($currentVersion, (string) $data['configuration_version']);

        return [
            'candidate_uuid' => $data['candidate_uuid'],
            'actor_user_id' => $actor->id,
            'resource' => $data['resource'],
            'record_id' => $record?->id,
            'context_uuid' => $data['context_uuid'],
            'configuration_version' => $data['configuration_version'],
            'current_configuration_version' => $currentVersion,
            'authorized' => true,
            'capabilities' => ['submit' => ! $stale],
            'blocker' => $stale ? 'configuration_changed' : null,
        ];
    }

    public function reviewActor(User $actor, ?int $originalActorId = null): User
    {
        $current = User::query()->find($actor->id);
        if ($originalActorId !== null && $originalActorId !== (int) $actor->id) {
            throw new AuthorizationException('Your signed-in account changed. Reopen Setup from your current account.');
        }
        try {
            $this->guardActor($current);
        } catch (DomainException) {
            throw new AuthorizationException('You are not allowed to configure IT service management.');
        }

        return $current;
    }

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
            $this->guardVersion($data, $this->teamVersion($team), 'team');
            $this->guardAgents($this->teamUserIds($data));
            $before = $team->only(['manager_user_id', 'name', 'description', 'is_active']);
            $beforeMembers = $this->memberConfiguration($team);
            $team->fill(Arr::only($data, ['manager_user_id', 'name', 'description', 'is_active']))->save();
            if (array_key_exists('members', $data)) {
                $this->syncMembers($team, (array) $data['members']);
                $team->load('members:id');
            }
            AuditLogger::logOrFail('it.setup.team.updated', $team, [
                'application_scope' => 'single_installation',
                'before' => $before,
                'members_before' => $beforeMembers,
                'members_after' => $this->memberConfiguration($team),
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
            if (isset($data['configuration_version'])
                && ! hash_equals($this->queueVersion($queue), (string) $data['configuration_version'])) {
                throw ValidationException::withMessages([
                    'configuration_version' => 'This queue changed while you were editing. Review the current configuration before applying your draft.',
                ]);
            }
            $prospective = [
                ...($queue->filter_rules ?? []),
                'team_id' => $queue->team_id,
                'is_active' => $queue->is_active,
                ...$data,
            ];
            // Revoked staff access must not prevent disabling a queue. Retained
            // people are checked again on activation; new choices are checked now.
            if ($prospective['is_active'] || array_intersect(array_keys($data), [
                'team_id', 'default_assignee_user_id', 'cover_user_id',
            ]) !== []) {
                $this->guardQueueDefaults($actor, $prospective);
            }
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
            $this->guardVersion($data, $this->serviceVersion($service), 'service');
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
        $team = ItTeam::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
        $this->guardActor(User::query()->whereKey($actor->id)->lockForUpdate()->first());

        return $team;
    }

    public function teamVersion(ItTeam $team): string
    {
        $team->loadMissing('members:id');

        return hash('sha256', json_encode(Arr::sortRecursive([
            ...$team->only(['id', 'name', 'description', 'manager_user_id', 'is_active']),
            'members' => $this->memberConfiguration($team),
        ]), JSON_THROW_ON_ERROR));
    }

    /** @return list<array{id: int, role: string}> */
    private function memberConfiguration(ItTeam $team): array
    {
        $team->loadMissing('members:id');

        return $team->members->map(fn (User $member): array => [
            'id' => $member->id, 'role' => $member->pivot->role,
        ])->sortBy('id')->values()->all();
    }

    public function serviceVersion(ItService $service): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive($service->only([
            'id', 'key', 'name', 'description', 'owner_user_id', 'status', 'criticality', 'is_active',
        ])), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $data */
    private function guardVersion(array $data, string $current, string $record): void
    {
        if (! isset($data['configuration_version']) || ! hash_equals($current, (string) $data['configuration_version'])) {
            throw ValidationException::withMessages([
                'configuration_version' => "This {$record} changed while you were editing. Review the current configuration before applying your draft.",
            ]);
        }
    }

    public function queueVersion(ItQueue $queue): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive($queue->only([
            'id', 'key', 'name', 'description', 'team_id', 'filter_rules', 'is_active',
        ])), JSON_THROW_ON_ERROR));
    }

    private function lockedQueue(ItQueue $queue, User $actor): ItQueue
    {
        $queue = ItQueue::query()->whereKey($queue->id)->lockForUpdate()->firstOrFail();
        $this->guardActor($actor->fresh());

        return $queue;
    }

    private function lockedService(ItService $service, User $actor): ItService
    {
        $service = ItService::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();
        $this->guardActor(User::query()->whereKey($actor->id)->lockForUpdate()->first());

        return $service;
    }

    private function guardActor(?User $actor): void
    {
        if (! $actor?->isApproved() || ! $actor->canDo('it.manage')) {
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
        $agents = ItStaffDirectory::agents()
            ->filter(fn (User $agent) => $this->routingEligibility->currentlyEmployed($agent->id))
            ->pluck('id')->map(fn ($id) => (int) $id);
        if (collect($ids)->diff($agents)->isNotEmpty()) {
            throw new DomainException('Team managers, members, owners, assignees and cover must be current IT agents with active staff profiles.');
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
        $coverId = ! empty($data['cover_user_id']) ? (int) $data['cover_user_id'] : null;
        $team = $teamId !== null ? ItTeam::query()->with('members')->find($teamId) : null;
        $this->guardAgents(array_filter([$assigneeId, $coverId]));
        foreach (array_filter([$assigneeId, $coverId]) as $userId) {
            if ($team && (int) $team->manager_user_id !== $userId && ! $team->members->contains('id', $userId)) {
                throw new DomainException('The default assignee and cover must belong to the queue team.');
            }
        }
        if ($coverId !== null && $team?->manager_user_id === $coverId) {
            throw new DomainException('Choose a cover person different from the accountable team manager.');
        }
        if (($data['is_active'] ?? false) && ($data['is_default'] ?? false)) {
            if (! $team?->is_active || ! $team->manager_user_id || $coverId === null) {
                throw new DomainException('An active fallback queue needs an active team, an accountable manager and a distinct cover person.');
            }
            $this->guardAgents([$team->manager_user_id]);
            $coveredSites = (array) ($data['site_ids'] ?? []);
            if ($coveredSites === []) {
                $coveredSites = Site::query()->where('is_active', true)->where('archived', false)
                    ->whereNull('archived_at')->pluck('id')->all();
            }
            foreach ($coveredSites as $siteId) {
                $scope = new ItTicket(['site_id' => (int) $siteId, 'is_organisation_wide' => false]);
                if (! $this->routingEligibility->agent($team->manager_user_id, $scope, available: false)
                    || ! $this->routingEligibility->agent($coverId, $scope, available: false)) {
                    throw new DomainException('The fallback manager and cover need current approved access to every Site this queue covers.');
                }
            }
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

        if (collect($siteIds)->diff($this->workAccess->approvedSiteIds($actor))->isNotEmpty()) {
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
            'cover_user_id' => ! empty($data['cover_user_id']) ? (int) $data['cover_user_id'] : null,
        ];
    }

    /** @param array<string, mixed> $data */
    private function hasRoutingData(array $data): bool
    {
        return array_intersect(array_keys($data), [
            'routing_priority', 'is_default', 'work_types', 'categories', 'priorities',
            'service_ids', 'site_ids', 'default_assignee_user_id', 'cover_user_id',
        ]) !== [];
    }
}
