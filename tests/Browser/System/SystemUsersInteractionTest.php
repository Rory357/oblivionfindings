<?php

use App\Models\Role;
use App\Models\User;
use Laravel\Dusk\Browser;

test('system user detail hides self suspend action but keeps it for other users', function () {
    $this->browse(function (Browser $browser) {
        $admin = User::where('email', 'admin@test.com')->firstOrFail();
        $staff = User::where('email', 'staff@test.com')->firstOrFail();

        $browser->loginAs($admin)
            ->visit("/system/users/{$admin->id}")
            ->waitForText($admin->name, 10)
            ->assertMissing('@user-suspend-action')
            ->visit("/system/users/{$staff->id}")
            ->waitForText($staff->name, 10)
            ->assertPresent('@user-suspend-action');
    });
});

test('settings users index links to the live create flow and excludes self from bulk selection', function () {
    $this->browse(function (Browser $browser) {
        $admin = User::where('email', 'admin@test.com')->firstOrFail();
        $staff = User::where('email', 'staff@test.com')->firstOrFail();

        $clickSelector = static function (string $selector): string {
            return str_replace('__SELECTOR__', json_encode($selector, JSON_THROW_ON_ERROR), <<<'JS'
                const selector = __SELECTOR__;
                const element = document.querySelector(selector);

                if (!element) {
                    throw new Error(`Element not found: ${selector}`);
                }

                element.scrollIntoView({ block: 'center' });
                element.click();
            JS);
        };

        $browser->loginAs($admin)
            ->visit('/system/users')
            ->waitForText('System Users', 10)
            ->script($clickSelector('[dusk="users-create-link"]'));

        $browser
            ->waitForLocation('/system/users/create')
            ->assertSee('Create User')
            ->visit('/system/users')
            ->waitForText('System Users', 10);

        $selfDisabled = $browser->script(
            "return document.querySelector('[dusk=\"user-select-{$admin->id}\"]')?.hasAttribute('disabled') ?? false;"
        )[0] ?? false;

        expect($selfDisabled)->toBeTrue();

        $browser->script($clickSelector('[dusk="users-select-all"]'));

        $browser->pause(250);

        $selfChecked = $browser->script(
            "return document.querySelector('[dusk=\"user-select-{$admin->id}\"]')?.getAttribute('data-state') === 'checked';"
        )[0] ?? false;
        $staffChecked = $browser->script(
            "return document.querySelector('[dusk=\"user-select-{$staff->id}\"]')?.getAttribute('data-state') === 'checked';"
        )[0] ?? false;

        expect($selfChecked)->toBeFalse();
        expect($staffChecked)->toBeTrue();

        $browser->clickLink($staff->name)
            ->waitForLocation("/system/users/{$staff->id}")
            ->waitForText($staff->name, 10)
            ->click('@user-back-link')
            ->waitForLocation('/system/users');
    });
});

test('system user detail can add and remove roles through the live update endpoint', function () {
    $this->browse(function (Browser $browser) {
        $admin = User::where('email', 'admin@test.com')->firstOrFail();
        $staff = User::where('email', 'staff@test.com')->firstOrFail();
        $managerRole = Role::where('name', 'manager')->firstOrFail();

        $browser->loginAs($admin)
            ->visit("/system/users/{$staff->id}")
            ->waitForText($staff->name, 10)
            ->click('@user-role-add-toggle')
            ->waitFor("@user-role-assign-{$managerRole->id}", 5)
            ->click("@user-role-assign-{$managerRole->id}")
            ->waitFor("@user-role-remove-{$managerRole->id}", 10)
            ->assertSee($managerRole->label ?? $managerRole->name)
            ->click("@user-role-remove-{$managerRole->id}")
            ->waitUntilMissing("@user-role-remove-{$managerRole->id}", 10);

        expect($staff->fresh()->roles()->where('name', 'manager')->exists())->toBeFalse();
    });
});
