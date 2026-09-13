<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('retired today bookmark opens my day without a duplicate navigation entry', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/today')
            ->waitForLocation('/my-day', 15)
            ->assertPathIs('/my-day')
            ->assertSee('My Day')
            ->assertMissing('#app-sidebar-nav a[href="/today"]');
    });
});
