<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('governance risks index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/risks')
            ->waitForText('Risks', 10)
            ->assertSee('Risks');
    });
});

test('governance risks create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/risks/create')
            ->waitForText('Risk', 10)
            ->assertSee('Risk');
    });
});

test('governance risks heatmap page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/risks/heatmap')
            ->waitForText('Heatmap', 10)
            ->assertSee('Heatmap');
    });
});

test('governance risks trends page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/risks/trends')
            ->waitForText('Trends', 10)
            ->assertSee('Trends');
    });
});
