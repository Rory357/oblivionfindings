<?php

use App\Models\Role;
use App\Models\User;
use Laravel\Dusk\Browser;

test('system access dashboard view action opens the roles editor dialog', function () {
    $this->browse(function (Browser $browser) {
        $admin = User::where('email', 'admin@test.com')->firstOrFail();
        $role = Role::query()->orderBy('id')->firstOrFail();
        $roleLabel = $role->label ?? $role->name;

        $browser->loginAs($admin)
            ->visit('/system/access')
            ->waitForText('Access Control', 10)
            ->click("@system-role-view-{$role->id}")
            ->waitForLocation('/system/access/roles')
            ->waitForText("Customize Role: {$roleLabel}", 10);
    });
});
