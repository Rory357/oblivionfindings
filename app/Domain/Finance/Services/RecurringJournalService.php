<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinRecurringJournal;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class RecurringJournalService
{
    public function __construct(
        protected JournalPostingService $postingService,
    ) {}

    /**
     * Find all active recurring journals due for an organisation, create and post them,
     * then advance each recurring journal's next_run_date.
     *
     * @return array<\App\Domain\Finance\Models\FinJournal> Created journals.
     */
    public function processDueRecurringJournals(?int $orgId): array
    {
        $dueRecurrings = FinRecurringJournal::forOrganization($orgId)
            ->due()
            ->get();

        $createdJournals = [];

        foreach ($dueRecurrings as $recurring) {
            try {
                $journal = $this->postingService->createAndPost($orgId, [
                    'journal_date' => $recurring->next_run_date->toDateString(),
                    'type' => 'standard',
                    'reference' => "REC-{$recurring->id}",
                    'description' => $recurring->description ?? $recurring->name,
                    'lines' => $recurring->template_lines,
                ]);

                $recurring->update([
                    'last_run_date' => $recurring->next_run_date,
                    'next_run_date' => $this->calculateNextRunDate(
                        $recurring->frequency,
                        $recurring->next_run_date->toDateString(),
                    ),
                ]);

                $createdJournals[] = $journal;
            } catch (\Throwable $e) {
                Log::error("Failed to process recurring journal #{$recurring->id} ({$recurring->name}): {$e->getMessage()}");
            }
        }

        return $createdJournals;
    }

    /**
     * Calculate the next run date based on frequency.
     */
    public function calculateNextRunDate(string $frequency, string $currentDate): string
    {
        $date = Carbon::parse($currentDate);

        return match ($frequency) {
            'daily' => $date->addDay()->toDateString(),
            'weekly' => $date->addWeek()->toDateString(),
            'monthly' => $date->addMonth()->toDateString(),
            'quarterly' => $date->addMonths(3)->toDateString(),
            'annually' => $date->addYear()->toDateString(),
            default => throw new \InvalidArgumentException("Unknown recurring journal frequency: {$frequency}"),
        };
    }
}
