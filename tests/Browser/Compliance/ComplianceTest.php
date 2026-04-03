<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('compliance index page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/compliance')
            ->waitForText('Compliance', 10)
            ->assertSee('Compliance');
    });
});

test('compliance hazards page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/compliance/hazards')
            ->waitForText('Hazard', 10)
            ->assertSee('Hazard');
    });
});
