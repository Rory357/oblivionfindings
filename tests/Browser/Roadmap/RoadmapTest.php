<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('roadmap dashboard page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/roadmap/dashboard')
            ->waitForText('Roadmap', 10)
            ->assertSee('Roadmap');
    });
});

test('roadmap initiatives page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/roadmap/initiatives')
            ->waitFor('[data-testid="initiative-register-table"]', 10)
            ->assertPresent('[data-testid="initiative-register-table"]');
    });
});

test('roadmap decisions page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/roadmap/decisions')
            ->waitFor('[data-testid="roadmap-decisions-table"]', 10)
            ->assertPresent('[data-testid="roadmap-decisions-table"]');
    });
});

test('roadmap suggestions page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/roadmap/suggestions')
            ->waitFor('[data-testid="suggestion-backlog-table"]', 10)
            ->assertPresent('[data-testid="suggestion-backlog-table"]');
    });
});

test('roadmap quarterly plans page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/roadmap/quarterly-plans')
            ->waitFor('[data-testid="quarterly-plan-history-table"]', 10)
            ->assertPresent('[data-testid="quarterly-plan-history-table"]');
    });
});
