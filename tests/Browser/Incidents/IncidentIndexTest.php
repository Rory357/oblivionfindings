<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('incidents index page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/incidents')
            ->waitForText('Incident', 10)
            ->assertPathIs('/incidents');
    });
});

test('incidents create page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/incidents/create')
            ->waitForText('Incident', 10)
            ->assertPathIs('/incidents/create');
    });
});

test('incidents templates page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/incidents/templates')
            ->waitForText('Template', 10)
            ->assertPathIs('/incidents/templates');
    });
});
