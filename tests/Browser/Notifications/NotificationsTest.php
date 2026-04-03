<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('notifications page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/notifications')
            ->waitForText('Notification', 10)
            ->assertSee('Notification');
    });
});
