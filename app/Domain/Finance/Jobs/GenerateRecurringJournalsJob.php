<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Models\FinRecurringJournal;
use App\Domain\Finance\Services\RecurringJournalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateRecurringJournalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(RecurringJournalService $service): void
    {
        $orgIds = FinRecurringJournal::due()
            ->distinct()
            ->pluck('organization_id');

        foreach ($orgIds as $orgId) {
            $journals = $service->processDueRecurringJournals($orgId);

            Log::info("Recurring journals: generated " . count($journals) . " journal(s) for organisation #{$orgId}.");
        }
    }
}
