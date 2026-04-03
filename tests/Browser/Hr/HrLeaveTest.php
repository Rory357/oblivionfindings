<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr leave index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/leave')
            ->waitForText('Leave', 10)
            ->assertPathIs('/hr/leave');
    });
});

test('hr leave balances page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/leave/balances')
            ->waitForText('Balances', 10)
            ->assertPathIs('/hr/leave/balances');
    });
});

test('hr leave create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/leave/create')
            ->waitForText('Leave', 10)
            ->assertPathIs('/hr/leave/create');
    });
});

test('hr leave reports page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/leave/reports')
            ->waitForText('Report', 10)
            ->assertPathIs('/hr/leave/reports');
    });
});
