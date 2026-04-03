<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr settings audit log page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/settings/audit-log')
            ->waitForText('Audit', 10)
            ->assertPathIs('/hr/settings/audit-log');
    });
});

test('hr settings custom fields page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/settings/custom-fields')
            ->waitForText('Custom Field', 10)
            ->assertPathIs('/hr/settings/custom-fields');
    });
});

test('hr settings webhooks page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/settings/webhooks')
            ->waitForText('Webhook', 10)
            ->assertPathIs('/hr/settings/webhooks');
    });
});
