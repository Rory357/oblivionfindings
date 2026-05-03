<?php

// Superseded by tests/e2e/my-day-returned-timesheet.spec.ts, tests/e2e/my-day-end-of-shift.spec.ts, and tests/Feature/Routing/ShiftLegacyRedirectTest.php.
// Kept until 2026-08-01 for parity; safe to delete once Playwright suite has 30 consecutive green runs in CI.

use App\Models\User;
use Laravel\Dusk\Browser;

test('timesheets index page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/timesheets')
            ->waitForText('Timesheet', 10)
            ->assertPathIs('/operations/timesheets');
    });
});

test('timesheets approvals page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/timesheets/approvals')
            ->waitForText('Approval', 10)
            ->assertPathIs('/operations/timesheets/approvals');
    });
});

test('timesheets create page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/timesheets/create')
            ->waitForText('Timesheet', 10)
            ->assertPathIs('/operations/timesheets/create');
    });
});
