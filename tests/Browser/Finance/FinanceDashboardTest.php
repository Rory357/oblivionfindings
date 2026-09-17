<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('finance dashboard loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance')
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

// The routed create form is retired — adding an account is the wizard on the
// chart, so the old URL redirects there.
test('finance accounts create url redirects to the chart', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/accounts/create')
            ->waitForText('Chart of accounts', 10)
            ->assertPathIs('/finance/accounts');
    });
});
