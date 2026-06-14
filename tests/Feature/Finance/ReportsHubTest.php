<?php

use App\Models\Permission;
use App\Models\User;

/**
 * /finance/reports is the Reports & Planning hub entry — it redirects to the first
 * report tab (P&L) for a reports-view user, and 403s otherwise.
 */
it('redirects a reports-view user to profit & loss', function () {
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $permission = Permission::firstOrCreate(['key' => 'finance.reports.view'], ['description' => 'finance.reports.view']);
    $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);

    $this->actingAs($user)
        ->get(route('finance.reports.index'))
        ->assertRedirect(route('finance.reports.profit-loss'));
});

it('403s a user without finance.reports.view', function () {
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);

    $this->actingAs($user)
        ->get(route('finance.reports.index'))
        ->assertForbidden();
});
