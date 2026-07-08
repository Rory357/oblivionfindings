<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinDonorFund;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\DonorFundService;
use App\Models\User;

/**
 * Journal-posting lock-in for the donor-fund transaction modal's two posting
 * paths: recording a receipt (DR Bank / CR the fund's GL) and recording an
 * expenditure (DR the expense account / CR the fund's GL). Both must post a
 * single BALANCED trust journal, and the fund's running balances must move by
 * exactly the transaction amount.
 */
function seedDonorFundAccounts(): void
{
    foreach ([['1000', 'Bank', 'asset'], ['4000', 'Grant Income', 'liability'], ['6000', 'Programme Costs', 'expense']] as [$code, $name, $type]) {
        FinAccount::factory()->create([
            'organization_id' => 1, 'code' => $code, 'name' => $name, 'type' => $type, 'is_active' => true,
        ]);
    }

    FinFiscalPeriod::create([
        'organization_id' => 1,
        'name' => 'FY',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(),
        'status' => 'open',
    ]);
}

/** @return array{0:string,1:string} [debits, credits] summed to 2dp. */
function donorJournalTotals(FinJournal $journal): array
{
    $journal->loadMissing('lines');

    return [
        $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->debit, 2), '0'),
        $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->credit, 2), '0'),
    ];
}

function fundWithGl(): FinDonorFund
{
    $gl = FinAccount::where('organization_id', 1)->where('code', '4000')->firstOrFail();

    return FinDonorFund::factory()->create([
        'organization_id' => 1,
        'status' => 'active',
        'is_restricted' => false,
        'gl_account_id' => $gl->id,
        'total_received' => 0,
        'total_spent' => 0,
        'total_committed' => 0,
        'available_balance' => 0,
    ]);
}

it('recording a receipt posts a single balanced DR Bank / CR fund journal', function () {
    seedDonorFundAccounts();
    $fund = fundWithGl();
    $user = User::factory()->create(['organization_id' => 1]);
    $this->actingAs($user);

    $txn = app(DonorFundService::class)->recordReceipt($fund, [
        'transaction_date' => now()->toDateString(),
        'description' => 'Q1 grant instalment',
        'amount' => '1000.00',
        'reference' => 'GRANT-1',
        'bank_account_id' => null,
    ]);

    expect($txn->journal_id)->not->toBeNull();

    $journal = FinJournal::findOrFail($txn->journal_id);
    [$debits, $credits] = donorJournalTotals($journal);
    $dr = $journal->lines->first(fn ($l) => bccomp((string) $l->debit, '0', 2) > 0);
    $cr = $journal->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 2) > 0);

    expect(bccomp($debits, $credits, 2))->toBe(0)
        ->and($debits)->toBe('1000.00')
        ->and($dr->account->code)->toBe('1000')   // bank
        ->and($cr->account->code)->toBe('4000');  // fund GL

    expect((string) $fund->fresh()->total_received)->toBe('1000.00');
});

it('recording an expenditure posts a single balanced DR Expense / CR fund journal', function () {
    seedDonorFundAccounts();
    $fund = fundWithGl();
    $expense = FinAccount::where('organization_id', 1)->where('code', '6000')->firstOrFail();
    $user = User::factory()->create(['organization_id' => 1]);
    $this->actingAs($user);

    $txn = app(DonorFundService::class)->recordExpenditure($fund, [
        'transaction_date' => now()->toDateString(),
        'description' => 'Programme delivery',
        'amount' => '250.00',
        'reference' => null,
        'expense_account_id' => $expense->id,
        'bill_id' => null,
    ]);

    expect($txn->journal_id)->not->toBeNull();

    $journal = FinJournal::findOrFail($txn->journal_id);
    [$debits, $credits] = donorJournalTotals($journal);
    $dr = $journal->lines->first(fn ($l) => bccomp((string) $l->debit, '0', 2) > 0);
    $cr = $journal->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 2) > 0);

    expect(bccomp($debits, $credits, 2))->toBe(0)
        ->and($debits)->toBe('250.00')
        ->and($dr->account->code)->toBe('6000')   // expense
        ->and($cr->account->code)->toBe('4000');  // fund GL

    expect((string) $fund->fresh()->total_spent)->toBe('250.00');
});
