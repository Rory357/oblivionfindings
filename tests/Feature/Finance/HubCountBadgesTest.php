<?php

use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Services\FinanceHubCountsService;
use App\Models\Permission;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Finance hub tab count badges (C3d). Every finance page shares `financeHubCounts`
 * (hub → tab id → row count) so each *TabsFooter renders a count beside its tabs.
 * The prop is finance-route-scoped and lazy; the service counts every hub's lists,
 * org-scoped, guarding each count so one bad table never 500s the finance chrome.
 */
function countsUser(string $permissionKey = 'finance.ar.view'): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $permission = Permission::firstOrCreate(['key' => $permissionKey], ['description' => $permissionKey]);
    $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);

    return $user;
}

it('shares finance hub counts on a finance page, keyed by hub and tab', function () {
    FinInvoice::factory()->count(3)->create(['organization_id' => 1, 'status' => 'sent']);

    $this->actingAs(countsUser())
        ->get(route('finance.invoices.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('financeHubCounts.receivables.invoices', 3)
            ->has('financeHubCounts.payables')
            ->has('financeHubCounts.banking')
            ->has('financeHubCounts.ledger')
            ->has('financeHubCounts.tax'));
});

it('counts each org list and excludes other organisations', function () {
    FinInvoice::factory()->count(4)->create(['organization_id' => 1, 'status' => 'sent']);
    FinInvoice::factory()->count(1)->create(['organization_id' => 2, 'status' => 'sent']);

    $counts = (new FinanceHubCountsService)->forOrganization(1);

    expect($counts['receivables']['invoices'])->toBe(4)
        ->and($counts)->toHaveKeys(['receivables', 'payables', 'banking', 'ledger', 'tax']);
});

it('returns an empty map for a null organisation', function () {
    expect((new FinanceHubCountsService)->forOrganization(null))->toBe([]);
});
