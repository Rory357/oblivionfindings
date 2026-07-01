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

test('hr cases create GET deep-links into the New-case wizard on the index', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/cases/create')
            ->waitForText('Case', 10)
            // The full-page form was replaced by the wizard modal — the old
            // route now redirects to the index with ?new=1 which opens it.
            ->assertPathIs('/hr/cases')
            ->assertQueryStringHas('new', '1');
    });
});
