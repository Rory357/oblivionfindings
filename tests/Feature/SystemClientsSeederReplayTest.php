<?php

use App\Models\Client;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemCatalogSeeder;
use Database\Seeders\SystemClientsSeeder;
use Database\Seeders\SystemUsersSeeder;

test('system clients seeder re-runs update the same seeded people instead of adding duplicate identities', function () {
    $this->seed([RbacSeeder::class, SystemCatalogSeeder::class, SystemUsersSeeder::class, SystemClientsSeeder::class]);
    $seeded = fn () => Client::withTrashed()->get()
        ->filter(fn (Client $client) => str_ends_with((string) $client->email, '@client.demo.test'));
    $first = $seeded()->mapWithKeys(fn (Client $client) => [$client->nhi_number => $client->id]);
    expect($first)->toHaveCount(12);

    // Playwright's global setup re-runs this seeder on an already seeded database,
    // including after a spec has archived one of these people.
    Client::query()->whereKey($first['NGT1234'])->sole()->delete();
    $this->seed(SystemClientsSeeder::class);

    $again = $seeded();
    expect($again)->toHaveCount(12)
        ->and($again->mapWithKeys(fn (Client $client) => [$client->nhi_number => $client->id])->all())->toBe($first->all())
        ->and($again->every(fn (Client $client) => ! $client->trashed()))->toBeTrue()
        ->and(Client::query()->whereKey($first['NGT1234'])->sole()->email)->toBe('rosie.ngata@client.demo.test');
    foreach ($first->keys() as $nhi) {
        expect(Client::withTrashed()->where('nhi_hash', Client::nhiHash($nhi))->count())->toBe(1);
    }
});
