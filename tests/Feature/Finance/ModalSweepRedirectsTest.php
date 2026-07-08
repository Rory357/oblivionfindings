<?php

use App\Models\Permission;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * C2 modal sweep — simple full-page Create/Edit flows became WizardShell modals
 * on their index (and, where relevant, show) pages: recurring charges · price
 * books · petty cash · bank accounts · audit exports · cash-flow forecasts ·
 * quotes · credit notes · fixed assets · donor funds. The retired GET create/
 * edit URLs now redirect to each flow's index; the POST/PUT endpoints are
 * unchanged. (Funding streams already used inline dialogs — no GET create/edit
 * URLs existed to retire, so they're not in the redirect set.)
 */
function modalSweepUser(): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);

    // The view permission each index route's middleware requires.
    foreach ([
        'finance.ar.view', 'finance.ap.view', 'finance.petty_cash.view',
        'finance.bank.view', 'finance.reports.view', 'finance.assets.view',
    ] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }

    return $user;
}

it('redirects every retired create/edit url to its flow index', function (string $retired, string $index) {
    $this->actingAs(modalSweepUser())
        ->get($retired)
        ->assertRedirect($index);
})->with([
    'recurring charge create' => ['/finance/recurring-charges/create', '/finance/recurring-charges'],
    'recurring charge edit' => ['/finance/recurring-charges/123/edit', '/finance/recurring-charges'],
    'price book create' => ['/finance/price-books/create', '/finance/price-books'],
    'price book edit' => ['/finance/price-books/123/edit', '/finance/price-books'],
    'petty cash create' => ['/finance/petty-cash/create', '/finance/petty-cash'],
    'bank account create' => ['/finance/bank-accounts/create', '/finance/bank-accounts'],
    'bank account edit' => ['/finance/bank-accounts/123/edit', '/finance/bank-accounts'],
    'audit export create' => ['/finance/audit-exports/create', '/finance/audit-exports'],
    'cash flow forecast create' => ['/finance/cash-flow-forecast/create', '/finance/cash-flow-forecast'],
    'quote create' => ['/finance/quotes/create', '/finance/quotes'],
    'quote edit' => ['/finance/quotes/123/edit', '/finance/quotes'],
    'credit note create' => ['/finance/credit-notes/create', '/finance/credit-notes'],
    'fixed asset create' => ['/finance/fixed-assets/create', '/finance/fixed-assets'],
    'fixed asset edit' => ['/finance/fixed-assets/123/edit', '/finance/fixed-assets'],
    'donor fund create' => ['/finance/donor-funds/create', '/finance/donor-funds'],
]);

it('still serves each flow index (now hosting the modal) to a permitted user', function (string $url, string $component) {
    $this->actingAs(modalSweepUser())
        ->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->has('canManage'));
})->with([
    'recurring charges' => ['/finance/recurring-charges', 'finance/recurring-charges/Index'],
    'price books' => ['/finance/price-books', 'finance/price-books/Index'],
    'petty cash' => ['/finance/petty-cash', 'finance/petty-cash/Index'],
    'bank accounts' => ['/finance/bank-accounts', 'finance/bank-accounts/Index'],
    'audit exports' => ['/finance/audit-exports', 'finance/audit-exports/Index'],
    'cash flow forecasts' => ['/finance/cash-flow-forecast', 'finance/CashFlowForecast/Index'],
    'quotes' => ['/finance/quotes', 'finance/quotes/Index'],
    'credit notes' => ['/finance/credit-notes', 'finance/credit-notes/Index'],
    'fixed assets' => ['/finance/fixed-assets', 'finance/fixed-assets/Index'],
    'donor funds' => ['/finance/donor-funds', 'finance/donor-funds/Index'],
]);

it('persists a recurring charge from the modal payload (starts_at regression)', function () {
    // Regression: recurring_charges.starts_at is NOT NULL with no default, so the
    // store 500'd on every create — the modal AND the retired full-page form both
    // hit it. The store now derives starts_at from the first charge date.
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $manage = Permission::firstOrCreate(['key' => 'finance.ar.manage'], ['description' => 'finance.ar.manage']);
    $user->permissionOverrides()->syncWithoutDetaching([$manage->id => ['allowed' => true]]);
    $client = \App\Models\Client::factory()->create(['organization_id' => 1]);

    $this->actingAs($user)
        ->post('/finance/recurring-charges', [
            'client_id' => $client->id,
            'description' => 'Weekly transport levy',
            'amount' => '45.50',
            'frequency' => 'weekly',
            'next_charge_date' => '2026-07-14',
            'is_active' => true,
        ])
        ->assertRedirect(route('finance.recurring_charges.index'));

    $this->assertDatabaseHas('recurring_charges', [
        'organization_id' => 1,
        'client_id' => $client->id,
        'name' => 'Weekly transport levy',
        'frequency' => 'weekly',
        'next_charge_at' => '2026-07-14 00:00:00',
        'starts_at' => '2026-07-14 00:00:00',
    ]);
});
