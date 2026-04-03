<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr surveys index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/surveys')
            ->waitForText('Survey', 10)
            ->assertPathIs('/hr/surveys');
    });
});

test('hr surveys create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/surveys/create')
            ->waitForText('Survey', 10)
            ->assertPathIs('/hr/surveys/create');
    });
});
