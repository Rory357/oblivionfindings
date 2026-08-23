<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Models\FinRecurringJournal;
use App\Domain\Finance\Services\RecurringJournalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GenerateRecurringJournalsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public int $uniqueFor = 7200;

    public function handle(RecurringJournalService $service): void
    {
        $orgIds = FinRecurringJournal::due()
            ->distinct()
            ->orderBy('organization_id')
            ->pluck('organization_id');

        $firstFailure = null;
        $failedOrganizationIds = [];

        foreach ($orgIds as $orgId) {
            try {
                $journals = $service->processDueRecurringJournals($orgId);

                Log::info('Recurring journals: generated '.count($journals)." journal(s) for organisation #{$orgId}.");
            } catch (Throwable $exception) {
                $firstFailure ??= $exception;
                $failedOrganizationIds[] = (int) $orgId;
                Log::error("Recurring journals failed for organisation #{$orgId}.");
            }
        }

        if ($firstFailure !== null) {
            throw new RuntimeException(
                'Recurring journal generation failed for organisation(s): '
                .implode(', ', $failedOrganizationIds).'.',
                0,
                $firstFailure,
            );
        }
    }

    public function uniqueId(): string
    {
        return 'finance-recurring-journals';
    }
}
