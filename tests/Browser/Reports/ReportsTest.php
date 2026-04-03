<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('reports index page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/reports')
            ->waitForText('Report', 10)
            ->assertSee('Report');
    });
});

test('incidents report page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/reports/incidents')
            ->waitForText('Incident', 10)
            ->assertSee('Incident');
    });
});

test('shifts report page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/reports/shifts')
            ->waitForText('Shift', 10)
            ->assertSee('Shift');
    });
});

test('assets report page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/reports/assets')
            ->waitForText('Asset', 10)
            ->assertSee('Asset');
    });
});

test('medications report page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/reports/medications')
            ->waitForText('Medication', 10)
            ->assertSee('Medication');
    });
});
