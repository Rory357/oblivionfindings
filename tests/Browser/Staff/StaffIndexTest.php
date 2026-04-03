<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('authenticated user can view staff index', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/staff')
            ->waitForLocation('/staff')
            ->pause(500)
            ->assertSee('Staff');
    });
});

test('staff page loads with content', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $staffUser = User::factory()->withoutTwoFactor()->create(['role' => 'support_worker']);
    \App\Models\Staff::factory()->create(['user_id' => $staffUser->id]);

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/staff')
            ->waitForLocation('/staff')
            ->pause(500)
            ->assertSee('Staff')
            ->assertDontSee('500');
    });
});
