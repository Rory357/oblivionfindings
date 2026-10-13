<?php

use App\Models\User;
use Laravel\Dusk\Browser;

// The staff sign-in asks for the email first ("Continue"), then the password.

test('guest can see login page', function () {
    $this->browse(function (Browser $browser) {
        $browser->logout()
            ->visit('/login')
            ->waitForText('Sign in', 10)
            ->assertSee('Sign in')
            ->assertSee('Email address')
            ->assertSee('Continue');
    });
});

test('guest can login with valid credentials', function () {
    $user = User::factory()->withoutTwoFactor()->create([
        'approved_at' => now(),
    ]);

    $this->browse(function (Browser $browser) use ($user) {
        $browser->logout()
            ->visit('/login')
            ->waitForText('Sign in', 10)
            ->type('#email', $user->email)
            ->press('Continue')
            ->waitFor('#password', 10)
            ->type('#password', 'password')
            ->press('Sign in')
            ->waitForLocation('/my-day', 15)
            ->assertPathIs('/my-day');
    });
});

test('guest cannot login with invalid credentials', function () {
    $this->browse(function (Browser $browser) {
        $browser->logout()
            ->visit('/login')
            ->waitForText('Sign in', 10)
            ->type('#email', 'invalid@example.com')
            ->press('Continue')
            ->waitFor('#password', 10)
            ->type('#password', 'wrong-password')
            ->press('Sign in')
            ->waitForText("don't match", 10)
            ->assertSee("That email and password don't match");
    });
});

test('guest is redirected to login from protected pages', function () {
    $this->browse(function (Browser $browser) {
        $browser->logout()
            ->visit('/dashboard')
            ->waitForLocation('/login', 15)
            ->assertPathIs('/login');
    });
});

test('login page asks for the email first, then the password', function () {
    $this->browse(function (Browser $browser) {
        $browser->logout()
            ->visit('/login')
            ->waitForText('Sign in', 10)
            ->assertPresent('input[type="email"]')
            ->assertMissing('input[type="password"]')
            ->type('#email', 'someone@example.com')
            ->press('Continue')
            ->waitFor('input[type="password"]', 10)
            ->assertSee('someone@example.com');
    });
});
