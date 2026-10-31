<?php

namespace App\Services\ControlRoom;

use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The live counts on the Live response view tabs (Needs action, Mine,
 * Escalated, Snoozed). Each count uses the same rule as its own page, so a
 * tab never promises a number its list doesn't show:
 *  - Needs action: open alerts that aren't snoozed (the desk's "active").
 *  - Mine: open alerts assigned to this person (ControlRoomMyTasksController).
 *  - Escalated: open alerts in an active queue (ControlRoomEscalationController).
 *  - Snoozed: the snoozed lens (AlertWorklistQuery).
 * All four respect the person's alert visibility. Read-only.
 */
final class LiveResponseTabCounts
{
    public function __construct(
        private readonly ControlRoomAlertAccessService $alertAccess,
    ) {}

    /** @return array{needs_action: int, mine: int, escalated: int, snoozed: int} */
    public function for(User $user): array
    {
        $activeQueueIds = TriageQueue::active()->pluck('id');

        return [
            'needs_action' => $this->visible($user)->actionable()->notSnoozed()->count(),
            'mine' => $this->visible($user)->unresolved()
                ->where('assigned_to_user_id', $user->id)
                ->count(),
            'escalated' => $activeQueueIds->isEmpty()
                ? 0
                : $this->visible($user)->unresolved()
                    ->whereIn('queue_id', $activeQueueIds)
                    ->count(),
            'snoozed' => $this->visible($user)->snoozed()->count(),
        ];
    }

    private function visible(User $user): Builder
    {
        $query = ControlRoomAlert::query();
        $this->alertAccess->applyVisibleScope($query, $user);

        return $query;
    }
}
