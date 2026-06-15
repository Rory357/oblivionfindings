<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr policies index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/documents/policies')
            ->waitForText('Polic', 10)
            ->assertPathIs('/hr/documents/policies');
    });
});

test('hr policies create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/documents/policies/create')
            ->waitForText('Polic', 10)
            ->assertPathIs('/hr/documents/policies/create');
    });
});

test('hr policy attestations page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/documents/policies/attestations')
            ->waitForText('Attestation', 10)
            ->assertPathIs('/hr/documents/policies/attestations');
    });
});
