<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('health safety index page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/health-safety')
            ->waitForText('Health', 10)
            ->assertSee('Health');
    });
});

test('health safety analytics page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/health-safety/analytics')
            ->waitForText('Analytics', 10)
            ->assertSee('Analytics');
    });
});

test('health safety drills page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/health-safety/drills')
            ->waitForText('Drill', 10)
            ->assertSee('Drill');
    });
});

test('health safety drills create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/health-safety/drills/create')
            ->waitForText('Drill', 10)
            ->assertSee('Drill');
    });
});

test('health safety first aid page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/health-safety/first-aid')
            ->waitForText('First Aid', 10)
            ->assertSee('First Aid');
    });
});

test('health safety injuries page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/health-safety/injuries')
            ->waitForText('Injur', 10)
            ->assertSee('Injur');
    });
});

test('health safety injuries create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/health-safety/injuries/create')
            ->waitForText('Injur', 10)
            ->assertSee('Injur');
    });
});

test('health safety lone workers page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/health-safety/lone-workers')
            ->waitForText('Lone Worker', 10)
            ->assertSee('Lone Worker');
    });
});

test('health safety PPE page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/health-safety/ppe')
            ->waitForText('PPE', 10)
            ->assertSee('PPE');
    });
});

test('health safety procedures page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/health-safety/procedures')
            ->waitForText('Procedure', 10)
            ->assertSee('Procedure');
    });
});

test('health safety procedures create deep-link opens the wizard', function () {
    // Modal-first: /create now redirects to the register with the New-procedure wizard open.
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/health-safety/procedures/create')
            ->waitForText('New procedure', 10)
            ->assertSee('Procedure completeness');
    });
});

test('health safety restraints page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/health-safety/restraints')
            ->waitForText('Restraint', 10)
            ->assertSee('Restraint');
    });
});

test('health safety substances page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/health-safety/substances')
            ->waitForText('Substance', 10)
            ->assertSee('Substance');
    });
});

test('health safety substances create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/health-safety/substances/create')
            ->waitForText('Substance', 10)
            ->assertSee('Substance');
    });
});

test('health safety worker participation page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/health-safety/worker-participation')
            ->waitForText('Worker Participation', 10)
            ->assertSee('Worker Participation');
    });
});
