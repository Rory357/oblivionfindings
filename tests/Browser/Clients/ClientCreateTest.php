<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('authenticated user can see create client form', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/clients/create')
            ->waitForLocation('/clients/create')
            ->pause(500)
            ->assertSee('Client');
    });
});

test('create client form has required name fields', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/clients/create')
            ->waitForLocation('/clients/create')
            ->pause(500)
            ->assertPresent('input[name="first_name"], input[name="name"], input[name="firstName"]');
    });
});
