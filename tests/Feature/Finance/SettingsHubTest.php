<?php

use App\Models\Permission;
use App\Models\User;

/**
 * /finance/settings is the Settings hub entry — it redirects a finance.admin
 * user to the first openable tab (Integrations), and 403s anyone else.
 */
it('redirects a finance.admin user to integrations', function () {
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $permission = Permission::firstOrCreate(['key' => 'finance.admin'], ['description' => 'finance.admin']);
    $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);

    $this->actingAs($user)
        ->get(route('finance.settings.index'))
        ->assertRedirect(route('finance.integrations.index'));
});

it('403s a user without finance.admin', function () {
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);

    $this->actingAs($user)
        ->get(route('finance.settings.index'))
        ->assertForbidden();
});
