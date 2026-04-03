<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('governance compliance index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/compliance')
            ->waitForText('Compliance', 10)
            ->assertSee('Compliance');
    });
});

test('governance compliance create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/compliance/create')
            ->waitForText('Compliance', 10)
            ->assertSee('Compliance');
    });
});

test('governance compliance calendar page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/compliance/calendar')
            ->waitForText('Calendar', 10)
            ->assertSee('Calendar');
    });
});
