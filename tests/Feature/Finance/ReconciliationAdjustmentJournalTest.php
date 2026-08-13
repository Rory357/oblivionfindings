<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournalLine;
use App\Domain\Finance\Services\BankReconciliationService;
use App\Models\User;

/**
 * Matching a statement line that has no existing journal (a bank fee / interest)
 * used to mark it reconciled with NO GL effect — the fee never hit the ledger.
 * It now posts a balanced adjustment journal against the chosen account and
 * matches the new bank-side journal line.
 */
beforeEach(function () {
    $this->actor = User::factory()->create(['organization_id' => 1]);
    $this->actingAs($this->actor);
    $this->bankGl = FinAccount::factory()->create([
        'organization_id' => 1, 'code' => '1000', 'name' => 'Bank', 'type' => 'asset', 'is_active' => true,
    ]);
    $this->feeAccount = FinAccount::factory()->create([
        'organization_id' => 1, 'code' => '6000', 'name' => 'Bank Fees', 'type' => 'expense', 'is_active' => true,
    ]);
    FinFiscalPeriod::create([
        'organization_id' => 1, 'name' => 'FY',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(), 'status' => 'open',
    ]);

    $this->bankAccount = FinBankAccount::factory()->create([
        'organization_id' => 1, 'is_active' => true, 'gl_account_id' => $this->bankGl->id,
    ]);
    $this->recon = app(BankReconciliationService::class)->startReconciliation(1, $this->bankAccount->id, [
        'statement_date' => now()->toDateString(),
        'statement_balance' => '0.00',
        'created_by' => $this->actor->id,
    ]);
    // A $5 bank fee on the statement (outflow), with no journal yet.
    $this->fee = FinBankTransaction::create([
        'organization_id' => 1, 'bank_account_id' => $this->bankAccount->id,
        'transaction_date' => now()->toDateString(), 'amount' => '-5.00',
        'description' => 'Monthly account fee', 'status' => 'unreconciled',
    ]);
});

it('posts a balanced DR expense / CR bank journal when matching a fee as an adjustment', function () {
    $line = app(BankReconciliationService::class)->matchTransaction(
        $this->recon->id, $this->fee->id, null, $this->feeAccount->id, $this->actor->id, 1,
    );

    // The reconciliation line links a (newly created) bank-side journal line.
    expect($line->journal_line_id)->not->toBeNull()
        ->and($this->fee->fresh()->status)->toBe('matched');

    $bankLine = FinJournalLine::with('account', 'journal.lines.account')->findOrFail($line->journal_line_id);
    $journal = $bankLine->journal;
    $debits = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->debit, 2), '0');
    $credits = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->credit, 2), '0');
    $debitLine = $journal->lines->firstWhere('debit', '>', 0);
    $creditLine = $journal->lines->firstWhere('credit', '>', 0);

    expect(bccomp($debits, $credits, 2))->toBe(0)
        ->and($debits)->toBe('5.00')
        ->and($debitLine->account->code)->toBe('6000')   // Bank Fees (expense) debited
        ->and($creditLine->account->code)->toBe('1000')  // Bank credited (money out)
        ->and($bankLine->account->code)->toBe('1000');   // recon matched the bank-side line
});

it('refuses to match a statement effect without a posted GL line', function () {
    expect(fn () => app(BankReconciliationService::class)->matchTransaction(
        $this->recon->id, $this->fee->id, null, null, $this->actor->id, 1,
    ))->toThrow(DomainException::class);

    expect($this->fee->fresh()->status)->toBe('unreconciled')
        ->and($this->recon->fresh()->version)->toBe(1);
});
