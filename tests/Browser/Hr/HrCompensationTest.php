<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr compensation bands page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/compensation/bands')
            ->waitForText('Band', 10)
            ->assertPathIs('/hr/compensation/bands');
    });
});

test('hr compensation bonuses page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/compensation/bonuses')
            ->waitForText('Bonus', 10)
            ->assertPathIs('/hr/compensation/bonuses');
    });
});

test('hr compensation reviews page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/compensation/reviews')
            ->waitForText('Review', 10)
            ->assertPathIs('/hr/compensation/reviews');
    });
});

test('hr compensation reviews create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/compensation/reviews/create')
            ->waitForText('Review', 10)
            ->assertPathIs('/hr/compensation/reviews/create');
    });
});
