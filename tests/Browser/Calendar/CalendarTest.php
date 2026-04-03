<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('calendar page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/calendar')
            ->waitForText('Calendar', 10)
            ->assertSee('Calendar');
    });
});

test('my calendar page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/my-calendar')
            ->waitForText('Calendar', 10)
            ->assertSee('Calendar');
    });
});

test('my tasks page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/my-tasks')
            ->waitForText('Task', 10)
            ->assertSee('Task');
    });
});
