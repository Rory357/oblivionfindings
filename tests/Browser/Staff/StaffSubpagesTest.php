<?php

use App\Models\User;
use App\Models\Staff;
use Laravel\Dusk\Browser;

test('staff edit page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $staffUser = User::factory()->withoutTwoFactor()->create(['role' => 'support_worker']);
    Staff::factory()->create(['user_id' => $staffUser->id]);

    $this->browse(function (Browser $browser) use ($user, $staffUser) {
        $browser->loginAs($user)
            ->visit('/staff/' . $staffUser->id . '/edit')
            ->pause(500)
            ->assertPathBeginsWith('/staff/' . $staffUser->id)
            ->assertDontSee('500');
    });
});

test('staff assignments page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $staffUser = User::factory()->withoutTwoFactor()->create(['role' => 'support_worker']);
    Staff::factory()->create(['user_id' => $staffUser->id]);

    $this->browse(function (Browser $browser) use ($user, $staffUser) {
        $browser->loginAs($user)
            ->visit('/staff/' . $staffUser->id . '/assignments')
            ->pause(500)
            ->assertPathBeginsWith('/staff/' . $staffUser->id)
            ->assertSee('Assignment');
    });
});

test('staff availability page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $staffUser = User::factory()->withoutTwoFactor()->create(['role' => 'support_worker']);
    Staff::factory()->create(['user_id' => $staffUser->id]);

    $this->browse(function (Browser $browser) use ($user, $staffUser) {
        $browser->loginAs($user)
            ->visit('/staff/' . $staffUser->id . '/availability')
            ->pause(500)
            ->assertPathBeginsWith('/staff/' . $staffUser->id)
            ->assertSee('Availability');
    });
});

test('staff background checks page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $staffUser = User::factory()->withoutTwoFactor()->create(['role' => 'support_worker']);
    Staff::factory()->create(['user_id' => $staffUser->id]);

    $this->browse(function (Browser $browser) use ($user, $staffUser) {
        $browser->loginAs($user)
            ->visit('/staff/' . $staffUser->id . '/background-checks')
            ->pause(500)
            ->assertPathBeginsWith('/staff/' . $staffUser->id)
            ->assertDontSee('500');
    });
});

test('staff competency page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $staffUser = User::factory()->withoutTwoFactor()->create(['role' => 'support_worker']);
    Staff::factory()->create(['user_id' => $staffUser->id]);

    $this->browse(function (Browser $browser) use ($user, $staffUser) {
        $browser->loginAs($user)
            ->visit('/staff/' . $staffUser->id . '/competency')
            ->pause(500)
            ->assertPathBeginsWith('/staff/' . $staffUser->id)
            ->assertDontSee('500');
    });
});

test('staff credentials page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $staffUser = User::factory()->withoutTwoFactor()->create(['role' => 'support_worker']);
    Staff::factory()->create(['user_id' => $staffUser->id]);

    $this->browse(function (Browser $browser) use ($user, $staffUser) {
        $browser->loginAs($user)
            ->visit('/staff/' . $staffUser->id . '/credentials')
            ->pause(500)
            ->assertPathBeginsWith('/staff/' . $staffUser->id)
            ->assertDontSee('500');
    });
});

test('staff induction page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $staffUser = User::factory()->withoutTwoFactor()->create(['role' => 'support_worker']);
    Staff::factory()->create(['user_id' => $staffUser->id]);

    $this->browse(function (Browser $browser) use ($user, $staffUser) {
        $browser->loginAs($user)
            ->visit('/staff/' . $staffUser->id . '/induction')
            ->pause(500)
            ->assertPathBeginsWith('/staff/' . $staffUser->id)
            ->assertDontSee('500');
    });
});

test('staff timeline page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $staffUser = User::factory()->withoutTwoFactor()->create(['role' => 'support_worker']);
    Staff::factory()->create(['user_id' => $staffUser->id]);

    $this->browse(function (Browser $browser) use ($user, $staffUser) {
        $browser->loginAs($user)
            ->visit('/staff/' . $staffUser->id . '/timeline')
            ->pause(500)
            ->assertPathBeginsWith('/staff/' . $staffUser->id)
            ->assertSee('Timeline');
    });
});

test('staff training page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $staffUser = User::factory()->withoutTwoFactor()->create(['role' => 'support_worker']);
    Staff::factory()->create(['user_id' => $staffUser->id]);

    $this->browse(function (Browser $browser) use ($user, $staffUser) {
        $browser->loginAs($user)
            ->visit('/staff/' . $staffUser->id . '/training')
            ->pause(500)
            ->assertPathBeginsWith('/staff/' . $staffUser->id)
            ->assertDontSee('500');
    });
});

test('staff background checks index loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/staff/background-checks')
            ->waitForLocation('/staff/background-checks')
            ->pause(500)
            ->assertDontSee('500');
    });
});

test('staff training index loads', function () {
    $user = User::where('email', 'admin@test.com')->first();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/staff/training')
            ->waitForLocation('/staff/training')
            ->pause(500)
            ->assertDontSee('500');
    });
});
