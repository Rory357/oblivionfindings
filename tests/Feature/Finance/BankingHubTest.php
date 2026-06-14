<?php

use App\Models\Permission;
use App\Models\User;

/**
 * /finance/banking is the Banking & Cash hub entry — it redirects to the first
 * tab the user can open across the heterogeneous gates (bank.view → accounts,
 * bank.manage → feeds, petty_cash.view → petty cash), and 403s otherwise.
 */
function bankingUser(array $permissionKeys): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    foreach ($permissionKeys as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }

    return $user;
}

it('redirects a bank-view user to the accounts tab', function () {
    $this->actingAs(bankingUser(['finance.bank.view']))
        ->get(route('finance.banking.index'))
        ->assertRedirect(route('finance.bank-accounts.index'));
});

it('redirects a petty-cash-only user to the petty cash tab', function () {
    $this->actingAs(bankingUser(['finance.petty_cash.view']))
        ->get(route('finance.banking.index'))
        ->assertRedirect(route('finance.petty-cash.index'));
});

it('403s a user with no banking permissions', function () {
    $this->actingAs(bankingUser([]))
        ->get(route('finance.banking.index'))
        ->assertForbidden();
});
