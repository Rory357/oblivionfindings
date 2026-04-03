<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('finance journals index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/journals')
            ->waitForText('Journals', 10)
            ->assertSee('Journals');
    });
});

test('finance journals create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/journals/create')
            ->waitForText('Journal', 10)
            ->assertSee('Journal');
    });
});
