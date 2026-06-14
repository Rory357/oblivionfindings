<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinCreditNote;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\AccountsPayableService;
use App\Models\User;

/**
 * Credit-note approval used to reverse the GST-inclusive line total against the
 * revenue/expense account and never touch the GST control account, so GST
 * Collected (2200) / GST Paid (2210) stayed wrong. The reversal now splits net
 * vs GST exactly like the original invoice/bill journal.
 */
beforeEach(function () {
    foreach ([
        ['1100', 'Accounts Receivable', 'asset'],
        ['2000', 'Accounts Payable', 'liability'],
        ['2200', 'GST Collected', 'liability'],
        ['2210', 'GST Paid', 'liability'],
        ['4030', 'Sales Revenue', 'revenue'],
        ['6500', 'Staff Expenses', 'expense'],
    ] as [$code, $name, $type]) {
        FinAccount::factory()->create([
            'organization_id' => 1,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'opening_balance' => 0,
            'is_active' => true,
        ]);
    }

    FinFiscalPeriod::create([
        'organization_id' => 1,
        'name' => 'FY',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(),
        'status' => 'open',
    ]);

    $this->user = User::factory()->create(['organization_id' => 1]);
});

function creditNoteWithLine(string $type, string $lineAccountCode): FinCreditNote
{
    $accountId = FinAccount::where('organization_id', 1)->where('code', $lineAccountCode)->value('id');

    $cn = FinCreditNote::factory()->create([
        'organization_id' => 1,
        'type' => $type,
        'status' => 'draft',
        'credit_date' => now(),
        'subtotal' => '100.00',
        'gst_amount' => '15.00',
        'total_amount' => '115.00',
    ]);

    $cn->lines()->create([
        'description' => 'Refund line',
        'quantity' => 1,
        'unit_price' => '100.00',
        'gst_rate' => '0.15',
        'gst_amount' => '15.00',
        'line_total' => '115.00', // GST-inclusive
        'account_id' => $accountId,
    ]);

    return $cn->fresh('lines');
}

function creditNoteJournal(FinCreditNote $cn): FinJournal
{
    return FinJournal::query()
        ->where('source_type', FinCreditNote::class)
        ->where('source_id', $cn->id)
        ->firstOrFail()
        ->load('lines.account');
}

it('an AR credit note reverses revenue (net) + GST Collected (2200), balanced', function () {
    $cn = creditNoteWithLine('receivable', '4030');

    app(AccountsPayableService::class)->approveCreditNote($cn, $this->user->id);

    $journal = creditNoteJournal($cn);
    $line = fn (string $code) => $journal->lines->first(fn ($l) => $l->account->code === $code);
    $debits = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->debit, 2), '0');
    $credits = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->credit, 2), '0');

    expect(bccomp($debits, $credits, 2))->toBe(0)
        ->and((float) $line('4030')->debit)->toBe(100.0)   // revenue net reversed
        ->and((float) $line('2200')->debit)->toBe(15.0)    // GST Collected reversed
        ->and((float) $line('1100')->credit)->toBe(115.0); // AR cleared inc-GST
});

it('an AP credit note reverses expense (net) + GST Paid (2210), balanced', function () {
    $cn = creditNoteWithLine('payable', '6500');

    app(AccountsPayableService::class)->approveCreditNote($cn, $this->user->id);

    $journal = creditNoteJournal($cn);
    $line = fn (string $code) => $journal->lines->first(fn ($l) => $l->account->code === $code);
    $debits = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->debit, 2), '0');
    $credits = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->credit, 2), '0');

    expect(bccomp($debits, $credits, 2))->toBe(0)
        ->and((float) $line('2000')->debit)->toBe(115.0)   // AP reversed inc-GST
        ->and((float) $line('6500')->credit)->toBe(100.0)  // expense net reversed
        ->and((float) $line('2210')->credit)->toBe(15.0);  // GST Paid reversed
});
