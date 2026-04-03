<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr cases index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/cases')
            ->waitForText('Case', 10)
            ->assertPathIs('/hr/cases');
    });
});

test('hr cases create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/cases/create')
            ->waitForText('Case', 10)
            ->assertPathIs('/hr/cases/create');
    });
});
