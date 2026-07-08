<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinBillLine;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\AccountsPayableService;
use App\Domain\Governance\Models\SpendApproval;
use App\Models\User;

/**
 * Governance spend-approval gate on AP bills (config finance.spend_approval).
 * When enforcement is on, a bill at/above the threshold can only be approved
 * once it is linked to an APPROVED SpendApproval covering the full amount.
 * Off by default so existing AP flows are unaffected. The link is one-directional
 * — approving a bill never creates a SpendApproval.
 *
 * Helpers are prefixed `sag_` to stay unique across the global Pest function space.
 */
function sag_seedAccounts(): void
{
    foreach ([['2000', 'Accounts Payable', 'liability'], ['6000', 'Supplies', 'expense']] as [$code, $name, $type]) {
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

function sag_draftBill(string $total): FinBill
{
    $expense = FinAccount::where('organization_id', 1)->where('code', '6000')->firstOrFail();
    $vendor = FinVendor::factory()->create(['organization_id' => 1]);
    $bill = FinBill::factory()->create([
        'organization_id' => 1,
        'vendor_id' => $vendor->id,
        'status' => 'draft',
        'bill_date' => now()->subDay(),
        'total_amount' => $total,
        'amount_paid' => 0,
    ]);
    FinBillLine::create([
        'bill_id' => $bill->id,
        'description' => 'Supplies',
        'quantity' => 1,
        'unit_price' => $total,
        'gst_rate' => 0,
        'gst_amount' => 0,
        'line_total' => $total,
        'account_id' => $expense->id,
    ]);

    return $bill;
}

function sag_approvedApproval(int $userId, string $amount): SpendApproval
{
    return SpendApproval::create([
        'reference' => 'SA-TEST-1',
        'title' => 'Board sign-off',
        'category' => SpendApproval::CATEGORY_CAPEX,
        'amount' => $amount,
        'status' => SpendApproval::STATUS_APPROVED,
        'requested_by' => $userId,
        'decided_by' => $userId,
        'decided_at' => now(),
    ]);
}

it('blocks approving an over-threshold bill with no linked spend approval when enforced', function () {
    config(['finance.spend_approval.enforce' => true, 'finance.spend_approval.threshold' => 10000]);
    sag_seedAccounts();
    $bill = sag_draftBill('15000.00');
    $user = User::factory()->create(['organization_id' => 1]);

    expect(fn () => app(AccountsPayableService::class)->approveBill($bill, $user->id))
        ->toThrow(InvalidArgumentException::class);

    expect($bill->fresh()->status)->toBe('draft')
        ->and($bill->fresh()->journal_id)->toBeNull();
});

it('approves an over-threshold bill once linked to an approved spend approval that covers it', function () {
    config(['finance.spend_approval.enforce' => true, 'finance.spend_approval.threshold' => 10000]);
    sag_seedAccounts();
    $user = User::factory()->create(['organization_id' => 1]);
    $approval = sag_approvedApproval($user->id, '20000.00');
    $bill = sag_draftBill('15000.00');
    $bill->update(['spend_approval_id' => $approval->id]);

    $result = app(AccountsPayableService::class)->approveBill($bill->fresh(), $user->id);

    expect($result->status)->toBe('approved')
        ->and($result->journal_id)->not->toBeNull();

    $journal = $result->journal;
    $journal->loadMissing('lines');
    $debits = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->debit, 2), '0');
    $credits = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->credit, 2), '0');
    expect(bccomp($debits, $credits, 2))->toBe(0)
        ->and($debits)->toBe('15000.00');
});

it('blocks when the linked approval does not cover the full bill amount', function () {
    config(['finance.spend_approval.enforce' => true, 'finance.spend_approval.threshold' => 10000]);
    sag_seedAccounts();
    $user = User::factory()->create(['organization_id' => 1]);
    $approval = sag_approvedApproval($user->id, '5000.00'); // less than the bill
    $bill = sag_draftBill('15000.00');
    $bill->update(['spend_approval_id' => $approval->id]);

    expect(fn () => app(AccountsPayableService::class)->approveBill($bill->fresh(), $user->id))
        ->toThrow(InvalidArgumentException::class);
});

it('blocks when the linked approval is not yet approved', function () {
    config(['finance.spend_approval.enforce' => true, 'finance.spend_approval.threshold' => 10000]);
    sag_seedAccounts();
    $user = User::factory()->create(['organization_id' => 1]);
    $approval = sag_approvedApproval($user->id, '20000.00');
    $approval->update(['status' => SpendApproval::STATUS_SUBMITTED]); // pending, not decided
    $bill = sag_draftBill('15000.00');
    $bill->update(['spend_approval_id' => $approval->id]);

    expect(fn () => app(AccountsPayableService::class)->approveBill($bill->fresh(), $user->id))
        ->toThrow(InvalidArgumentException::class);
});

it('does not gate a bill below the threshold', function () {
    config(['finance.spend_approval.enforce' => true, 'finance.spend_approval.threshold' => 10000]);
    sag_seedAccounts();
    $bill = sag_draftBill('500.00');
    $user = User::factory()->create(['organization_id' => 1]);

    $result = app(AccountsPayableService::class)->approveBill($bill, $user->id);

    expect($result->status)->toBe('approved');
});

it('does not gate anything when enforcement is off (default)', function () {
    // Enforcement left at its default (off) — a large bill approves with no approval.
    sag_seedAccounts();
    $bill = sag_draftBill('50000.00');
    $user = User::factory()->create(['organization_id' => 1]);

    $result = app(AccountsPayableService::class)->approveBill($bill, $user->id);

    expect($result->status)->toBe('approved')
        ->and($result->journal_id)->not->toBeNull();
});
