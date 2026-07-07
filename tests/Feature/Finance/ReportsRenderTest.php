<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinJournalLine;
use App\Models\Permission;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Every report tab must actually RENDER, not just be reachable via the hub redirect.
 * Regression: profit-loss 500'd with SQLSTATE 1052 (ambiguous organization_id) because
 * FinAccount::forOrganization applied an unqualified where and the P&L query joins
 * fin_journal_lines + fin_journals, which also carry organization_id.
 */
function reportsUser(): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $permission = Permission::firstOrCreate(['key' => 'finance.reports.view'], ['description' => 'finance.reports.view']);
    $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);

    return $user;
}

function seedPostedJournal(): void
{
    $revenue = FinAccount::factory()->create([
        'organization_id' => 1, 'code' => '4000', 'name' => 'Funding Revenue', 'type' => 'revenue', 'is_active' => true,
    ]);
    $expense = FinAccount::factory()->create([
        'organization_id' => 1, 'code' => '6000', 'name' => 'Supplies', 'type' => 'expense', 'is_active' => true,
    ]);
    $bank = FinAccount::factory()->create([
        'organization_id' => 1, 'code' => '1000', 'name' => 'Bank', 'type' => 'asset', 'is_active' => true,
    ]);

    // Inside the current month — the P&L defaults to startOfMonth..endOfMonth.
    $journal = FinJournal::factory()->create([
        'organization_id' => 1, 'status' => 'posted', 'journal_date' => now()->startOfMonth()->toDateString(),
    ]);
    FinJournalLine::create(['journal_id' => $journal->id, 'account_id' => $bank->id, 'debit' => '1150.00', 'credit' => 0, 'description' => 'Receipt']);
    FinJournalLine::create(['journal_id' => $journal->id, 'account_id' => $revenue->id, 'debit' => 0, 'credit' => '1150.00', 'description' => 'Revenue']);

    $spend = FinJournal::factory()->create([
        'organization_id' => 1, 'status' => 'posted', 'journal_date' => now()->startOfMonth()->addDay()->toDateString(),
    ]);
    FinJournalLine::create(['journal_id' => $spend->id, 'account_id' => $expense->id, 'debit' => '400.00', 'credit' => 0, 'description' => 'Spend']);
    FinJournalLine::create(['journal_id' => $spend->id, 'account_id' => $bank->id, 'debit' => 0, 'credit' => '400.00', 'description' => 'Spend']);
}

it('renders every report tab with posted journals', function (string $routeName) {
    seedPostedJournal();

    $this->actingAs(reportsUser())
        ->get(route($routeName))
        ->assertOk();
})->with([
    'finance.reports.profit-loss',
    'finance.reports.balance-sheet',
    'finance.reports.trial-balance',
    'finance.reports.cash-flow',
    'finance.reports.aged-payables',
    'finance.reports.aged-receivables',
    'finance.reports.funding-stream-summary',
    'finance.reports.budget-vs-actuals',
]);

it('reports profit and loss figures from the posted journals', function () {
    seedPostedJournal();

    $this->actingAs(reportsUser())
        ->get(route('finance.reports.profit-loss'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.total_revenue', fn ($v) => (float) $v === 1150.0)
            ->where('report.total_expenses', fn ($v) => (float) $v === 400.0)
            ->where('report.net_profit', fn ($v) => (float) $v === 750.0));
});
