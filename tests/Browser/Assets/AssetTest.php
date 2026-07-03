<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('assets index page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/assets')
            ->waitForText('Asset', 10)
            ->assertSee('Asset');
    });
});

test('assets create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/assets/create')
            ->waitForText('Asset', 10)
            ->assertSee('Asset');
    });
});

test('assets alerts page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        // Legacy archive URL now redirects to the canonical alerts page,
        // which renders the archived asset alert history inline.
        $browser->loginAs($user)
            ->visit('/assets/alerts')
            ->waitForText('Archived', 10)
            ->assertSee('Archived Asset Alert History');
    });
});
