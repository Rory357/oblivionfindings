<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr policies index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/policies')
            ->waitForText('Polic', 10)
            ->assertPathIs('/hr/policies');
    });
});

test('hr policies create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/policies/create')
            ->waitForText('Polic', 10)
            ->assertPathIs('/hr/policies/create');
    });
});

test('hr policy attestations page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/policies/attestations')
            ->waitForText('Attestation', 10)
            ->assertPathIs('/hr/policies/attestations');
    });
});
