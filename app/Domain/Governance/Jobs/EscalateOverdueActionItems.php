<?php

namespace App\Domain\Governance\Jobs;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Notifications\ActionItemEscalatedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EscalateOverdueActionItems implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $overdue = ActionItem::overdue()
            ->whereNull('escalated_at')
            ->get();

        foreach ($overdue as $item) {
            $item->escalate(
                $item->assigned_to,
                'Automatically escalated due to overdue status'
            );

            // Notify assignee and chair
            $item->assignedTo->notify(new ActionItemEscalatedNotification($item));
            
            \Log::info("Escalated action item: {$item->action_reference}");
        }
    }
}
