<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr people index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/people')
            ->waitForText('People', 10)
            ->assertPathIs('/hr/people');
    });
});

test('hr people directory loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/directory')
            ->waitForText('Directory', 10)
            ->assertPathIs('/hr/directory');
    });
});

test('hr people profile documents page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $staff = \App\Models\Staff::factory()->forUser($user)->create();
        $browser->loginAs($user)
            ->visit('/hr/people/' . $staff->id . '/documents')
            ->waitForText('Document', 10)
            ->assertPathBeginsWith('/hr/people');
    });
});

test('hr people profile edit page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $staff = \App\Models\Staff::factory()->forUser($user)->create();
        $browser->loginAs($user)
            ->visit('/hr/people/' . $staff->id . '/edit')
            ->waitForText('Edit', 10)
            ->assertPathBeginsWith('/hr/people');
    });
});
