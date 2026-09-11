<?php

namespace App\Domain\It\Presenters;

use App\Models\ItTicket;
use App\Models\User;

final class ItTicketRoutingPresenter
{
    /**
     * @return array{
     *     queue: array{id: int, name: string}|null,
     *     team: array{id: int, name: string}|null,
     *     owner: array{id: int, name: string}|null,
     *     strategy: string,
     *     explanation: string,
     *     gaps: list<string>,
     *     cover: array{id: int, name: string}|null,
     *     accountable_owner: array{id: int, name: string}|null,
     *     override: array<string, mixed>|null
     * }
     */
    public function present(ItTicket $ticket): array
    {
        $ticket->loadMissing([
            'queue:id,name',
            'team:id,name',
            'owner:id,name',
        ]);
        $decision = $ticket->routing_decision ?? [];
        $strategy = $decision['strategy'] ?? 'not_evaluated';
        $names = User::query()->whereKey(array_filter([
            $decision['cover_user_id'] ?? null,
            $decision['accountable_user_id'] ?? null,
            $ticket->routing_override['actor_user_id'] ?? null,
        ]))->get(['id', 'name'])->keyBy('id');
        $user = fn (?int $id): ?array => $id && isset($names[$id])
            ? ['id' => $id, 'name' => $names[$id]->name] : null;

        return [
            'queue' => $ticket->queue
                ? ['id' => $ticket->queue->id, 'name' => $ticket->queue->name]
                : null,
            'team' => $ticket->team
                ? ['id' => $ticket->team->id, 'name' => $ticket->team->name]
                : null,
            'owner' => $ticket->owner
                ? ['id' => $ticket->owner->id, 'name' => $ticket->owner->name]
                : null,
            'strategy' => $strategy,
            'explanation' => match ($strategy) {
                'rule' => 'Classification matched this queue. Later classification changes re-evaluate automatic ownership.',
                'fallback' => 'No available specific rule matched. The service desk fallback is accountable for triage.',
                'override' => 'An intentional queue choice is retained until the override is released.',
                'unconfigured' => 'No eligible queue is configured for this ticket. IT setup needs attention.',
                default => 'Routing has not been evaluated for this existing ticket.',
            },
            'gaps' => array_values($decision['gaps'] ?? ['routing_not_evaluated']),
            'cover' => $user(isset($decision['cover_user_id']) ? (int) $decision['cover_user_id'] : null),
            'accountable_owner' => $user(isset($decision['accountable_user_id']) ? (int) $decision['accountable_user_id'] : null),
            'override' => $ticket->routing_override ? [
                'reason' => $ticket->routing_override['reason'],
                'actor' => $user((int) $ticket->routing_override['actor_user_id']),
                'fields' => array_keys($ticket->routing_override['fields']),
                'suspended_fields' => $decision['override_suspended_fields'] ?? [],
            ] : null,
        ];
    }
}
