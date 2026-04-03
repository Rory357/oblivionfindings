<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr onboarding index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/onboarding')
            ->waitForText('Onboarding', 10)
            ->assertPathIs('/hr/onboarding');
    });
});

test('hr onboarding create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/onboarding/create')
            ->waitForText('Onboarding', 10)
            ->assertPathIs('/hr/onboarding/create');
    });
});

test('hr offboarding index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/offboarding')
            ->waitForText('Offboarding', 10)
            ->assertPathIs('/hr/offboarding');
    });
});

test('hr offboarding create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/offboarding/create')
            ->waitForText('Offboarding', 10)
            ->assertPathIs('/hr/offboarding/create');
    });
});

test('hr onboarding emails page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/onboarding/emails')
            ->waitForText('Email', 10)
            ->assertPathIs('/hr/onboarding/emails');
    });
});

test('hr onboarding email log page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/onboarding/emails/log')
            ->waitForText('Log', 10)
            ->assertPathIs('/hr/onboarding/emails/log');
    });
});
