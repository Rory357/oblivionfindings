<?php

use App\Models\User;
use App\Models\Client;
use Laravel\Dusk\Browser;

test('authenticated user can see edit client form', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $client = Client::factory()->create();

    $this->browse(function (Browser $browser) use ($user, $client) {
        $browser->loginAs($user)
            ->visit('/clients/' . $client->id . '/edit')
            ->waitForLocation('/clients/' . $client->id . '/edit')
            ->pause(500)
            ->assertPathBeginsWith('/clients/')
            ->assertSee('Client');
    });
});
