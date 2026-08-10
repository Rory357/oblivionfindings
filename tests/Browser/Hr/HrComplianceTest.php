<?php

use App\Models\User;
use App\Domain\Hr\Models\HrEmployeeProfile;
use Laravel\Dusk\Browser;

test('hr compliance index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/compliance')
            ->waitForText('Compliance', 10)
            ->assertPathIs('/hr/compliance');
    });
});

test('hr compliance calendar loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/compliance/calendar')
            ->waitForText('Calendar', 10)
            ->assertPathIs('/hr/compliance/calendar');
    });
});

test('hr compliance drivers page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/compliance/drivers')
            ->waitForText('Driver', 10)
            ->assertPathIs('/hr/compliance/drivers');
    });
});

test('hr compliance matrix page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/compliance/matrix')
            ->waitForText('Matrix', 10)
            ->assertPathIs('/hr/compliance/matrix');
    });
});

test('hr compliance training page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/compliance/training')
            ->waitForText('Training', 10)
            ->assertPathIs('/hr/compliance/training');
    });
});

test('hr vetting index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/compliance/vetting')
            ->waitForText('Vetting', 10)
            ->assertPathIs('/hr/compliance/vetting');
    });
});

test('hr vetting create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/compliance/vetting/create')
            ->waitForText('Vetting', 10)
            ->assertPathIs('/hr/compliance/vetting/create');
    });
});

test('hr compliance staff page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $profile = HrEmployeeProfile::query()->firstOrFail();
        $browser->loginAs($user)
            ->visit('/hr/compliance/staff/' . $profile->user_id)
            ->waitForText('Compliance', 10)
            ->assertPathBeginsWith('/hr/compliance/staff');
    });
});
