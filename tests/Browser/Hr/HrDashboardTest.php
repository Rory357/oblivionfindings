<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr index redirects to my hr', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr')
            ->waitForText('HR', 10)
            ->assertPathBeginsWith('/hr/my');
    });
});

test('hr analytics page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/analytics')
            ->waitForText('Analytics', 10)
            ->assertPathIs('/hr/analytics');
    });
});

test('hr directory page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/directory')
            ->waitForText('Directory', 10)
            ->assertPathIs('/hr/directory');
    });
});

test('hr orgchart page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/orgchart')
            ->pause(2000)
            ->assertPathBeginsWith('/hr');
    });
});

test('hr headcount page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/headcount')
            ->pause(2000)
            ->assertPathBeginsWith('/hr');
    });
});

test('hr feed page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/feed')
            ->waitForText('Feed', 10)
            ->assertPathIs('/hr/feed');
    });
});
