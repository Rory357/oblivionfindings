<?php

// Superseded by tests/e2e/my-day-lifecycle-smoke.spec.ts, tests/e2e/my-roster-week-grid.spec.ts, and tests/e2e/frontline-published-visibility.spec.ts.
// Kept until 2026-08-01 for parity; safe to delete once Playwright suite has 30 consecutive green runs in CI.

use App\Models\User;
use Carbon\Carbon;
use Laravel\Dusk\Browser;

test('frontline my day uses the worker date and avoids admin client links', function () {
    $user = User::where('email', 'sw2@demo.test')->firstOrFail();
    $todayNz = Carbon::now('Pacific/Auckland')->format('l, j F Y');

    $this->browse(function (Browser $browser) use ($user, $todayNz) {
        $browser->loginAs($user)
            ->resize(390, 844)
            ->visit('/my-day')
            ->waitForText('Open items', 10)
            ->assertSee($todayNz);

        $adminClientLinks = (int) ($browser->script(
            'return document.querySelectorAll(\'a[href^="/clients/"]\').length;',
        )[0] ?? 0);
        $careLinks = (int) ($browser->script(
            'return document.querySelectorAll(\'a[href^="/operations/clients/"][href$="/care"]\').length;',
        )[0] ?? 0);

        expect($adminClientLinks)->toBe(0);
        expect($careLinks)->toBeGreaterThan(0);
    });
});

test('frontline more opens a worker sheet instead of leaving the workflow', function () {
    $user = User::where('email', 'sw2@demo.test')->firstOrFail();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->resize(390, 844)
            ->visit('/my-day')
            ->waitForText('Open items', 10)
            ->click('button[aria-label="More"]')
            ->waitForText('Quick worker links', 10)
            ->assertSee('Settings & profile')
            ->assertPathIs('/my-day');
    });
});

test('frontline meds can hand off to mar without a server error', function () {
    $user = User::where('email', 'sw2@demo.test')->firstOrFail();
    $todayNz = Carbon::now('Pacific/Auckland')->format('l, j F Y');

    $this->browse(function (Browser $browser) use ($user, $todayNz) {
        $browser->loginAs($user)
            ->resize(390, 844)
            ->visit('/meds/today')
            ->waitForText($todayNz, 10);

        $marUrl = (string) ($browser->script(
            <<<'JS'
return Array.from(document.querySelectorAll('a'))
    .find((node) => (node.getAttribute('href') ?? '').includes('/emar/mar?client_id='))
    ?.getAttribute('href') ?? '';
JS,
        )[0] ?? '');

        expect($marUrl)->not->toBe('');

        $browser->visit($marUrl)
            ->waitForText('MAR', 10)
            ->assertPathIs('/emar/mar')
            ->assertDontSee('Server Error');
    });
});
