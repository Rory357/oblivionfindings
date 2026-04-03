<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('finance bills index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/bills')
            ->waitForText('Bills', 10)
            ->assertSee('Bills');
    });
});

test('finance bills create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/bills/create')
            ->waitForText('Bill', 10)
            ->assertSee('Bill');
    });
});
