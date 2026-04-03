<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr announcements index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/announcements')
            ->waitForText('Announcement', 10)
            ->assertPathIs('/hr/announcements');
    });
});

test('hr announcements create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/announcements/create')
            ->waitForText('Announcement', 10)
            ->assertPathIs('/hr/announcements/create');
    });
});
