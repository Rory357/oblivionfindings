<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('settings admin-only data and users pages are forbidden without manage access', function () {
    $this->browse(function (Browser $browser) {
        $staff = User::where('email', 'staff@test.com')->firstOrFail();

        $browser->loginAs($staff)
            ->visit('/settings/data')
            ->waitForText('403', 10)
            ->visit('/settings/users')
            ->waitForText('403', 10);
    });
});
