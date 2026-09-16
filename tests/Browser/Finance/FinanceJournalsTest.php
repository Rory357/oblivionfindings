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

// The routed create form is retired — new journals are the wizard on the list,
// so the old URL redirects there.
test('finance journals create url redirects to the list', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/journals/create')
            ->waitForText('Journals', 10)
            ->assertPathIs('/finance/journals');
    });
});
