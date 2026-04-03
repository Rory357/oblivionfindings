<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('medications reports page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/reports/medications')
            ->waitForText('Medication', 10)
            ->assertPresent('body');
    });
});

test('compliance dashboard page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/compliance')
            ->waitForText('Compliance', 10)
            ->assertPresent('body');
    });
});
