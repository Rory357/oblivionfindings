<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('privacy dashboard page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/privacy')
            ->waitForText('Privacy', 10)
            ->assertSee('Privacy');
    });
});

test('privacy breaches page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/privacy/breaches')
            ->waitForText('Breach', 10)
            ->assertSee('Breach');
    });
});

test('privacy breaches create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/privacy/breaches/create')
            ->waitForText('Breach', 10)
            ->assertSee('Breach');
    });
});

test('privacy requests page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/privacy/requests')
            ->waitForText('Request', 10)
            ->assertSee('Request');
    });
});

test('privacy requests create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/privacy/requests/create')
            ->waitForText('Request', 10)
            ->assertSee('Request');
    });
});

test('privacy retention page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/privacy/retention')
            ->waitForText('Retention', 10)
            ->assertSee('Retention');
    });
});

test('privacy retention create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/privacy/retention/create')
            ->waitForText('Retention', 10)
            ->assertSee('Retention');
    });
});

test('privacy PIA page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/privacy/pia')
            ->waitForText('PIA', 10)
            ->assertSee('PIA');
    });
});

test('privacy PIA create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/privacy/pia/create')
            ->waitForText('PIA', 10)
            ->assertSee('PIA');
    });
});

test('privacy legal holds page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/privacy/legal-holds')
            ->waitForText('Legal Hold', 10)
            ->assertSee('Legal Hold');
    });
});

test('privacy legal holds create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/privacy/legal-holds/create')
            ->waitForText('Legal Hold', 10)
            ->assertSee('Legal Hold');
    });
});

test('privacy deletion logs page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/privacy/deletion-logs')
            ->waitForText('Deletion', 10)
            ->assertSee('Deletion');
    });
});

test('privacy reports page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/privacy/reports')
            ->waitForText('Report', 10)
            ->assertSee('Report');
    });
});

test('privacy reports compliance page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/privacy/reports/compliance')
            ->waitForText('Compliance', 10)
            ->assertPathBeginsWith('/privacy');
    });
});

test('privacy retention review page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/privacy/retention/review')
            ->waitForText('Retention', 10)
            ->assertPathBeginsWith('/privacy');
    });
});
