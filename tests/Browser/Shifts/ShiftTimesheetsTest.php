<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('timesheets index page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/timesheets')
            ->waitForText('Timesheet', 10)
            ->assertPathIs('/timesheets');
    });
});

test('timesheets approvals page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/timesheets/approvals')
            ->waitForText('Approval', 10)
            ->assertPathIs('/timesheets/approvals');
    });
});

test('timesheets create page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/timesheets/create')
            ->waitForText('Timesheet', 10)
            ->assertPathIs('/timesheets/create');
    });
});
