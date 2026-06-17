<?php

namespace App\Observers;

use App\Models\SafeguardingInvestigation;

/**
 * Safeguarding redesign — Step 7b (W5).
 *
 * Completing an investigation auto-advances its concern from `investigating`
 * to `action_plan` (the next protective stage) — so investigation completion
 * isn't a dead-end requiring a manual status pick. Concerns in other statuses
 * (e.g. a parallel `referred_external` branch) are left untouched.
 */
class SafeguardingInvestigationObserver
{
    public function updated(SafeguardingInvestigation $investigation): void
    {
        if (! $investigation->wasChanged('status') || $investigation->status !== 'completed') {
            return;
        }

        $concern = $investigation->concern;

        if ($concern && $concern->status === 'investigating') {
            $concern->update(['status' => 'action_plan']);
        }
    }
}
