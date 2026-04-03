<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('control room index page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/control-room')
            ->waitForText('Control Room', 10)
            ->assertSee('Control Room');
    });
});

test('control room alerts page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/control-room/alerts')
            ->waitForText('Alert', 10)
            ->assertSee('Alert');
    });
});

test('control room broadcast page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/control-room/broadcast')
            ->waitForText('Broadcast', 10)
            ->assertSee('Broadcast');
    });
});

test('control room devices page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/control-room/devices')
            ->waitForText('Device', 10)
            ->assertSee('Device');
    });
});

test('control room escalations page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/control-room/escalations')
            ->waitForText('Escalation', 10)
            ->assertSee('Escalation');
    });
});

test('control room incidents page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/control-room/incidents')
            ->waitForText('Incident', 10)
            ->assertSee('Incident');
    });
});

test('control room map page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/control-room/map')
            ->waitForText('Map', 10)
            ->assertSee('Map');
    });
});

test('control room messaging page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/control-room/messaging')
            ->waitForText('Messaging', 10)
            ->assertSee('Messaging');
    });
});

test('control room my tasks page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/control-room/my-tasks')
            ->waitForText('Task', 10)
            ->assertSee('Task');
    });
});

test('control room playbooks page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/control-room/playbooks')
            ->waitForText('Playbook', 10)
            ->assertSee('Playbook');
    });
});

test('control room reports page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/control-room/reports')
            ->waitForText('Report', 10)
            ->assertSee('Report');
    });
});

test('control room settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/control-room/settings')
            ->waitForText('Setting', 10)
            ->assertSee('Setting');
    });
});

test('control room shifts page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/control-room/shifts')
            ->waitForText('Shift', 10)
            ->assertSee('Shift');
    });
});

test('control room SLA page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/control-room/sla')
            ->waitForText('SLA', 10)
            ->assertSee('SLA');
    });
});

test('control room SLA breaches page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/control-room/sla-breaches')
            ->waitForText('Breach', 10)
            ->assertSee('Breach');
    });
});

test('control room stats page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/control-room/stats')
            ->waitForText('Stat', 10)
            ->assertSee('Stat');
    });
});

test('control room integration alerts page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/control-room/integration-alerts')
            ->waitForText('Integration', 10)
            ->assertSee('Integration');
    });
});

test('control room messaging thread page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/control-room/messaging/thread')
            ->waitForText('Message', 10)
            ->assertPathBeginsWith('/control-room');
    });
});
