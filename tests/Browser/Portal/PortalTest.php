<?php

use Laravel\Dusk\Browser;

test('portal login page loads', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/portal/login')
            ->waitForText('Sign in', 10)
            ->assertSee('Email me a sign-in link');
    });
});
