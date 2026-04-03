<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr recruitment index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/recruitment')
            ->waitForText('Recruitment', 10)
            ->assertPathIs('/hr/recruitment');
    });
});

test('hr candidates index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/recruitment/candidates')
            ->waitForText('Candidate', 10)
            ->assertPathBeginsWith('/hr/recruitment');
    });
});

test('hr candidates create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/recruitment/candidates/create')
            ->waitForText('Candidate', 10)
            ->assertPathIs('/hr/recruitment/candidates/create');
    });
});

test('hr recruitment jobs page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/recruitment/jobs')
            ->waitForText('Job', 10)
            ->assertPathIs('/hr/recruitment/jobs');
    });
});

test('hr recruitment kanban page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/recruitment/kanban')
            ->waitForText('Kanban', 10)
            ->assertPathIs('/hr/recruitment/kanban');
    });
});

test('hr recruitment analytics page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/recruitment/analytics')
            ->waitForText('Analytics', 10)
            ->assertPathIs('/hr/recruitment/analytics');
    });
});

test('hr recruitment kits page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/recruitment/kits')
            ->waitForText('Kit', 10)
            ->assertPathIs('/hr/recruitment/kits');
    });
});
