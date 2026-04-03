<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('respite index page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite')
            ->waitForText('Respite', 10)
            ->assertSee('Respite');
    });
});

test('respite bookings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/bookings')
            ->waitForText('Booking', 10)
            ->assertSee('Booking');
    });
});

test('respite bookings create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/bookings/create')
            ->waitForText('Booking', 10)
            ->assertSee('Booking');
    });
});

test('respite calendar page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/calendar')
            ->waitForText('Calendar', 10)
            ->assertSee('Calendar');
    });
});

test('respite communication logs page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/communication-logs')
            ->waitForText('Communication', 10)
            ->assertSee('Communication');
    });
});

test('respite communication logs create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/communication-logs/create')
            ->waitForText('Communication', 10)
            ->assertSee('Communication');
    });
});

test('respite daily notes page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/daily-notes')
            ->waitForText('Daily Note', 10)
            ->assertSee('Daily Note');
    });
});

test('respite daily notes create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/daily-notes/create')
            ->waitForText('Daily Note', 10)
            ->assertSee('Daily Note');
    });
});

test('respite evidence packs page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/evidence-packs')
            ->waitForText('Evidence', 10)
            ->assertSee('Evidence');
    });
});

test('respite evidence packs create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/evidence-packs/create')
            ->waitForText('Evidence', 10)
            ->assertSee('Evidence');
    });
});

test('respite handover notes page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/handover-notes')
            ->waitForText('Handover', 10)
            ->assertSee('Handover');
    });
});

test('respite handover notes create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/handover-notes/create')
            ->waitForText('Handover', 10)
            ->assertSee('Handover');
    });
});

test('respite procedures page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/procedures')
            ->waitForText('Procedure', 10)
            ->assertSee('Procedure');
    });
});

test('respite procedures create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/procedures/create')
            ->waitForText('Procedure', 10)
            ->assertSee('Procedure');
    });
});

test('respite procedure runs page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/procedure-runs')
            ->waitForText('Procedure Run', 10)
            ->assertSee('Procedure Run');
    });
});

test('respite procedure runs create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/procedure-runs/create')
            ->waitForText('Procedure Run', 10)
            ->assertSee('Procedure Run');
    });
});

test('respite requests page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/requests')
            ->waitForText('Request', 10)
            ->assertSee('Request');
    });
});

test('respite requests create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/requests/create')
            ->waitForText('Request', 10)
            ->assertSee('Request');
    });
});

test('respite resources page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/resources')
            ->waitForText('Resource', 10)
            ->assertSee('Resource');
    });
});

test('respite tasks page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/tasks')
            ->waitForText('Task', 10)
            ->assertSee('Task');
    });
});

test('respite risk plan activations page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/risk-plan-activations')
            ->waitForText('Risk Plan', 10)
            ->assertSee('Risk Plan');
    });
});

test('respite risk plan activations create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/risk-plan-activations/create')
            ->waitForText('Risk Plan', 10)
            ->assertSee('Risk Plan');
    });
});

test('respite daily notes with concerns page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/daily-notes/with-concerns')
            ->waitForText('Daily', 10)
            ->assertPathBeginsWith('/respite');
    });
});

test('respite daily notes with incidents page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/daily-notes/with-incidents')
            ->waitForText('Daily', 10)
            ->assertPathBeginsWith('/respite');
    });
});

test('respite handover notes unacknowledged page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/handover-notes/unacknowledged')
            ->waitForText('Handover', 10)
            ->assertPathBeginsWith('/respite');
    });
});

test('respite procedure runs my active page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/procedure-runs/my-active')
            ->waitForText('Procedure', 10)
            ->assertPathBeginsWith('/respite');
    });
});

test('respite procedure runs overdue page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/procedure-runs/overdue')
            ->waitForText('Procedure', 10)
            ->assertPathBeginsWith('/respite');
    });
});

test('respite referrals create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/referrals/create')
            ->waitForText('Referral', 10)
            ->assertPathBeginsWith('/respite');
    });
});

test('respite risk plan activations needing acknowledgment page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/risk-plan-activations/needing-acknowledgment')
            ->waitForText('Risk Plan', 10)
            ->assertPathBeginsWith('/respite');
    });
});

test('respite tasks awaiting approval page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/tasks/awaiting-approval')
            ->waitForText('Task', 10)
            ->assertPathBeginsWith('/respite');
    });
});

test('respite tasks my tasks page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/tasks/my-tasks')
            ->waitForText('Task', 10)
            ->assertPathBeginsWith('/respite');
    });
});

test('respite tasks overdue page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/respite/tasks/overdue')
            ->waitForText('Task', 10)
            ->assertPathBeginsWith('/respite');
    });
});
