<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('guest can see login page', function () {
    $this->browse(function (Browser $browser) {
        $browser->logout()
            ->visit('/login')
            ->waitForText('Log in', 10)
            ->assertSee('Log in')
            ->assertSee('Email address')
            ->assertSee('Password');
    });
});

test('guest can login with valid credentials', function () {
    $user = User::factory()->withoutTwoFactor()->create([
        'approved_at' => now(),
    ]);

    $this->browse(function (Browser $browser) use ($user) {
        $browser->logout()
            ->visit('/login')
            ->waitForText('Log in', 10)
            ->type('input[type="email"]', $user->email)
            ->type('input[type="password"]', 'password')
            ->press('Log in')
            ->waitForLocation('/dashboard', 15)
            ->assertPathIs('/dashboard');
    });
});

test('guest cannot login with invalid credentials', function () {
    $this->browse(function (Browser $browser) {
        $browser->logout()
            ->visit('/login')
            ->waitForText('Log in', 10)
            ->type('input[type="email"]', 'invalid@example.com')
            ->type('input[type="password"]', 'wrong-password')
            ->press('Log in')
            ->pause(3000)
            ->assertSee('credentials');
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

test('login page has email and password fields', function () {
    $this->browse(function (Browser $browser) {
        $browser->logout()
            ->visit('/login')
            ->waitForText('Log in', 10)
            ->assertPresent('input[type="email"]')
            ->assertPresent('input[type="password"]');
    });
});
