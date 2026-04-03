<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('emar destructions page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/emar/destructions')
            ->waitForText('Destruction', 10)
            ->assertPathIs('/emar/destructions');
    });
});

test('emar emergency access page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/emar/emergency-access')
            ->waitForText('Emergency', 10)
            ->assertPathIs('/emar/emergency-access');
    });
});

test('emar errors page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/emar/errors')
            ->waitForText('Error', 10)
            ->assertPathIs('/emar/errors');
    });
});

test('emar handovers page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/emar/handovers')
            ->waitForText('Handover', 10)
            ->assertPathIs('/emar/handovers');
    });
});

test('emar mar page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/emar/mar')
            ->waitForText('MAR', 10)
            ->assertPathIs('/emar/mar');
    });
});

test('emar medications page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/emar/medications')
            ->waitForText('Medication', 10)
            ->assertPathIs('/emar/medications');
    });
});

test('emar prescriptions page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/emar/prescriptions')
            ->waitForText('Prescription', 10)
            ->assertPathIs('/emar/prescriptions');
    });
});

test('emar prn page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/emar/prn')
            ->waitForText('PRN', 10)
            ->assertPathIs('/emar/prn');
    });
});

test('emar reports page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/emar/reports')
            ->waitForText('Report', 10)
            ->assertPathIs('/emar/reports');
    });
});

test('emar reviews page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/emar/reviews')
            ->waitForText('Review', 10)
            ->assertPathIs('/emar/reviews');
    });
});

test('emar rounds page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/emar/rounds')
            ->waitForText('Round', 10)
            ->assertPathIs('/emar/rounds');
    });
});

test('emar self admin page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/emar/self-admin')
            ->waitForText('Self', 10)
            ->assertPathIs('/emar/self-admin');
    });
});

test('emar stock page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/emar/stock')
            ->waitForText('Stock', 10)
            ->assertPathIs('/emar/stock');
    });
});
