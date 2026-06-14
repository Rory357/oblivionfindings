<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinMatchRule;
use App\Domain\Finance\Models\FinPaymentMatch;
use App\Domain\Finance\Services\PaymentMatchingService;

/**
 * The auto-match engine ignored the configured match rules (only the global max
 * threshold) and never credited match_count. Now the highest-priority active rule
 * whose rule_type the candidate satisfied governs the auto-confirm threshold and
 * has its match_count incremented.
 */
beforeEach(function () {
    foreach ([['1000', 'Bank', 'asset'], ['1100', 'Accounts Receivable', 'asset']] as [$code, $name, $type]) {
        FinAccount::factory()->create([
            'organization_id' => 1, 'code' => $code, 'name' => $name,
            'type' => $type, 'opening_balance' => 0, 'is_active' => true,
        ]);
    }
    FinFiscalPeriod::create([
        'organization_id' => 1, 'name' => 'FY',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(), 'status' => 'open',
    ]);

    $this->bankAccount = FinBankAccount::factory()->create(['organization_id' => 1, 'is_active' => true]);

    // A $100 invoice and a matching $100 deposit referencing it on the due date:
    // exact amount (40) + reference (30) + date proximity (10) = score 80.
    $this->invoice = FinInvoice::factory()->create([
        'organization_id' => 1, 'invoice_number' => 'INV-MATCH01', 'status' => 'sent',
        'total_amount' => '100.00', 'invoice_date' => now()->subDays(2)->toDateString(),
        'due_date' => now()->toDateString(),
    ]);
    $this->txn = FinBankTransaction::create([
        'organization_id' => 1, 'bank_account_id' => $this->bankAccount->id,
        'transaction_date' => now()->toDateString(), 'amount' => '100.00',
        'description' => 'Deposit INV-MATCH01', 'reference' => 'INV-MATCH01', 'status' => 'unreconciled',
    ]);
});

it('leaves an 80-score match merely suggested with no governing rule', function () {
    app(PaymentMatchingService::class)->matchUnmatchedTransactions(1);

    $pm = FinPaymentMatch::where('bank_transaction_id', $this->txn->id)->firstOrFail();
    expect($pm->status)->toBe('suggested')
        ->and((float) $pm->confidence_score)->toBe(80.0);
});

it('auto-confirms via an exact_amount rule and increments its match_count', function () {
    $rule = FinMatchRule::create([
        'organization_id' => 1, 'name' => 'Auto exact amounts', 'priority' => 10,
        'rule_type' => 'exact_amount', 'conditions' => [], 'auto_confirm_threshold' => 75.00, 'is_active' => true,
    ]);

    app(PaymentMatchingService::class)->matchUnmatchedTransactions(1);

    $pm = FinPaymentMatch::where('bank_transaction_id', $this->txn->id)->firstOrFail();
    expect($pm->status)->toBe('auto_confirmed')
        ->and($rule->fresh()->match_count)->toBe(1);

    // The auto-confirm posted a balanced DR Bank / CR AR receipt journal.
    $journal = FinJournal::where('source_type', FinPaymentMatch::class)->where('source_id', $pm->id)->firstOrFail()->load('lines');
    $debits = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->debit, 2), '0');
    $credits = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->credit, 2), '0');
    expect(bccomp($debits, $credits, 2))->toBe(0)->and($debits)->toBe('100.00');
});

it('does not auto-confirm when the governing rule threshold is above the score', function () {
    FinMatchRule::create([
        'organization_id' => 1, 'name' => 'Strict exact amounts', 'priority' => 10,
        'rule_type' => 'exact_amount', 'conditions' => [], 'auto_confirm_threshold' => 90.00, 'is_active' => true,
    ]);

    app(PaymentMatchingService::class)->matchUnmatchedTransactions(1);

    expect(FinPaymentMatch::where('bank_transaction_id', $this->txn->id)->firstOrFail()->status)->toBe('suggested');
});
