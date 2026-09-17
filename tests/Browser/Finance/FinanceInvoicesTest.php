<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('finance invoices index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/invoices')
            ->waitForText('Invoices', 10)
            ->assertSee('Invoices');
    });
});

test('finance invoices create url redirects to the index (modal flow)', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/invoices/create')
            ->waitForText('Invoices', 10)
            ->assertPathIs('/finance/invoices');
    });
});
