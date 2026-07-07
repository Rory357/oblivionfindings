<?php

use App\Models\Permission;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * /finance is the Overview hub (Summary · Executive · By site · Cash position).
 * The old /finance/dashboard URL redirects; the finance.dashboard route NAME
 * stays on the hub so existing callers keep working.
 */
function overviewUser(): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $permission = Permission::firstOrCreate(['key' => 'finance.dashboard'], ['description' => 'finance.dashboard']);
    $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);

    return $user;
}

it('serves the summary dashboard at /finance', function () {
    $this->actingAs(overviewUser())
        ->get('/finance')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('finance/Dashboard'));
});

it('redirects the old /finance/dashboard url to /finance', function () {
    $this->actingAs(overviewUser())
        ->get('/finance/dashboard')
        ->assertRedirect('/finance');
});

it('keeps the finance.dashboard route name pointing at the hub', function () {
    expect(route('finance.dashboard', absolute: false))->toBe('/finance');
});

it('renders the cash position tab with balances and obligations', function () {
    $this->actingAs(overviewUser())
        ->get(route('finance.cash-position'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/cash-position/Index')
            ->has('accounts')
            ->has('pettyCash')
            ->has('totals.cash_on_hand')
            ->has('totals.expected_in_30d')
            ->has('totals.expected_out_30d')
            ->has('obligations'));
});

it('403s the overview hub without finance.dashboard', function () {
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);

    $this->actingAs($user)->get('/finance')->assertForbidden();
    $this->actingAs($user)->get(route('finance.cash-position'))->assertForbidden();
});
