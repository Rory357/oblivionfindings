<?php

use App\Domain\Finance\Jobs\CalculateGstReturnJob;
use App\Domain\Finance\Jobs\CheckBillDueDatesJob;
use App\Domain\Finance\Jobs\GenerateRecurringJournalsJob;
use App\Domain\Finance\Jobs\RunDepreciationJob;
use App\Domain\Finance\Jobs\RunPaymentMatchingJob;
use App\Domain\Finance\Jobs\SnapshotFinancialReportsJob;
use App\Domain\Finance\Jobs\SyncAccountingIntegrationJob;
use App\Domain\Finance\Jobs\SyncBankFeedsJob;
use Illuminate\Console\Scheduling\Schedule;

function finance_schedule_event(string $jobClass): ?object
{
    return collect(app(Schedule::class)->events())
        ->first(fn ($event) => ($event->description ?? null) === $jobClass);
}

test('finance operational jobs are scheduled with overlap guards', function () {
    $expected = [
        GenerateRecurringJournalsJob::class => '45 2 * * *',
        RunDepreciationJob::class => '0 3 1 * *',
        SyncBankFeedsJob::class => '*/30 * * * *',
        RunPaymentMatchingJob::class => '15,45 * * * *',
        CheckBillDueDatesJob::class => '0 7 * * *',
        CalculateGstReturnJob::class => '0 4 28 */2 *',
        SnapshotFinancialReportsJob::class => '55 23 1 * *',
        SyncAccountingIntegrationJob::class => '10 * * * *',
    ];

    foreach ($expected as $jobClass => $cron) {
        $event = finance_schedule_event($jobClass);

        expect($event)
            ->not->toBeNull()
            ->and($event->expression)->toBe($cron)
            ->and((string) $event->timezone)->toBe('Pacific/Auckland')
            ->and($event->withoutOverlapping)->toBeTrue();
    }
});
