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

test('hr onboarding create route redirects to the hub', function () {
    // Legacy single-field create page retired — /create redirects to the hub wizard.
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/onboarding/create')
            ->waitForText('Onboarding', 10)
            ->assertPathIs('/hr/onboarding');
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

test('hr offboarding create redirects to the index wizard', function () {
    // Start-offboarding is now a wizard modal — the legacy create GET bounces
    // to the index with ?new=1, which opens the wizard on mount.
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/offboarding/create')
            ->waitForText('Offboarding', 10)
            ->assertPathIs('/hr/offboarding');
    });
});

test('hr onboarding emails tab loads in the hub', function () {
    // Emails is now an in-hub tab; the legacy /emails route redirects to the hub.
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/onboarding/emails')
            ->waitForText('Onboarding', 10)
            ->assertPathIs('/hr/onboarding');
    });
});
