<?php

use App\Models\Permission;
use App\Models\User;

/**
 * /finance/tax is the Tax & Compliance hub entry — it redirects to the first tab
 * the user can open across the heterogeneous gates (tax.view → GST returns,
 * tax.manage → IRD filings, reports.view → audit exports, admin → consolidation),
 * and 403s otherwise.
 */
function taxUser(array $permissionKeys): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    foreach ($permissionKeys as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }

    return $user;
}

it('redirects a tax-view user to GST returns', function () {
    $this->actingAs(taxUser(['finance.tax.view']))
        ->get(route('finance.tax.index'))
        ->assertRedirect(route('finance.gst-returns.index'));
});

it('redirects an admin-only user to consolidation', function () {
    $this->actingAs(taxUser(['finance.admin']))
        ->get(route('finance.tax.index'))
        ->assertRedirect(route('finance.consolidation.index'));
});

it('403s a user with no tax/compliance permissions', function () {
    $this->actingAs(taxUser([]))
        ->get(route('finance.tax.index'))
        ->assertForbidden();
});
