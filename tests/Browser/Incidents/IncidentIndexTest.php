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

test('incidents create redirects to the report wizard over the register', function () {
    $user = User::where('email', 'admin@test.com')->first();

    // The report flow is modal-first now: /incidents/create redirects to the
    // index with ?report= so the wizard opens over the list.
    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/incidents/create')
            ->waitForText('Report an incident', 10)
            ->assertPathIs('/incidents');
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
