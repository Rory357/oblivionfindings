<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('guest can see forgot password page', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/forgot-password')
            ->waitForText('Forgot password', 10)
            ->assertSee('Forgot password')
            ->assertPresent('input#email[type="email"]');
    });
});

test('guest can request password reset link', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->visit('/forgot-password')
            ->waitForText('Forgot password', 10)
            ->type('#email', $user->email)
            ->press('Email password reset link')
            ->waitForText('We have emailed your password reset link', 10)
            ->assertSee('We have emailed your password reset link');
    });
});
