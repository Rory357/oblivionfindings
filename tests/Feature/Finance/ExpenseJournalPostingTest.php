<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrExpenseItem;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

function createExpenseGlAccounts(): void
{
    foreach ([
        '6100' => ['Travel', 'expense'],
        '7010' => ['Meals & Entertainment', 'expense'],
        '6000' => ['Accommodation', 'expense'],
        '6300' => ['Office Supplies', 'expense'],
        '2000' => ['Accounts Payable', 'liability'],
    ] as $code => [$name, $type]) {
        FinAccount::factory()->create([
            'organization_id' => 1,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'opening_balance' => 0,
            'is_active' => true,
        ]);
    }
}

function createExpenseOpenPeriod(): FinFiscalPeriod
{
    return FinFiscalPeriod::create([
        'organization_id' => 1,
        'name' => 'FY2026 Expenses',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'open',
    ]);
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    // HR role is granted hr.expenses.approve.
    $this->hr = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    $this->worker = User::factory()->create(['organization_id' => 1, 'role' => 'support_worker', 'approved_at' => now()]);
});

function makeSubmittedExpenseClaim(int $ownerId): HrExpenseClaim
{
    $claim = HrExpenseClaim::query()->create([
        'tenant_id' => 1,
        'user_id' => $ownerId,
        'claim_number' => 'EXP-TEST-001',
        'title' => 'Conference trip',
        'status' => 'submitted',
        'total_amount' => 300,
        'currency' => 'NZD',
        'submitted_at' => now(),
        'created_by' => $ownerId,
    ]);

    HrExpenseItem::query()->create([
        'expense_claim_id' => $claim->id,
        'description' => 'Flights',
        'category' => 'travel',
        'amount' => 100,
        'expense_date' => now()->subDay()->toDateString(),
    ]);
    HrExpenseItem::query()->create([
        'expense_claim_id' => $claim->id,
        'description' => 'Team dinner',
        'category' => 'meals',
        'amount' => 200,
        'expense_date' => now()->subDay()->toDateString(),
    ]);

    return $claim->fresh();
}

test('approving an expense claim posts a balanced GL journal', function () {
    createExpenseGlAccounts();
    createExpenseOpenPeriod();
    $claim = makeSubmittedExpenseClaim($this->worker->id);

    $this->actingAs($this->hr)
        ->post(route('hr.compensation.expenses.approve', $claim))
        ->assertRedirect();

    $claim->refresh();
    expect($claim->status)->toBe('approved');
    expect($claim->journal_id)->not->toBeNull();
    expect($claim->gl_posted_at)->not->toBeNull();

    $journal = FinJournal::query()
        ->where('organization_id', 1)
        ->where('source_type', 'expense_claim')
        ->where('source_id', $claim->id)
        ->firstOrFail()
        ->load('lines.account');

    $debits = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->debit, 2), '0');
    $credits = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->credit, 2), '0');

    expect(bccomp($debits, $credits, 2))->toBe(0)
        ->and(bccomp($debits, '300.00', 2))->toBe(0);

    // CR Accounts Payable (2000) == claim total.
    $ap = $journal->lines->firstWhere('account.code', '2000');
    expect((float) $ap->credit)->toBe(300.0);
    // DR travel (6100) + meals (7010) expense lines present.
    expect((float) $journal->lines->firstWhere('account.code', '6100')->debit)->toBe(100.0);
    expect((float) $journal->lines->firstWhere('account.code', '7010')->debit)->toBe(200.0);
});

test('the expense GL post guards against double-posting (one journal per claim)', function () {
    createExpenseGlAccounts();
    createExpenseOpenPeriod();
    $claim = makeSubmittedExpenseClaim($this->worker->id);

    $this->actingAs($this->hr)->post(route('hr.compensation.expenses.approve', $claim))->assertRedirect();
    $claim->refresh();
    expect($claim->journal_id)->not->toBeNull();

    // The service refuses to re-post an already-journalled claim (guard).
    expect(fn () => app(\App\Domain\Finance\Services\ExpenseJournalService::class)
        ->postExpenseClaimJournal($claim))
        ->toThrow(InvalidArgumentException::class);

    expect(
        FinJournal::query()
            ->where('source_type', 'expense_claim')
            ->where('source_id', $claim->id)
            ->count()
    )->toBe(1);
});

test('a user without hr.expenses.approve cannot approve a claim', function () {
    createExpenseGlAccounts();
    createExpenseOpenPeriod();
    $claim = makeSubmittedExpenseClaim($this->worker->id);

    // worker has no approve permission.
    $this->actingAs($this->worker)
        ->post(route('hr.compensation.expenses.approve', $claim))
        ->assertForbidden();

    expect($claim->fresh()->journal_id)->toBeNull();
});
