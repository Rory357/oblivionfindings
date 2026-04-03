<?php

use App\Models\User;
use App\Models\Client;
use Laravel\Dusk\Browser;

test('client documents page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $client = Client::factory()->create();

    $this->browse(function (Browser $browser) use ($user, $client) {
        $browser->loginAs($user)
            ->visit('/clients/' . $client->id . '/documents')
            ->pause(500)
            ->assertPathBeginsWith('/clients/' . $client->id)
            ->assertSee('Document');
    });
});

test('client incidents page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $client = Client::factory()->create();

    $this->browse(function (Browser $browser) use ($user, $client) {
        $browser->loginAs($user)
            ->visit('/clients/' . $client->id . '/incidents')
            ->pause(500)
            ->assertPathBeginsWith('/clients/' . $client->id)
            ->assertSee('Incident');
    });
});

test('client MAR page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $client = Client::factory()->create();

    $this->browse(function (Browser $browser) use ($user, $client) {
        $browser->loginAs($user)
            ->visit('/clients/' . $client->id . '/mar')
            ->pause(500)
            ->assertPathBeginsWith('/clients/' . $client->id)
            ->assertDontSee('500');
    });
});

test('client medical page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $client = Client::factory()->create();

    $this->browse(function (Browser $browser) use ($user, $client) {
        $browser->loginAs($user)
            ->visit('/clients/' . $client->id . '/medical')
            ->pause(500)
            ->assertPathBeginsWith('/clients/' . $client->id)
            ->assertSee('Medical');
    });
});

test('client portal users page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $client = Client::factory()->create();

    $this->browse(function (Browser $browser) use ($user, $client) {
        $browser->loginAs($user)
            ->visit('/clients/' . $client->id . '/portal-users')
            ->pause(500)
            ->assertPathBeginsWith('/clients/' . $client->id)
            ->assertDontSee('500');
    });
});

test('client risks page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $client = Client::factory()->create();

    $this->browse(function (Browser $browser) use ($user, $client) {
        $browser->loginAs($user)
            ->visit('/clients/' . $client->id . '/risks')
            ->pause(500)
            ->assertPathBeginsWith('/clients/' . $client->id)
            ->assertSee('Risk');
    });
});

test('client timeline page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $client = Client::factory()->create();

    $this->browse(function (Browser $browser) use ($user, $client) {
        $browser->loginAs($user)
            ->visit('/clients/' . $client->id . '/timeline')
            ->pause(500)
            ->assertPathBeginsWith('/clients/' . $client->id)
            ->assertSee('Timeline');
    });
});

test('client assignments page loads', function () {
    $user = User::where('email', 'admin@test.com')->first();
    $client = Client::factory()->create();

    $this->browse(function (Browser $browser) use ($user, $client) {
        $browser->loginAs($user)
            ->visit('/clients/' . $client->id . '/assignments')
            ->pause(500)
            ->assertPathBeginsWith('/clients/' . $client->id)
            ->assertSee('Assignment');
    });
});
