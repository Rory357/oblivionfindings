<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr benefits index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/compensation/benefits')
            ->waitForText('Benefit', 10)
            ->assertPathIs('/hr/compensation/benefits');
    });
});

test('hr benefits plans page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/compensation/benefits/plans')
            ->waitForText('Plan', 10)
            ->assertPathIs('/hr/compensation/benefits/plans');
    });
});
