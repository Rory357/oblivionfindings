<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr time tracking index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/time')
            ->waitForText('Time', 10)
            ->assertPathIs('/hr/time');
    });
});

test('hr timesheets page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/time/timesheets')
            ->waitForText('Timesheet', 10)
            ->assertPathIs('/hr/time');
    });
});
