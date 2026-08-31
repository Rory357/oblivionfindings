<?php

namespace App\Domain\Governance\Jobs;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Notifications\ActionItemEscalatedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class EscalateOverdueActionItems implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $overdue = ActionItem::overdue()
            ->whereNull('escalated_at')
            ->with([
                'assignedTo.hrEmployeeProfile' => fn ($profile) => $profile->withTrashed(),
                'assignedTo.permissionOverrides',
                'assignedTo.roles.permissions',
            ])
            ->get();
        $today = Carbon::today();

        foreach ($overdue as $item) {
            $item->escalate(
                $item->assigned_to,
                'Automatically escalated due to overdue status'
            );

            $recipient = $item->assignedTo;
            $profile = $recipient?->hrEmployeeProfile;
            $hasCurrentProfile = ! $profile || (
                ! $profile->trashed()
                && $profile->is_active
                && $profile->start_date
                && $profile->start_date->startOfDay()->lte($today)
                && (! $profile->end_date || $profile->end_date->startOfDay()->gte($today))
            );

            if ($recipient?->approved_at
                && $hasCurrentProfile
                && $recipient->canDo('governance.actions.view')
                && Gate::forUser($recipient)->allows('view', $item)) {
                $recipient->notify(new ActionItemEscalatedNotification($item));
            }

            \Log::info("Escalated action item: {$item->action_reference}");
        }
    }
}
