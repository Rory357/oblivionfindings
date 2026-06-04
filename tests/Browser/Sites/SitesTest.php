<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('sites index page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/sites')
            ->waitForText('Site', 10)
            ->assertSee('Site');
    });
});

test('sites create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/sites/create')
            ->waitForText('Site', 10)
            ->assertSee('Site');
    });
});

test('sites reports page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/sites/reports')
            ->waitForText('Report', 10)
            ->assertSee('Report');
    });
});

test('checklists dashboard library loads', function () {
    // Templates are now managed from the in-page builder on /checklists — the
    // standalone /sites/checklists/templates pages were retired.
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/checklists')
            ->waitForText('Library', 10)
            ->assertSee('Library');
    });
});

test('sites reports asset condition page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/sites/reports/asset-condition')
            ->waitForText('Asset', 10)
            ->assertPathBeginsWith('/sites');
    });
});

test('sites reports checklist trends page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/sites/reports/checklist-trends')
            ->waitForText('Checklist', 10)
            ->assertPathBeginsWith('/sites');
    });
});

test('sites reports facilities page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/sites/reports/facilities')
            ->waitForText('Facilit', 10)
            ->assertPathBeginsWith('/sites');
    });
});

test('sites reports head office page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/sites/reports/head-office')
            ->waitForText('Head Office', 10)
            ->assertPathBeginsWith('/sites');
    });
});

test('sites reports houses page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/sites/reports/houses')
            ->waitForText('House', 10)
            ->assertPathBeginsWith('/sites');
    });
});

test('sites reports overdue actions page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/sites/reports/overdue-actions')
            ->waitForText('Overdue', 10)
            ->assertPathBeginsWith('/sites');
    });
});
