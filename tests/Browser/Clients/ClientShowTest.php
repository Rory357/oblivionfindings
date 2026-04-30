<?php

use App\Models\User;
use App\Models\Client;
use Laravel\Dusk\Browser;

test('authenticated user can view a client profile', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $client = Client::factory()->create();

    $this->browse(function (Browser $browser) use ($user, $client) {
        $browser->loginAs($user)
            ->visit('/operations/clients/' . $client->id)
            ->waitForLocation('/operations/clients/' . $client->id)
            ->pause(500)
            ->assertPathBeginsWith('/operations/clients/')
            ->assertSee('Client');
    });
});

test('client profile shows client information', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $client = Client::factory()->create();

    $this->browse(function (Browser $browser) use ($user, $client) {
        $browser->loginAs($user)
            ->visit('/operations/clients/' . $client->id)
            ->waitForLocation('/operations/clients/' . $client->id)
            ->pause(500)
            ->assertSee($client->first_name ?? $client->name ?? 'Client');
    });
});
