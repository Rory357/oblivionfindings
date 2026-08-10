<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('governance dashboard loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/dashboard')
            ->waitForText('Governance', 10)
            ->assertSee('Governance');
    });
});

test('governance dashboard data page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/dashboard-data')
            ->waitFor('@governance-cockpit-heading', 30)
            ->assertPathIs('/governance/dashboard')
            ->assertSee('Executive & Board Cockpit');
    });
});
