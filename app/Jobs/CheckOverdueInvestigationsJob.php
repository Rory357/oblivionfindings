<?php

namespace App\Jobs;

use App\Models\HsInvestigation;
use App\Services\HealthSafety\HsSignalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Detect overdue H&S investigations and emit signals to Control Room.
 *
 * Runs every 15 minutes. Finds investigations that are:
 * - Not completed
 * - Past their target_completion_date
 */
class CheckOverdueInvestigationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(HsSignalService $signalService): void
    {
        $overdueInvestigations = HsInvestigation::query()
            ->overdue()
            ->with(['hsEvent:id,site_id,event_category,severity', 'leadInvestigator:id,name'])
            ->get();

        $count = 0;

        foreach ($overdueInvestigations as $investigation) {
            $daysOverdue = (int) $investigation->target_completion_date->diffInDays(now());

            $signalService->emitInvestigationOverdue(
                $investigation->id,
                $investigation->reference_number ?? "INV-{$investigation->id}",
                $daysOverdue,
                $investigation->hsEvent?->site_id,
                [
                    'investigation_type' => $investigation->investigation_type,
                    'investigation_status' => $investigation->status,
                    'lead_investigator' => $investigation->leadInvestigator?->name,
                    'hs_event_id' => $investigation->hs_event_id,
                    'event_category' => $investigation->hsEvent?->event_category,
                    'event_severity' => $investigation->hsEvent?->severity,
                    'target_completion_date' => $investigation->target_completion_date->toDateString(),
                ],
            );

            $count++;
        }

        if ($count > 0) {
            Log::info('CheckOverdueInvestigationsJob: completed', ['overdue_count' => $count]);
        }
    }
}
