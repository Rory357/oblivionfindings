<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('finance dashboard loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/dashboard')
            ->waitForText('Finance', 10)
            ->assertSee('Finance');
    });
});

test('finance accounts index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/accounts')
            ->waitForText('Accounts', 10)
            ->assertSee('Accounts');
    });
});

test('finance accounts create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/accounts/create')
            ->waitForText('Account', 10)
            ->assertSee('Account');
    });
});
