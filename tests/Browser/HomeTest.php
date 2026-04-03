<?php

use Laravel\Dusk\Browser;

test('home page loads for guests', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/')
            ->waitForText('Resident Management', 10)
            ->assertSee('Resident Management');
    });
});

test('about page loads', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/about')
            ->waitForText('About', 10)
            ->assertSee('About');
    });
});

test('features page loads', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/features')
            ->waitForText('Features', 10)
            ->assertSee('Features');
    });
});

test('contact page loads', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/contact')
            ->waitForText('Contact', 10)
            ->assertSee('Contact');
    });
});

test('terms page loads', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/terms')
            ->waitForText('Terms', 10)
            ->assertSee('Terms');
    });
});

test('pricing page loads', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/pricing')
            ->waitForText('Pricing', 10)
            ->assertSee('Pricing');
    });
});
