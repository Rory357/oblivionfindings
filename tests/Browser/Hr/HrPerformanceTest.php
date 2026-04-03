<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr performance index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/performance')
            ->waitForText('Performance', 10)
            ->assertPathIs('/hr/performance');
    });
});

test('hr performance reviews index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/performance/reviews')
            ->waitForText('Review', 10)
            ->assertPathIs('/hr/performance/reviews');
    });
});

test('hr performance reviews create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/performance/reviews/create')
            ->waitForText('Review', 10)
            ->assertPathIs('/hr/performance/reviews/create');
    });
});

test('hr competencies page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/performance/competencies')
            ->waitForText('Competenc', 10)
            ->assertPathIs('/hr/performance/competencies');
    });
});

test('hr pips index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/performance/pips')
            ->waitForText('Performance Improvement', 10)
            ->assertPathIs('/hr/performance/pips');
    });
});

test('hr pips create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/performance/pips/create')
            ->waitForText('PIP', 10)
            ->assertPathIs('/hr/performance/pips/create');
    });
});

test('hr supervision create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/performance/supervision/create')
            ->waitForText('Supervision', 10)
            ->assertPathIs('/hr/performance/supervision/create');
    });
});
