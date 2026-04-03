<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('audit logs page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/audit-logs')
            ->waitForText('Audit', 10)
            ->assertSee('Audit');
    });
});

test('timeline page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/timeline')
            ->waitForText('Timeline', 10)
            ->assertSee('Timeline');
    });
});

test('emergency access page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/emergency-access')
            ->waitForText('Emergency', 10)
            ->assertSee('Emergency');
    });
});

test('smart monitoring page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/smart-monitoring')
            ->waitForText('Smart Monitoring', 10)
            ->assertSee('Smart Monitoring');
    });
});

test('workers page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/workers')
            ->waitForText('Worker', 10)
            ->assertSee('Worker');
    });
});

test('competency frameworks page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/competency-frameworks')
            ->waitForText('Competency', 10)
            ->assertSee('Competency');
    });
});

test('competency frameworks create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/competency-frameworks/create')
            ->waitForText('Competency', 10)
            ->assertSee('Competency');
    });
});

test('quality checklist page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/quality/checklist')
            ->waitForText('Quality', 10)
            ->assertPathBeginsWith('/quality');
    });
});

test('integrations unifi page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/integrations/unifi')
            ->waitForText('UniFi', 10)
            ->assertPathBeginsWith('/integrations');
    });
});

test('health safety test page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/health-safety-test')
            ->waitForText('Health', 10)
            ->assertPathBeginsWith('/health-safety');
    });
});
