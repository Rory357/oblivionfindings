<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('governance policies index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/policies')
            ->waitForText('Policies', 10)
            ->assertSee('Policies');
    });
});

test('governance policies create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/policies/create')
            ->waitForText('Policy', 10)
            ->assertSee('Policy');
    });
});
