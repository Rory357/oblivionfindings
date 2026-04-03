<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('summaries index page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/summaries')
            ->waitForText('Summar', 10)
            ->assertSee('Summar');
    });
});

test('summaries me page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/summaries/me')
            ->waitForText('Summar', 10)
            ->assertSee('Summar');
    });
});
