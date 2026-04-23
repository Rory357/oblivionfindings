<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('training courses page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/training/courses')
            ->waitForLocation('/hr/training/catalog')
            ->waitForText('Course Catalog', 10)
            ->assertPathIs('/hr/training/catalog');
    });
});

test('training courses create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/training/courses/create')
            ->waitForLocation('/hr/training/catalog?open=create')
            ->waitForText('New Course', 10)
            ->assertPathBeginsWith('/hr/training/catalog');
    });
});

test('training matrix page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/training/matrix')
            ->waitForLocation('/hr/compliance/training')
            ->waitForText('Training Dashboard', 10)
            ->assertPathIs('/hr/compliance/training');
    });
});
