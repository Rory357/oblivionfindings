<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('consents index page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/consents')
            ->waitForText('Consent', 10)
            ->assertSee('Consent');
    });
});
