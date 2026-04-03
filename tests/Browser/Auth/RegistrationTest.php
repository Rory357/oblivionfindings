<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('guest can see registration page', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/register')
            ->waitForText('Create an account', 10)
            ->assertSee('Create an account')
            ->assertPresent('input#name')
            ->assertPresent('input#email')
            ->assertPresent('input#password');
    });
});

test('guest can register with valid data', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/register')
            ->waitForText('Create an account', 10)
            ->type('#name', 'Test User')
            ->type('#email', 'dusk-register-' . time() . '@example.com')
            ->type('#password', 'Password123!')
            ->type('#password_confirmation', 'Password123!')
            ->press('Create account')
            ->waitForLocation('/verify-email', 15)
            ->assertPathIs('/verify-email');
    });
});

test('registration requires name email and password', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/register')
            ->waitForText('Create an account', 10)
            ->press('Create account')
            ->assertPresent('input#name:invalid')
            ->assertPresent('input#email:invalid')
            ->assertPresent('input#password:invalid');
    });
});
