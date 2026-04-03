<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('safeguarding index page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/safeguarding')
            ->waitForText('Safeguarding', 10)
            ->assertPathIs('/safeguarding');
    });
});

test('safeguarding create page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/safeguarding/create')
            ->waitForText('Safeguarding', 10)
            ->assertPathIs('/safeguarding/create');
    });
});
