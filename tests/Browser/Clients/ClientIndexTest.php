<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('authenticated user can view clients index page', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/clients')
            ->pause(1000)
            ->assertPathBeginsWith('/operations/clients')
            ->assertSee('Client');
    });
});

test('clients page has create button or link', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/clients')
            ->pause(1000)
            ->assertPathBeginsWith('/operations/clients')
            ->assertSee('Client');
    });
});

test('guest is redirected from clients page', function () {
    $this->browse(function (Browser $browser) {
        $browser->logout()
            ->visit('/clients')
            ->pause(500)
            ->assertPathBeginsWith('/login');
    });
});
