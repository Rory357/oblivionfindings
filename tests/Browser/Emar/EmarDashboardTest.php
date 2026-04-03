<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('emar dashboard page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/emar')
            ->waitForText('eMAR', 10)
            ->assertPathIs('/emar');
    });
});

test('emar audit page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/emar/audit')
            ->waitForText('Audit', 10)
            ->assertPathIs('/emar/audit');
    });
});

test('emar competency page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/emar/competency')
            ->waitForText('Competenc', 10)
            ->assertPathIs('/emar/competency');
    });
});

test('emar controlled drugs page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/emar/controlled')
            ->waitForText('Controlled', 10)
            ->assertPathIs('/emar/controlled');
    });
});

test('emar daily page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/emar/daily')
            ->waitForText('Medication', 10)
            ->assertPathIs('/emar/daily');
    });
});
