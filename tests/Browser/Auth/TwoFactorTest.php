<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('two factor challenge page loads', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/two-factor-challenge')
            ->waitForText('Two', 10)
            ->assertSee('Two');
    });
});

test('email verify page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/email/verify')
            ->waitForText('Verify', 10)
            ->assertSee('Verify');
    });
});
