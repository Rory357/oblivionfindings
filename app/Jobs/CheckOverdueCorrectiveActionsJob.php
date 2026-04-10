<?php

namespace App\Jobs;

use App\Models\HsCorrectiveAction;
use App\Services\HealthSafety\HsSignalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Detect overdue H&S corrective actions and emit signals to Control Room.
 *
 * Runs every 15 minutes. Finds corrective actions that are:
 * - Not closed
 * - Past their due_date
 */
class CheckOverdueCorrectiveActionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(HsSignalService $signalService): void
    {
        $overdueActions = HsCorrectiveAction::query()
            ->overdue()
            ->with([
                'hsEvent:id,site_id,event_category,severity',
                'assignedTo:id,name',
            ])
            ->get();

        $count = 0;

        foreach ($overdueActions as $action) {
            $daysOverdue = (int) $action->due_date->diffInDays(now());

            $signalService->emitCorrectiveActionOverdue(
                $action->id,
                $action->reference_number ?? "CA-{$action->id}",
                $daysOverdue,
                $action->priority ?? 'medium',
                $action->hsEvent?->site_id,
                [
                    'action_type' => $action->action_type,
                    'action_status' => $action->status,
                    'action_title' => $action->title,
                    'assigned_to' => $action->assignedTo?->name,
                    'hs_event_id' => $action->hs_event_id,
                    'hs_investigation_id' => $action->hs_investigation_id,
                    'event_category' => $action->hsEvent?->event_category,
                    'due_date' => $action->due_date->toDateString(),
                ],
            );

            $count++;
        }

        if ($count > 0) {
            Log::info('CheckOverdueCorrectiveActionsJob: completed', ['overdue_count' => $count]);
        }
    }
}
