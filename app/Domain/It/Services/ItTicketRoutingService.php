<?php

namespace App\Domain\It\Services;

use App\Models\ItQueue;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ItTicketRoutingService
{
    private const OWNERSHIP = ['queue_id', 'team_id', 'owner_user_id', 'assigned_to_user_id'];

    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly ItTicketRoutingEligibility $eligibility,
        private readonly ItTicketPriorityService $priority,
    ) {}

    /**
     * Every adapter routes a persisted canonical ticket. Classification is
     * authoritative except for an explicit, still-eligible manual choice.
     */
    public function route(ItTicket $ticket, ?int $actorUserId = null): ItTicket
    {
        return DB::transaction(function () use ($ticket, $actorUserId): ItTicket {
            $ticket = ItTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            $ticket->load('service');
            $before = $ticket->only([...self::OWNERSHIP, 'routing_decision']);
            if ($ticket->priority_decision === null) {
                $ticket->priority_decision = $this->priority->legacyDecision($ticket);
            }

            [$queue, $strategy] = $this->matchingQueue($ticket);
            $override = $ticket->routing_override;
            $fields = (array) ($override['fields'] ?? []);
            $suspended = [];
            if (array_key_exists('queue_id', $fields)) {
                $chosen = ItQueue::query()->with('team.members')->find($fields['queue_id']);
                if ($chosen && $this->queueCanReceive($chosen, $ticket)) {
                    $queue = $chosen;
                    $strategy = 'override';
                } else {
                    $suspended[] = 'queue_id';
                }
            }

            $ticket->queue_id = $queue?->id;
            $ticket->team_id = $queue?->team_id;
            $owner = $this->ownerDecision($queue, $ticket);
            $ticket->owner_user_id = $owner['owner_user_id'];
            if (array_key_exists('owner_user_id', $fields)) {
                if ($this->eligibility->agent($fields['owner_user_id'], $ticket)) {
                    $ticket->owner_user_id = $fields['owner_user_id'];
                    $owner['source'] = 'override';
                    $owner['accountable_user_id'] = $fields['owner_user_id'];
                } else {
                    $suspended[] = 'owner_user_id';
                }
            }

            $defaultAssignee = $queue?->filter_rules['default_assignee_user_id'] ?? null;
            $oldAutomaticAssignee = $before['routing_decision']['automatic_assignee_user_id'] ?? null;
            $candidate = $ticket->assigned_to_user_id;
            if ($candidate !== null && (int) $candidate === (int) $oldAutomaticAssignee) {
                $candidate = null;
            }
            $candidate = $candidate !== null && $this->eligibility->agent((int) $candidate, $ticket)
                ? (int) $candidate : null;
            $automaticAssignee = null;
            if ($candidate === null && is_numeric($defaultAssignee)) {
                $candidate = $this->teamIncludes($queue, (int) $defaultAssignee)
                    ? $this->eligibility->agent((int) $defaultAssignee, $ticket)?->id : null;
                // An unavailable default assignee falls to the named cover.
                if ($candidate === null) {
                    $candidate = $this->eligibleCover($queue, $ticket)?->id;
                }
                $automaticAssignee = $candidate;
            }
            if (array_key_exists('assigned_to_user_id', $fields)) {
                if ($fields['assigned_to_user_id'] === null
                    || $this->eligibility->agent((int) $fields['assigned_to_user_id'], $ticket)) {
                    $candidate = $fields['assigned_to_user_id'];
                    $automaticAssignee = null;
                } else {
                    $suspended[] = 'assigned_to_user_id';
                }
            }
            $ticket->assigned_to_user_id = $candidate;

            $gaps = [];
            if ($queue === null) {
                $gaps[] = 'no_eligible_queue';
            }
            if ($ticket->team_id === null) {
                $gaps[] = 'no_accountable_team';
            }
            if ($ticket->owner_user_id === null) {
                $gaps[] = 'no_available_owner';
            }
            if ($owner['accountable_user_id'] === null) {
                $gaps[] = 'no_accountable_owner';
            }
            if ($this->eligibleCover($queue, $ticket) === null) {
                $gaps[] = 'no_available_cover';
            }
            if ($suspended !== []) {
                $gaps[] = 'manual_override_suspended';
            }
            $ticket->routing_decision = [
                'strategy' => $strategy,
                'rule_queue_id' => $queue?->id,
                'owner_source' => $owner['source'],
                'accountable_user_id' => $owner['accountable_user_id'],
                'cover_user_id' => $queue?->filter_rules['cover_user_id'] ?? null,
                'cover_applied' => $owner['source'] === 'cover',
                'automatic_assignee_user_id' => $automaticAssignee,
                'override_suspended_fields' => $suspended,
                'gaps' => $gaps,
            ];

            if ($ticket->isDirty()) {
                $ticket->save();
                $after = $ticket->only([...self::OWNERSHIP, 'routing_decision']);
                ItTicketEvent::record($ticket, 'routing_applied', $actorUserId, [
                    'from' => $before, 'to' => $after, 'rule_queue_id' => $queue?->id,
                ]);
                AuditLogger::logOrFail('it.ticket.routing.applied', $ticket, [
                    'actor_id' => $actorUserId, 'from' => Arr::only($before, self::OWNERSHIP),
                    'to' => Arr::only($after, self::OWNERSHIP), 'strategy' => $strategy,
                    'gap_codes' => $gaps, 'manual_override_suspended_fields' => $suspended,
                ]);
            }

            return $ticket->refresh();
        });
    }

    /**
     * Validate a manual intent for the locked triage command. This only builds
     * provenance; the canonical triage transaction persists it and routes.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function manualOverride(ItTicket $ticket, User $actor, array $data): array
    {
        if (! $actor->canDo('it.manage') || ! $this->workAccess->canWork($actor, $ticket)) {
            throw ValidationException::withMessages(['routing_reason' => 'You cannot change this ticket ownership.']);
        }
        $reason = $this->reason($data['routing_reason'] ?? null);
        $fields = (array) ($ticket->routing_override['fields'] ?? []);
        foreach (Arr::only($data, ['queue_id', 'owner_user_id', 'assigned_to_user_id']) as $field => $value) {
            $value = $value !== null ? (int) $value : null;
            if ($field === 'queue_id') {
                $queue = $value !== null ? ItQueue::query()->with('team.members')->find($value) : null;
                if (! $queue || ! $this->queueCanReceive($queue, $ticket)) {
                    throw ValidationException::withMessages([$field => 'Choose an active queue within this ticket scope.']);
                }
            } elseif (($value === null && $field === 'owner_user_id')
                || ($value !== null && ! $this->eligibility->agent($value, $ticket))) {
                throw ValidationException::withMessages([$field => 'Choose an available current IT technician approved for this ticket.']);
            }
            $fields[$field] = $value;
        }
        if ($fields === []) {
            throw ValidationException::withMessages(['routing_reason' => 'Choose the ownership you want to preserve.']);
        }
        if (($ticket->routing_override['fields'] ?? null) === $fields
            && ($ticket->routing_override['reason'] ?? null) === $reason
            && ($ticket->routing_override['actor_user_id'] ?? null) === $actor->id) {
            return $ticket->routing_override;
        }

        return ['fields' => $fields, 'reason' => $reason, 'actor_user_id' => $actor->id,
            'set_at' => now()->toIso8601String()];
    }

    public function reason(mixed $reason): string
    {
        if (! is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['routing_reason' => 'Explain why this ownership is needed.']);
        }

        return trim($reason);
    }

    /** @return list<array{id: int, name: string}> */
    public function queueOptions(ItTicket $ticket): array
    {
        return ItQueue::query()->with('team')->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (ItQueue $queue) => $this->queueCanReceive($queue, $ticket))
            ->map(fn (ItQueue $queue): array => ['id' => $queue->id, 'name' => $queue->name])->values()->all();
    }

    /** Configuration readiness is separate from an individual ticket decision. */
    public function queueReadiness(ItQueue $queue): array
    {
        $queue->load(['team.manager', 'team.members']);
        $manager = $queue->team?->manager;
        $coverId = $queue->filter_rules['cover_user_id'] ?? null;
        $cover = is_numeric($coverId) ? User::query()->find((int) $coverId) : null;
        $gaps = [];
        if (! $queue->team?->is_active) {
            $gaps[] = 'An active accountable team is required.';
        }
        if (! $manager?->approved_at || ! $manager->canDo('it.manage') || ! $this->eligibility->currentlyEmployed($manager->id)) {
            $gaps[] = 'The accountable manager needs current IT and staff access.';
        }
        if (! $cover || ! $this->teamIncludes($queue, $cover->id) || $cover->id === $manager?->id
            || ! $cover->approved_at || ! $cover->canDo('it.manage') || ! $this->eligibility->currentlyEmployed($cover->id)) {
            $gaps[] = 'A distinct current cover person in the team is required.';
        }
        $configuredSites = (array) ($queue->filter_rules['site_ids'] ?? []);
        $sites = Site::query()->where('is_active', true)->where('archived', false)->whereNull('archived_at')
            ->when($configuredSites !== [], fn ($query) => $query->whereKey($configuredSites))->pluck('id');
        if ($sites->isEmpty() || ($configuredSites !== [] && $sites->count() !== count(array_unique($configuredSites)))) {
            $gaps[] = 'The selected Sites must be active.';
        }
        if ($manager && $cover && $sites->contains(function ($siteId) use ($manager, $cover): bool {
            $ticket = new ItTicket(['site_id' => (int) $siteId, 'is_organisation_wide' => false]);

            return ! $this->eligibility->agent($manager->id, $ticket, available: false)
                || ! $this->eligibility->agent($cover->id, $ticket, available: false);
        })) {
            $gaps[] = 'The manager and cover need approved access to every Site this queue covers.';
        }

        return ['ready' => $queue->is_active && $gaps === [], 'gaps' => $gaps,
            'accountable_owner' => $manager ? ['id' => $manager->id, 'name' => $manager->name] : null,
            'cover' => $cover ? ['id' => $cover->id, 'name' => $cover->name] : null];
    }

    /** @return array{0: ?ItQueue, 1: string} */
    private function matchingQueue(ItTicket $ticket): array
    {
        $queues = ItQueue::query()->where('is_active', true)->with('team.members')->orderBy('id')->get()
            ->filter(fn (ItQueue $queue) => $this->queueCanReceive($queue, $ticket))
            ->sortBy([fn (ItQueue $a, ItQueue $b): int => ((int) ($b->filter_rules['routing_priority'] ?? 0) <=> (int) ($a->filter_rules['routing_priority'] ?? 0))
                ?: ($a->id <=> $b->id)]);
        $specific = $queues->filter(fn (ItQueue $queue) => ! ($queue->filter_rules['is_default'] ?? false)
            && $this->matches($queue->filter_rules ?? [], $ticket));
        $fallback = $queues->filter(fn (ItQueue $queue) => (bool) ($queue->filter_rules['is_default'] ?? false));
        foreach ([$specific, $fallback] as $index => $choices) {
            $ready = $choices->first(fn (ItQueue $queue) => $this->ownerDecision($queue, $ticket)['owner_user_id'] !== null);
            if ($ready) {
                return [$ready, $index === 0 ? 'rule' : 'fallback'];
            }
        }
        $queue = $fallback->first() ?? $specific->first();

        return [$queue, $queue ? (($queue->filter_rules['is_default'] ?? false) ? 'fallback' : 'rule') : 'unconfigured'];
    }

    private function queueCanReceive(ItQueue $queue, ItTicket $ticket): bool
    {
        return $queue->is_active
            && ($queue->team === null || $queue->team->is_active)
            && $this->allows((array) ($queue->filter_rules['site_ids'] ?? []), $ticket->site_id);
    }

    /** @return array{owner_user_id: ?int, accountable_user_id: ?int, source: string} */
    private function ownerDecision(?ItQueue $queue, ItTicket $ticket): array
    {
        $serviceOwner = $ticket->service?->is_active ? $ticket->service->owner_user_id : null;
        $manager = $queue?->team?->manager_user_id;
        $primary = $serviceOwner !== null && $this->eligibility->agent((int) $serviceOwner, $ticket, available: false)
            ? (int) $serviceOwner
            : ($manager !== null && $this->eligibility->agent((int) $manager, $ticket, available: false) ? (int) $manager : null);
        if ($primary !== null && $this->eligibility->agent($primary, $ticket)) {
            return ['owner_user_id' => $primary, 'accountable_user_id' => $primary,
                'source' => $primary === (int) $serviceOwner ? 'service' : 'team'];
        }
        $cover = $this->eligibleCover($queue, $ticket);

        return ['owner_user_id' => $cover?->id, 'accountable_user_id' => $primary, 'source' => $cover ? 'cover' : 'unavailable'];
    }

    private function eligibleCover(?ItQueue $queue, ItTicket $ticket): ?User
    {
        $id = $queue?->filter_rules['cover_user_id'] ?? null;
        if (! is_numeric($id) || (int) $id === (int) $queue?->team?->manager_user_id
            || (int) $id === (int) $ticket->service?->owner_user_id
            || ! $this->teamIncludes($queue, (int) $id)) {
            return null;
        }

        return $this->eligibility->agent((int) $id, $ticket);
    }

    private function teamIncludes(?ItQueue $queue, int $userId): bool
    {
        return $queue?->team !== null && ((int) $queue->team->manager_user_id === $userId
            || $queue->team->members->contains('id', $userId));
    }

    /** @param array<string, mixed> $rules */
    private function matches(array $rules, ItTicket $ticket): bool
    {
        return $this->allows((array) ($rules['work_types'] ?? []), $ticket->work_type)
            && $this->allows((array) ($rules['categories'] ?? []), $ticket->category)
            && $this->allows((array) ($rules['priorities'] ?? []), $ticket->priority)
            && $this->allows((array) ($rules['service_ids'] ?? []), $ticket->it_service_id)
            && $this->allows((array) ($rules['site_ids'] ?? []), $ticket->site_id);
    }

    /** @param array<int, mixed> $allowed */
    private function allows(array $allowed, mixed $actual): bool
    {
        return $allowed === [] || in_array((string) $actual, array_map('strval', $allowed), true);
    }
}
