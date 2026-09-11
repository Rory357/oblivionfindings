<?php

namespace App\Domain\It\Services;

use App\Models\ItTicket;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** One matrix for human and unattended intake; no transport owns priority policy. */
final class ItTicketPriorityService
{
    public const MATRIX = [
        'individual' => ['low' => 'low', 'normal' => 'normal', 'high' => 'high', 'critical' => 'urgent'],
        'team' => ['low' => 'normal', 'normal' => 'normal', 'high' => 'high', 'critical' => 'urgent'],
        'site' => ['low' => 'normal', 'normal' => 'high', 'high' => 'urgent', 'critical' => 'urgent'],
        'organization' => ['low' => 'high', 'normal' => 'high', 'high' => 'urgent', 'critical' => 'urgent'],
    ];

    /** Record an existing adapter's assessment without inventing a past decision. */
    public function legacyDecision(ItTicket $ticket): array
    {
        return [
            'mode' => 'legacy', 'matrix_version' => 1,
            'derived_priority' => self::MATRIX[$ticket->impact ?? 'individual'][$ticket->urgency ?? 'normal'],
            'supplied_priority' => $ticket->priority,
            'source' => $ticket->source,
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function decide(array $data, ?User $actor = null, ?ItTicket $current = null): array
    {
        $hasDimensions = array_key_exists('impact', $data) || array_key_exists('urgency', $data);
        $previous = $current?->priority_decision ?? [];
        $release = (bool) ($data['release_priority_override'] ?? false);
        $requestedPriority = $data['priority'] ?? null;
        if ($release && $requestedPriority !== null) {
            throw ValidationException::withMessages(['priority' => 'Release the override separately from choosing a manual priority.']);
        }
        $impact = $data['impact'] ?? $current?->impact ?? 'individual';
        $urgency = $data['urgency'] ?? $current?->urgency ?? 'normal';

        // Before the dimensional contract, adapters supplied priority alone.
        // Preserve that contract as an explicit individual-impact decision.
        if (! $hasDimensions && $previous === [] && $requestedPriority !== null) {
            $impact = 'individual';
            $urgency = $requestedPriority === 'urgent' ? 'critical' : $requestedPriority;
        }
        if (! in_array($impact, ItTicket::IMPACTS, true) || ! in_array($urgency, ItTicket::URGENCIES, true)) {
            throw ValidationException::withMessages(['impact' => 'Choose a valid impact and urgency.']);
        }
        $derived = self::MATRIX[$impact][$urgency];
        $decision = ['mode' => 'automatic', 'matrix_version' => 1, 'derived_priority' => $derived];
        $priority = $derived;

        if (! $release && ($previous['mode'] ?? null) === 'override'
            && $requestedPriority === $derived && $requestedPriority !== $current->priority) {
            throw ValidationException::withMessages(['priority' => 'Release the priority override to use the impact and urgency assessment.']);
        }

        if ($release) {
            $this->guardReason($actor, $data['priority_reason'] ?? null);
            $decision['released_by_user_id'] = $actor->id;
            $decision['release_reason'] = trim($data['priority_reason']);
        } elseif ($requestedPriority !== null && $requestedPriority !== $derived
            && ($hasDimensions || $previous !== [])) {
            if (! in_array($requestedPriority, ItTicket::PRIORITIES, true)) {
                throw ValidationException::withMessages(['priority' => 'Choose a valid priority.']);
            }
            $this->guardReason($actor, $data['priority_reason'] ?? null);
            $priority = $requestedPriority;
            $decision = [...$decision, 'mode' => 'override', 'reason' => trim($data['priority_reason']),
                'actor_user_id' => $actor->id, 'set_at' => now()->toIso8601String()];
        } elseif (($previous['mode'] ?? null) === 'override') {
            $priority = $current->priority;
            $decision = [...$previous, 'derived_priority' => $derived];
        }

        return ['impact' => $impact, 'urgency' => $urgency, 'priority' => $priority, 'priority_decision' => $decision];
    }

    private function guardReason(?User $actor, mixed $reason): void
    {
        if (! $actor?->canDo('it.manage')) {
            throw ValidationException::withMessages(['priority' => 'An IT technician must approve a priority override.']);
        }
        if (! is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['priority_reason' => 'Explain why this priority needs to differ from the impact and urgency assessment.']);
        }
    }
}
