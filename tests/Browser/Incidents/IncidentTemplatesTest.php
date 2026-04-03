<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('incident templates index page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/incidents/templates')
            ->waitForText('Template', 10)
            ->assertPathIs('/incidents/templates');
    });
});

test('incident templates create page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/incidents/templates/create')
            ->waitForText('Template', 10)
            ->assertPathIs('/incidents/templates/create');
    });
});
