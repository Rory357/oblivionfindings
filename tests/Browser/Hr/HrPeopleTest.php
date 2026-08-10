<?php

use App\Models\User;
use App\Domain\Hr\Models\HrEmployeeProfile;
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
        $profile = HrEmployeeProfile::query()->firstOrFail();
        $browser->loginAs($user)
            ->visit('/hr/people/' . $profile->id . '/documents')
            ->waitForText('Document', 10)
            ->assertPathBeginsWith('/hr/people');
    });
});

test('hr people profile edit page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $profile = HrEmployeeProfile::query()->firstOrFail();
        $browser->loginAs($user)
            ->visit('/hr/people/' . $profile->id . '/edit')
            ->waitForText('Edit', 10)
            ->assertPathBeginsWith('/hr/people');
    });
});
