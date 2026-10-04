<?php

namespace App\Services\Medication\Followups;

use App\Models\User;

/** Current outstanding work; reading never prepares a workflow or clinical result. */
final class MedicationFollowupProjection
{
    public function __construct(private readonly MedicationFollowupService $work) {}

    public function forClient(User $actor, int $clientId): array
    {
        // This existing person gate precedes every row and aggregate count.
        $followups = $this->work->forClient($actor, $clientId);
        $legacy = $this->work->legacyEffectChecks($actor, ['client_id' => $clientId]);
        $rows = collect($followups);

        return [
            'followups' => $followups,
            'legacy_effect_checks' => $legacy,
            'followup_counts' => [
                'open' => $rows->count() + $legacy['total'],
                'effect' => $rows->where('type', 'effect')->count() + $legacy['total'],
                'overdue' => $rows->where('state', 'overdue')->count() + $legacy['overdue'],
                'unscheduled' => $rows->whereNull('due_at')->count() + $legacy['unscheduled'],
            ],
        ];
    }
}
