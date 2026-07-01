<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr goals index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/goals')
            ->waitForText('Goal', 10)
            ->assertPathIs('/hr/goals');
    });
});

test('hr goals create route redirects to the hub', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/goals/create')
            ->waitForText('Goal', 10)
            ->assertPathIs('/hr/goals');
    });
});

test('hr development goals page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/goals/development')
            ->waitForText('Development', 10)
            ->assertPathIs('/hr/goals');
    });
});
