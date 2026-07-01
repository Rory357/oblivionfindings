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

test('hr policies create route opens the wizard on the index', function () {
    // The full-page create form was replaced by the PolicyWizard modal: the old
    // GET route now redirects to the index with ?new=1, which opens the wizard.
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/documents/policies/create')
            ->waitForText('Polic', 10)
            ->assertPathIs('/hr/documents/policies')
            ->assertQueryStringHas('new');
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
