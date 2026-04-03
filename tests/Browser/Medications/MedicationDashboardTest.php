<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('medications index page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/medications')
            ->waitForText('Medication', 10)
            ->assertPathIs('/medications');
    });
});

test('medications dashboard page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/medications/dashboard')
            ->waitForText('Dashboard', 10)
            ->assertPathIs('/medications/dashboard');
    });
});

test('medications audit page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/medications/audit')
            ->waitForText('Audit', 10)
            ->assertPathIs('/medications/audit');
    });
});
