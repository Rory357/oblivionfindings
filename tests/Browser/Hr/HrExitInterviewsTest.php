<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr exit interviews index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/exit-interviews')
            ->waitForText('Exit Interview', 10)
            ->assertPathIs('/hr/exit-interviews');
    });
});

test('hr exit interviews create redirects to the index wizard', function () {
    // The record form is now a wizard modal — the legacy create GET bounces
    // to the index with ?new=1, which opens the wizard on mount.
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/exit-interviews/create')
            ->waitForText('Exit Interview', 10)
            ->assertPathIs('/hr/exit-interviews');
    });
});

test('hr exit interviews trends page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/exit-interviews/trends')
            ->waitForText('Trend', 10)
            ->assertPathBeginsWith('/hr/exit-interviews');
    });
});
