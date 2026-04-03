<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('authenticated user can view today page', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/today')
            ->waitForText('Today', 10)
            ->assertPathIs('/today');
    });
});

test('today page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/today')
            ->waitForText('Today', 10)
            ->assertSee('Today');
    });
});
