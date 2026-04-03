<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('careers job apply page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/careers/test-job/apply')
            ->waitForText('Apply', 10)
            ->assertPathBeginsWith('/careers');
    });
});
