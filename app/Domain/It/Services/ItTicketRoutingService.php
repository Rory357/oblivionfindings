<?php

namespace App\Domain\It\Services;

use App\Models\ItQueue;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\User;

final class ItTicketRoutingService
{
    public function __construct(private readonly ItWorkAccessService $workAccess) {}

    public function route(ItTicket $ticket, ?int $actorUserId = null): ItTicket
    {
        $ticket->loadMissing(['service', 'team']);
        $before = $ticket->only(['queue_id', 'team_id', 'owner_user_id', 'assigned_to_user_id']);
        $queue = $this->matchingQueue($ticket);

        // Classification is authoritative: a re-triaged ticket must not keep
        // a queue/team/owner that no longer matches its current properties.
        $ticket->queue_id = $queue?->id;
        $ticket->team_id = $queue?->team_id;
        $ticket->owner_user_id = null;

        if ($queue) {
            $defaultAssigneeId = $queue->filter_rules['default_assignee_user_id'] ?? null;
            if ($ticket->assigned_to_user_id === null
                && is_numeric($defaultAssigneeId)
                && $this->agentCanWorkTicket((int) $defaultAssigneeId, $ticket)) {
                $ticket->assigned_to_user_id = (int) $defaultAssigneeId;
            }
        }

        if ($ticket->service?->is_active
            && $ticket->service->owner_user_id !== null
            && $this->agentCanWorkTicket((int) $ticket->service->owner_user_id, $ticket)) {
            $ticket->owner_user_id = $ticket->service->owner_user_id;
        } elseif ($queue?->team?->manager_user_id !== null
            && $this->agentCanWorkTicket((int) $queue->team->manager_user_id, $ticket)) {
            $ticket->owner_user_id = $queue->team->manager_user_id;
        }

        if ($ticket->isDirty()) {
            $ticket->save();
            ItTicketEvent::record($ticket, 'routing_applied', $actorUserId, [
                'from' => $before,
                'to' => $ticket->only(['queue_id', 'team_id', 'owner_user_id', 'assigned_to_user_id']),
                'rule_queue_id' => $queue?->id,
            ]);
        }

        return $ticket->refresh();
    }

    private function matchingQueue(ItTicket $ticket): ?ItQueue
    {
        $queues = ItQueue::query()
            ->where('is_active', true)
            ->with('team.members')
            ->get()
            ->filter(fn (ItQueue $queue) => $queue->team === null || $queue->team->is_active)
            ->filter(fn (ItQueue $queue) => $queue->team === null || collect([
                $queue->team->manager_user_id,
                ...$queue->team->members->pluck('id')->all(),
            ])->filter()->contains(fn (mixed $userId): bool => $this->agentCanWorkTicket((int) $userId, $ticket)))
            ->sortByDesc(fn (ItQueue $queue) => (int) ($queue->filter_rules['routing_priority'] ?? 0));

        $specific = $queues->first(fn (ItQueue $queue) => ! ($queue->filter_rules['is_default'] ?? false)
            && $this->matches($queue->filter_rules ?? [], $ticket));

        return $specific ?: $queues->first(fn (ItQueue $queue) => (bool) ($queue->filter_rules['is_default'] ?? false));
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
        if ($allowed === []) {
            return true;
        }

        return in_array((string) $actual, array_map('strval', $allowed), true);
    }

    private function agentCanWorkTicket(int $userId, ItTicket $ticket): bool
    {
        $agent = User::query()->whereKey($userId)->whereNotNull('approved_at')->first();

        return $agent !== null && $this->workAccess->canWork($agent, $ticket);
    }
}
