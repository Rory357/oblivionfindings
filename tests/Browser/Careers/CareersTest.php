<?php

use Laravel\Dusk\Browser;

test('careers index page loads', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/careers')
            ->waitForText('Career', 10)
            ->assertSee('Career');
    });
});
