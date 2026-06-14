<?php

use App\Models\Permission;
use App\Models\User;

/**
 * /finance/payables is the Payables hub entry — it redirects to the first AP tab
 * the user can open (bills), and 403s for users without finance.ap.view.
 */
it('redirects an AP-view user to the bills tab', function () {
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $permission = Permission::firstOrCreate(['key' => 'finance.ap.view'], ['description' => 'finance.ap.view']);
    $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);

    $this->actingAs($user)
        ->get(route('finance.payables.index'))
        ->assertRedirect(route('finance.bills.index'));
});

it('403s a user without finance.ap.view', function () {
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);

    $this->actingAs($user)
        ->get(route('finance.payables.index'))
        ->assertForbidden();
});
