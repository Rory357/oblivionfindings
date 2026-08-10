<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('shifts index page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/shifts')
            ->waitForText('Shift', 10)
            ->assertPathIs('/shifts');
    });
});

test('shifts create page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/shifts/create')
            ->waitForText('Shift', 10)
            ->assertPathIs('/shifts/create');
    });
});

test('rostering page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/rostering')
            ->waitForText('Roster', 10)
            ->assertPathIs('/rostering');
    });
});
