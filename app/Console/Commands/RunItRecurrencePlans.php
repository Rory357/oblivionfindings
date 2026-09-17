<?php

namespace App\Console\Commands;

use App\Domain\It\Services\ItRecurrenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * W20 recurrence: each due plan occurrence creates exactly one routed
 * ticket. Replays and crashes cannot double-create (unique run claims) and
 * missed catch-up is bounded with skips recorded, never a ticket storm.
 */
class RunItRecurrencePlans extends Command
{
    protected $signature = 'it:run-recurrence';

    protected $description = 'Create tickets for due IT recurrence plans';

    public function handle(ItRecurrenceService $recurrence): int
    {
        if (! Schema::hasTable('it_recurrence_plans')) {
            $this->info('Recurrence storage is not migrated yet.');

            return self::SUCCESS;
        }

        $summary = $recurrence->runDue();
        $this->info(sprintf(
            'Recurrence run: %d created, %d skipped, %d failed.',
            $summary['created'], $summary['skipped'], $summary['failed'],
        ));

        return $summary['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
