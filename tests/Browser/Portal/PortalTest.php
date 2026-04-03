<?php

use Laravel\Dusk\Browser;

test('portal login page loads', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/portal/login')
            ->waitForText('Login', 10)
            ->assertSee('Login');
    });
});
