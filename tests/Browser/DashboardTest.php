<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('authenticated user can visit dashboard', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/dashboard')
            ->waitForText('Dashboard', 10)
            ->assertPathIs('/dashboard');
    });
});

test('dashboard shows page content', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/dashboard')
            ->waitForText('Dashboard', 10)
            ->assertSee('Dashboard');
    });
});

test('unauthenticated user is redirected from dashboard', function () {
    $this->browse(function (Browser $browser) {
        $browser->logout()
            ->visit('/dashboard')
            ->waitForLocation('/login', 15)
            ->assertPathIs('/login');
    });
});
