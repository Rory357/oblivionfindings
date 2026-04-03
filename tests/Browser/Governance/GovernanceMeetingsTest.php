<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('governance meetings index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/meetings')
            ->waitForText('Meetings', 10)
            ->assertSee('Meetings');
    });
});

test('governance meetings create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/meetings/create')
            ->waitForText('Meeting', 10)
            ->assertSee('Meeting');
    });
});

test('governance meetings calendar page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/meetings/calendar')
            ->waitForText('Calendar', 10)
            ->assertSee('Calendar');
    });
});
