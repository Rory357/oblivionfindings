<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('system access page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/system/access')
            ->waitForText('Access', 10)
            ->assertSee('Access');
    });
});

test('system users page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/system/users')
            ->waitForText('User', 10)
            ->assertSee('User');
    });
});

test('system users create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/system/users/create')
            ->waitForText('User', 10)
            ->assertSee('User');
    });
});

test('system access assignments page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/system/access/assignments')
            ->waitForText('Assignment', 10)
            ->assertPathBeginsWith('/system');
    });
});

test('system access matrix page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/system/access/matrix')
            ->waitForText('Matrix', 10)
            ->assertPathBeginsWith('/system');
    });
});

test('system access roles page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/system/access/roles')
            ->waitForText('Role', 10)
            ->assertPathBeginsWith('/system');
    });
});
