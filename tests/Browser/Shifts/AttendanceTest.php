<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('attendance index page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/attendance')
            ->waitForText('Attendance', 10)
            ->assertPathIs('/attendance');
    });
});
