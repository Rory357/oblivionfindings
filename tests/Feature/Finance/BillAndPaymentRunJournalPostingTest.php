<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinBillLine;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Models\FinPaymentRunItem;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\AccountsPayableService;
use App\Domain\Finance\Services\PaymentRunService;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * End-to-end lock-in for the two AP money pipelines that post a GL journal but
 * had no journal-posting coverage: bill approval (DR Expense / CR AP) and payment
 * run processing (DR AP / CR Bank). Both must post a BALANCED journal, and both
 * are idempotent-by-state-machine — replaying the action throws (the status has
 * already advanced) and never posts a second journal.
 */
function seedApJournalAccounts(): void
{
    foreach ([['1000', 'Bank', 'asset'], ['2000', 'Accounts Payable', 'liability'], ['6000', 'Supplies', 'expense']] as [$code, $name, $type]) {
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
function journalTotals(FinJournal $journal): array
{
    $journal->loadMissing('lines.account');

    return [
        $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->debit, 2), '0'),
        $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->credit, 2), '0'),
    ];
}

function draftBillWithLine(): FinBill
{
    $expense = FinAccount::where('organization_id', 1)->where('code', '6000')->firstOrFail();
    $vendor = FinVendor::factory()->create(['organization_id' => 1]);
    $bill = FinBill::factory()->create([
        'organization_id' => 1,
        'vendor_id' => $vendor->id,
        'status' => 'draft',
        'bill_date' => now()->subDays(2),
        'total_amount' => '500.00',
        'amount_paid' => 0,
    ]);
    FinBillLine::create([
        'bill_id' => $bill->id,
        'description' => 'Supplies',
        'quantity' => 1,
        'unit_price' => '500.00',
        'gst_rate' => 0,
        'gst_amount' => 0,
        'line_total' => '500.00',
        'account_id' => $expense->id,
    ]);

    return $bill;
}

it('approving a bill posts a single balanced DR Expense / CR AP journal', function () {
    seedApJournalAccounts();
    $bill = draftBillWithLine();
    $user = User::factory()->create(['organization_id' => 1]);

    $result = app(AccountsPayableService::class)->approveBill($bill, $user->id);

    expect($result->status)->toBe('approved')
        ->and($result->journal_id)->not->toBeNull();

    $journal = FinJournal::findOrFail($result->journal_id);
    [$debits, $credits] = journalTotals($journal);
    $dr = $journal->lines->first(fn ($l) => bccomp((string) $l->debit, '0', 2) > 0);
    $cr = $journal->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 2) > 0);

    expect($journal->status)->toBe('posted')
        ->and(bccomp($debits, $credits, 2))->toBe(0)
        ->and($debits)->toBe('500.00')
        ->and($dr->account->code)->toBe('6000')   // expense
        ->and($cr->account->code)->toBe('2000');  // accounts payable
});

it('re-approving a bill throws and posts no second journal', function () {
    seedApJournalAccounts();
    $bill = draftBillWithLine();
    $user = User::factory()->create(['organization_id' => 1]);
    $service = app(AccountsPayableService::class);

    $service->approveBill($bill, $user->id);

    expect(fn () => $service->approveBill($bill->fresh(), $user->id))
        ->toThrow(InvalidArgumentException::class);

    expect(FinJournal::where('source_type', FinBill::class)->where('source_id', $bill->id)->count())->toBe(1);
});

function approvedPaymentRun(): FinPaymentRun
{
    $bankGl = FinAccount::where('organization_id', 1)->where('code', '1000')->firstOrFail();
    $bankAccount = FinBankAccount::factory()->create([
        'organization_id' => 1,
        'gl_account_id' => $bankGl->id,
    ]);
    $vendor = FinVendor::factory()->create(['organization_id' => 1]);
    $bill = FinBill::factory()->create([
        'organization_id' => 1,
        'vendor_id' => $vendor->id,
        'status' => 'approved',
        'total_amount' => '300.00',
        'amount_paid' => 0,
    ]);
    $run = FinPaymentRun::factory()->create([
        'organization_id' => 1,
        'status' => 'approved',
        'payment_date' => now()->subDay(),
        'item_count' => 1,
        'bank_account_id' => $bankAccount->id,
    ]);
    FinPaymentRunItem::create([
        'payment_run_id' => $run->id,
        'bill_id' => $bill->id,
        'vendor_id' => $vendor->id,
        'amount' => '300.00',
        'reference' => 'REF-1',
        'status' => 'pending',
        'bank_account_number' => '12-3456-7890123-00',
    ]);

    return $run;
}

it('processing a payment run posts a single balanced DR AP / CR Bank journal', function () {
    Storage::fake('local');
    seedApJournalAccounts();
    $run = approvedPaymentRun();
    $user = User::factory()->create(['organization_id' => 1]);

    $result = app(PaymentRunService::class)->processPaymentRun($run, $user->id);

    expect($result->status)->toBe('completed')
        ->and($result->journal_id)->not->toBeNull();

    $journal = FinJournal::findOrFail($result->journal_id);
    [$debits, $credits] = journalTotals($journal);
    $dr = $journal->lines->first(fn ($l) => bccomp((string) $l->debit, '0', 2) > 0);
    $cr = $journal->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 2) > 0);

    expect(bccomp($debits, $credits, 2))->toBe(0)
        ->and($debits)->toBe('300.00')
        ->and($dr->account->code)->toBe('2000')   // accounts payable
        ->and($cr->account->code)->toBe('1000');  // bank
});

it('re-processing a payment run throws and posts no second journal', function () {
    Storage::fake('local');
    seedApJournalAccounts();
    $run = approvedPaymentRun();
    $user = User::factory()->create(['organization_id' => 1]);
    $service = app(PaymentRunService::class);

    $service->processPaymentRun($run, $user->id);

    expect(fn () => $service->processPaymentRun($run->fresh(), $user->id))
        ->toThrow(InvalidArgumentException::class);

    expect(FinJournal::where('source_type', FinPaymentRun::class)->where('source_id', $run->id)->count())->toBe(1);
});
