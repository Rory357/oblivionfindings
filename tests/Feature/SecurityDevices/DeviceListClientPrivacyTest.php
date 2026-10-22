<?php

use App\Domain\SecurityDevices\Http\Controllers\Concerns\MapsDevicesForList;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** The device list, group and category pages all map rows through this trait. */
function deviceListRowMapper(): object
{
    return new class
    {
        use MapsDevicesForList;

        public function name(DeviceAssignment $assignment): string
        {
            return $this->resolveAssignableName($assignment);
        }
    };
}

function clientAssignment(Client $client): DeviceAssignment
{
    return new DeviceAssignment([
        'assignable_type' => DeviceAssignment::TARGET_CLIENT,
        'assignable_id' => $client->id,
    ]);
}

beforeEach(function () {
    $site = Site::factory()->create();
    $this->client = Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Hemi',
        'preferred_name' => 'Hemi W',
    ]);
    $this->actingAs(User::factory()->create(['approved_at' => now()]));
});

it('does not name a client to someone who may not view that client', function () {
    Gate::before(fn ($user, string $ability) => $ability === 'view' ? false : null);

    expect(deviceListRowMapper()->name(clientAssignment($this->client)))->toBe('Assigned client');
});

it('names the client to someone who may view that client', function () {
    Gate::before(fn ($user, string $ability) => $ability === 'view' ? true : null);

    expect(deviceListRowMapper()->name(clientAssignment($this->client)))->toBe('Hemi W');
});

it('never falls back to the client id', function () {
    Gate::before(fn ($user, string $ability) => $ability === 'view' ? true : null);
    $this->client->forceFill(['preferred_name' => null, 'first_name' => ''])->saveQuietly();

    expect(deviceListRowMapper()->name(clientAssignment($this->client->fresh())))->toBe('Assigned client');
});
