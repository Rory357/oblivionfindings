<?php

// Superseded by tests/e2e/operations-shifts-detail.spec.ts and tests/Feature/Routing/ShiftLegacyRedirectTest.php.
// Kept until 2026-08-01 for parity; safe to delete once Playwright suite has 30 consecutive green runs in CI.

use App\Models\User;
use Laravel\Dusk\Browser;

test('shifts index page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/shifts')
            ->waitForText('Shift', 10)
            ->assertPathIs('/operations/shifts');
    });
});

test('shifts create page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/shifts/create')
            ->waitForText('Shift', 10)
            ->assertPathIs('/operations/shifts/create');
    });
});

test('rostering page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/rostering')
            ->waitForText('Roster', 10)
            ->assertPathIs('/operations/rostering');
    });
});
