<?php

// Superseded by tests/e2e/attendance-readiness.spec.ts and tests/Feature/Routing/ShiftLegacyRedirectTest.php.
// Kept until 2026-08-01 for parity; safe to delete once Playwright suite has 30 consecutive green runs in CI.

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
