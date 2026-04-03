<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('training courses page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/training/courses')
            ->waitForText('Course', 10)
            ->assertSee('Course');
    });
});

test('training courses create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/training/courses/create')
            ->waitForText('Course', 10)
            ->assertSee('Course');
    });
});

test('training matrix page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/training/matrix')
            ->waitForText('Matrix', 10)
            ->assertSee('Matrix');
    });
});
