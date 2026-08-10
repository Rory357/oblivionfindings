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
            ->waitForText('Initiative', 10)
            ->assertSee('Initiative');
    });
});

test('roadmap decisions page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/roadmap/decisions')
            ->waitForText('Decision', 10)
            ->assertSee('Decision');
    });
});

test('roadmap suggestions page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/roadmap/suggestions')
            ->waitForText('Suggestion', 10)
            ->assertSee('Suggestion');
    });
});

test('roadmap quarterly plans page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/roadmap/quarterly-plans')
            ->waitForText('Quarterly', 10)
            ->assertSee('Quarterly');
    });
});
