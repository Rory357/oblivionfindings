<?php

use App\Domain\Finance\Jobs\ProcessFinancialEventJob;
use App\Domain\Finance\Models\FinFinancialEvent;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use Database\Seeders\FinanceSeeder;

/**
 * Regression guard for the observer → GL dispatch mechanism.
 *
 * ProcessFinancialEventJob is dispatched (never called directly) by
 * HouseLedgerEntryObserver, FleetFuelLogObserver and AssetMaintenanceLogObserver.
 * The job previously declared a `queue(): string` METHOD, which collides with
 * Laravel's custom-queueing hook (Bus\Dispatcher::dispatchToQueue) and silently
 * dropped the dispatch under the `sync` connection — so operational GL capture
 * (groceries, fuel, maintenance) never posted a journal.
 *
 * Existing GL tests either Bus::fake() the dispatch (asserting only that the job was
 * *queued*) or call FinancialEventService::record() directly — both bypass the real
 * dispatch path and hid the bug. These tests dispatch the REAL job under `sync` and
 * assert a balanced journal actually posts.
 */
beforeEach(function () {
    // The bug is connection-agnostic (the method_exists() short-circuit fires before
    // any connection is touched), but `sync` is what dev/testing/demo run and is where
    // the silent drop bites. Pin it so the test is deterministic regardless of the
    // runner's QUEUE_CONNECTION.
    config(['queue.default' => 'sync']);

    $this->seed(FinanceSeeder::class); // org-0 chart of accounts (1000/2000/6200/6300/6430/6431 …)

    FinFiscalPeriod::create([
        'organization_id' => 0,
        'name' => 'FY2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'open',
    ]);
});

it('does not declare a queue() method (which would hijack Laravel dispatch and silently drop the job)', function () {
    // A `queue()` METHOD makes Dispatcher::dispatchToQueue() call
    // $command->queue($queueInstance, $command) instead of enqueuing the job — the
    // dispatch becomes a no-op. Route via the $queue PROPERTY or ->onQueue() instead.
    expect(method_exists(ProcessFinancialEventJob::class, 'queue'))->toBeFalse();
});

it('runs handle() inline and posts a balanced journal when dispatched under sync', function (
    string $eventType,
    string $debitCode,
    ?string $creditCode,
    string $paymentType,
    string $expectedCreditCode,
) {
    $payload = [
        'organization_id' => 0,
        'source_type' => 'sync-dispatch-test',
        'source_id' => 1,
        'event_type' => $eventType,
        'description' => "Sync-dispatch {$eventType}",
        'amount' => '15.00',
        'event_date' => '2026-06-15',
        'debit_account_code' => $debitCode,
        'payment_type' => $paymentType,
        'journal_type' => 'standard',
        'source_updated_at' => '2026-06-15T00:00:00Z',
    ];

    if ($creditCode !== null) {
        $payload['credit_account_code'] = $creditCode;
    }

    // Dispatch the REAL job — no Bus::fake(). Under sync this must run handle() inline.
    ProcessFinancialEventJob::dispatch($payload);

    $event = FinFinancialEvent::query()
        ->where('source_type', 'sync-dispatch-test')
        ->where('event_type', $eventType)
        ->first();

    // Before the fix this is null: the dispatch silently no-ops and nothing posts.
    expect($event)->not->toBeNull('dispatch() under sync must run the job and record the event');
    expect($event->status)->toBe('posted')
        ->and($event->journal_id)->not->toBeNull();

    $journal = FinJournal::query()->findOrFail($event->journal_id)->load('lines.account');

    $debits = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->debit, 2), '0');
    $credits = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->credit, 2), '0');

    $debitLine = $journal->lines->first(fn ($l) => bccomp((string) $l->debit, '0', 2) > 0);
    $creditLine = $journal->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 2) > 0);

    expect(bccomp($debits, $credits, 2))->toBe(0)   // journal balances
        ->and($debits)->toBe('15.00')                // for the dispatched amount
        ->and($debitLine->account->code)->toBe($debitCode)
        ->and($creditLine->account->code)->toBe($expectedCreditCode);
})->with([
    // [event_type, debit code, explicit credit code, payment_type, expected credit code]
    'house-ledger grocery (cash)' => ['house_ledger_expense', '6431', '1000', 'cash', '1000'],
    'fleet fuel (AP)' => ['fuel_expense', '6200', null, 'ap', '2000'],
    'asset maintenance (AP)' => ['asset_maintenance_expense', '6300', null, 'ap', '2000'],
]);
