<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinPettyCashFund;
use App\Domain\Finance\Services\PettyCashService;

/**
 * Petty cash top-ups only bumped the fund balance — no GL journal — so the bank +
 * petty-cash ledger balances drifted from the recorded cash. A top-up now posts a
 * balanced DR Petty Cash / CR Bank journal.
 */
beforeEach(function () {
    $this->bank = FinAccount::factory()->create([
        'organization_id' => 1, 'code' => '1000', 'name' => 'Bank', 'type' => 'asset', 'is_active' => true,
    ]);
    $this->pettyCash = FinAccount::factory()->create([
        'organization_id' => 1, 'code' => '1010', 'name' => 'Petty Cash', 'type' => 'asset', 'is_active' => true,
    ]);
    FinFiscalPeriod::create([
        'organization_id' => 1, 'name' => 'FY',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(), 'status' => 'open',
    ]);
    $this->fund = FinPettyCashFund::create([
        'organization_id' => 1, 'name' => 'Office float', 'gl_account_id' => $this->pettyCash->id,
        'float_amount' => '200.00', 'current_balance' => '0.00', 'is_active' => true,
    ]);
});

it('books a balanced DR Petty Cash / CR Bank journal on top-up', function () {
    $txn = app(PettyCashService::class)->addTransaction($this->fund, [
        'type' => 'top_up', 'amount' => 200, 'transaction_date' => now()->toDateString(), 'description' => 'Initial float',
    ]);

    expect((float) $this->fund->fresh()->current_balance)->toBe(200.0)
        ->and($txn->journal_id)->not->toBeNull();

    $journal = FinJournal::findOrFail($txn->journal_id)->load('lines.account');
    $debits = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->debit, 2), '0');
    $credits = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->credit, 2), '0');
    $debitLine = $journal->lines->firstWhere('debit', '>', 0);
    $creditLine = $journal->lines->firstWhere('credit', '>', 0);

    expect(bccomp($debits, $credits, 2))->toBe(0)
        ->and($debits)->toBe('200.00')
        ->and($debitLine->account->code)->toBe('1010')   // Petty Cash
        ->and($creditLine->account->code)->toBe('1000');  // Bank
});

it('degrades gracefully (balance only) when no bank account is configured', function () {
    $this->bank->delete();

    $txn = app(PettyCashService::class)->addTransaction($this->fund, [
        'type' => 'top_up', 'amount' => 50, 'transaction_date' => now()->toDateString(),
    ]);

    expect((float) $this->fund->fresh()->current_balance)->toBe(50.0)
        ->and($txn->journal_id)->toBeNull();
});
