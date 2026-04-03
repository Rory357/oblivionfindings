<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr positions index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/positions')
            ->waitForText('Position', 10)
            ->assertPathIs('/hr/positions');
    });
});

test('hr positions create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/positions/create')
            ->waitForText('Position', 10)
            ->assertPathIs('/hr/positions/create');
    });
});
