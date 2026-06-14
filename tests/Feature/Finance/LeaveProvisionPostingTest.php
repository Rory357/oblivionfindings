<?php

use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\FinancialEventService;
use Database\Seeders\FinanceSeeder;

beforeEach(function () {
    $this->seed(FinanceSeeder::class); // org-0 chart (incl. the new 5050 Leave Expense)
    FinFiscalPeriod::create([
        'organization_id' => 0,
        'name' => 'FY2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'open',
    ]);
});

it('posts a leave-provision journal that debits Leave Expense (5050), not ACC Employer Levy (5020)', function () {
    $cfg = config('finance.event_accounts.leave_provision');

    $event = app(FinancialEventService::class)->record([
        'organization_id' => 0,
        'source_type' => 'leave-provision-test',
        'source_id' => 1,
        'event_type' => 'leave_provision',
        'description' => 'Leave provision delta',
        'amount' => '100.00',
        'event_date' => '2026-06-30',
        'debit_account_code' => $cfg['debit'],   // resolved from config — the fix
        'credit_account_code' => $cfg['credit'],
        'payment_type' => 'ap',
        'journal_type' => 'standard',
        'source_updated_at' => '2026-06-30',
    ]);

    expect($event->status)->toBe('posted');

    $journal = FinJournal::query()->findOrFail($event->journal_id)->load('lines.account');

    $debitLine = $journal->lines->first(fn ($l) => bccomp((string) $l->debit, '0', 2) > 0);
    $creditLine = $journal->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 2) > 0);

    $debits = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->debit, 2), '0');
    $credits = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->credit, 2), '0');

    expect($debitLine->account->code)->toBe('5050')
        ->and($debitLine->account->name)->toContain('Leave Expense')
        ->and($debitLine->account->name)->not->toContain('ACC')
        ->and($creditLine->account->code)->toBe('2400')
        ->and(bccomp($debits, $credits, 2))->toBe(0)
        ->and($debits)->toBe('100.00');
});

it('is idempotent — re-recording the same provision does not double-post', function () {
    $cfg = config('finance.event_accounts.leave_provision');
    $payload = [
        'organization_id' => 0,
        'source_type' => 'leave-provision-test',
        'source_id' => 2,
        'event_type' => 'leave_provision',
        'description' => 'Leave provision delta',
        'amount' => '50.00',
        'event_date' => '2026-06-30',
        'debit_account_code' => $cfg['debit'],
        'credit_account_code' => $cfg['credit'],
        'payment_type' => 'ap',
        'journal_type' => 'standard',
        'source_updated_at' => '2026-06-30',
    ];

    $first = app(FinancialEventService::class)->record($payload);
    $second = app(FinancialEventService::class)->record($payload);

    expect($second->id)->toBe($first->id)
        ->and(FinJournal::query()->where('source_id', $first->journal_id)->count())
        ->toBeLessThanOrEqual(1);
});
