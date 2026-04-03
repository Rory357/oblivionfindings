<?php

use App\Models\User;
use App\Models\Staff;
use Laravel\Dusk\Browser;

test('authenticated user can view staff profile', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $staffUser = User::factory()->withoutTwoFactor()->create(['role' => 'support_worker']);
    Staff::factory()->create(['user_id' => $staffUser->id]);

    $this->browse(function (Browser $browser) use ($user, $staffUser) {
        $browser->loginAs($user)
            ->visit('/staff/' . $staffUser->id)
            ->waitForLocation('/staff/' . $staffUser->id)
            ->pause(500)
            ->assertPathBeginsWith('/staff/')
            ->assertSee('Staff');
    });
});

test('staff profile shows staff information', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $staffUser = User::factory()->withoutTwoFactor()->create(['role' => 'support_worker']);
    Staff::factory()->create(['user_id' => $staffUser->id]);

    $this->browse(function (Browser $browser) use ($user, $staffUser) {
        $browser->loginAs($user)
            ->visit('/staff/' . $staffUser->id)
            ->waitForLocation('/staff/' . $staffUser->id)
            ->pause(500)
            ->assertSee($staffUser->name);
    });
});
