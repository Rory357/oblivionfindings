<?php

use App\Models\AssetGeofence;
use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\SiteHouseRoom;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->admin = User::factory()->create([
        'role' => 'admin',
        'organization_id' => 41,
        'approved_at' => now(),
    ]);
    $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

    $this->site = Site::factory()->create([
        'tenant_id' => 41,
        'type' => 'house',
    ]);
});

function sitePlacementWorker(int $organizationId): User
{
    $worker = User::factory()->create([
        'role' => 'support_worker',
        'organization_id' => $organizationId,
        'approved_at' => now(),
    ]);
    $worker->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());

    return $worker;
}

test('site profile has no duplicate client creation endpoint', function () {
    expect(Route::has('sites.clients.store'))->toBeFalse();
});

test('canonical full client creation records the authenticated organization', function () {
    $this->actingAs($this->admin)
        ->post(route('clients.store'), [
            '_modal' => true,
            'site_id' => $this->site->id,
            'first_name' => 'Aroha',
            'last_name' => 'Rangi',
            'status' => 'onboarding',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('clients', [
        'first_name' => 'Aroha',
        'last_name' => 'Rangi',
        'site_id' => $this->site->id,
        'organization_id' => 41,
    ]);
});

test('canonical full client creation rejects foreign and occupied placement options', function () {
    $foreignSite = Site::factory()->create(['tenant_id' => 99, 'type' => 'house']);
    $foreignContext = ServiceContext::create([
        'site_id' => $foreignSite->id,
        'type' => 'residential',
        'name' => 'Foreign residential care',
        'is_active' => true,
    ]);
    $foreignFence = AssetGeofence::query()->create([
        'site_id' => $foreignSite->id,
        'name' => 'Foreign home fence',
        'type' => 'circle',
        'scope' => 'house',
        'shape' => ['lat' => -41.2866, 'lng' => 174.7756, 'radius_m' => 50],
        'breach_type' => 'both',
        'is_active' => true,
    ]);
    $occupant = Client::factory()->create([
        'organization_id' => 99,
        'site_id' => $foreignSite->id,
    ]);
    $occupiedRoom = SiteHouseRoom::create([
        'site_id' => $foreignSite->id,
        'tenant_id' => 99,
        'name' => 'Occupied foreign room',
        'assigned_client_id' => $occupant->id,
        'is_active' => true,
        'is_assignable' => true,
    ]);

    $this->actingAs($this->admin)
        ->from(route('clients.index'))
        ->post(route('clients.store'), [
            '_modal' => true,
            'site_id' => $foreignSite->id,
            'room_id' => $occupiedRoom->id,
            'service_context_id' => $foreignContext->id,
            'house_geofence_id' => $foreignFence->id,
            'first_name' => 'Foreign',
            'last_name' => 'Placement',
            'status' => 'onboarding',
        ])
        ->assertRedirect(route('clients.index'))
        ->assertSessionHasErrors([
            'site_id',
            'room_id',
            'service_context_id',
            'house_geofence_id',
        ]);

    $this->assertDatabaseMissing('clients', [
        'first_name' => 'Foreign',
        'last_name' => 'Placement',
    ]);
});

test('links an unassigned client with placement metadata atomically', function () {
    $client = Client::factory()->create([
        'organization_id' => 41,
        'site_id' => null,
        'room_id' => null,
    ]);
    $room = SiteHouseRoom::create([
        'site_id' => $this->site->id,
        'tenant_id' => 41,
        'name' => 'Bedroom 1',
        'is_active' => true,
        'is_assignable' => true,
    ]);
    $context = ServiceContext::create([
        'site_id' => $this->site->id,
        'type' => 'residential',
        'name' => 'Residential care',
        'is_active' => true,
    ]);
    $worker = sitePlacementWorker(41);

    $this->actingAs($this->admin)
        ->from(route('sites.show', $this->site))
        ->post(route('sites.clients.link', $this->site), [
            'client_id' => $client->id,
            'room_id' => $room->id,
            'service_context_id' => $context->id,
            'key_worker_id' => $worker->id,
        ])
        ->assertRedirect(route('sites.show', $this->site))
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('clients', [
        'id' => $client->id,
        'site_id' => $this->site->id,
        'room_id' => $room->id,
        'service_context_id' => $context->id,
        'key_worker_id' => $worker->id,
    ]);
    $this->assertDatabaseHas('site_house_rooms', [
        'id' => $room->id,
        'assigned_client_id' => $client->id,
    ]);
});

test('rejects clients and service contexts outside the site tenant', function () {
    $foreignSite = Site::factory()->create(['tenant_id' => 99, 'type' => 'house']);
    $foreignClient = Client::factory()->create([
        'organization_id' => 99,
        'site_id' => null,
    ]);
    $foreignContext = ServiceContext::create([
        'site_id' => $foreignSite->id,
        'type' => 'residential',
        'name' => 'Foreign residential care',
        'is_active' => true,
    ]);

    $this->actingAs($this->admin)
        ->from(route('sites.show', $this->site))
        ->post(route('sites.clients.link', $this->site), [
            'client_id' => $foreignClient->id,
            'service_context_id' => $foreignContext->id,
        ])
        ->assertRedirect(route('sites.show', $this->site))
        ->assertSessionHasErrors(['client_id', 'service_context_id']);

    expect($foreignClient->fresh()->site_id)->toBeNull();
});

test('rejects occupied or foreign rooms and unauthorized key workers', function () {
    $client = Client::factory()->create([
        'organization_id' => 41,
        'site_id' => null,
    ]);
    $occupant = Client::factory()->create([
        'organization_id' => 41,
        'site_id' => $this->site->id,
    ]);
    $occupiedRoom = SiteHouseRoom::create([
        'site_id' => $this->site->id,
        'tenant_id' => 41,
        'name' => 'Occupied room',
        'assigned_client_id' => $occupant->id,
        'is_active' => true,
        'is_assignable' => true,
    ]);
    $foreignSite = Site::factory()->create(['tenant_id' => 99, 'type' => 'house']);
    $foreignRoom = SiteHouseRoom::create([
        'site_id' => $foreignSite->id,
        'tenant_id' => 99,
        'name' => 'Foreign room',
        'is_active' => true,
        'is_assignable' => true,
    ]);
    $foreignWorker = sitePlacementWorker(99);

    foreach ([$occupiedRoom, $foreignRoom] as $room) {
        $this->actingAs($this->admin)
            ->from(route('sites.show', $this->site))
            ->post(route('sites.clients.link', $this->site), [
                'client_id' => $client->id,
                'room_id' => $room->id,
                'key_worker_id' => $foreignWorker->id,
            ])
            ->assertRedirect(route('sites.show', $this->site))
            ->assertSessionHasErrors(['room_id', 'key_worker_id']);
    }

    expect($client->fresh()->site_id)->toBeNull()
        ->and($client->room_id)->toBeNull();
});

test('unlink clears the room assignment without deleting the client', function () {
    $client = Client::factory()->create([
        'organization_id' => 41,
        'site_id' => $this->site->id,
    ]);
    $room = SiteHouseRoom::create([
        'site_id' => $this->site->id,
        'tenant_id' => 41,
        'name' => 'Bedroom 2',
        'assigned_client_id' => $client->id,
        'assigned_from' => now()->toDateString(),
        'is_active' => true,
        'is_assignable' => true,
    ]);
    $client->update(['room_id' => $room->id]);

    $this->actingAs($this->admin)
        ->post(route('sites.clients.unlink', [$this->site, $client]))
        ->assertRedirect();

    $this->assertDatabaseHas('clients', [
        'id' => $client->id,
        'deleted_at' => null,
    ]);
    expect($client->fresh())->not->toBeNull()
        ->and($client->fresh()->site_id)->toBeNull()
        ->and($client->fresh()->room_id)->toBeNull()
        ->and($room->fresh()->assigned_client_id)->toBeNull();
});

test('room assignment keeps the room and client placement fields in sync', function () {
    $client = Client::factory()->create([
        'organization_id' => 41,
        'site_id' => $this->site->id,
        'room_id' => null,
    ]);
    $room = SiteHouseRoom::create([
        'site_id' => $this->site->id,
        'tenant_id' => 41,
        'name' => 'Bedroom 3',
        'is_active' => true,
        'is_assignable' => true,
    ]);

    $this->actingAs($this->admin)
        ->post(route('sites.rooms.assign', [$this->site, $room]), [
            'client_id' => $client->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($room->fresh()->assigned_client_id)->toBe($client->id)
        ->and($client->fresh()->room_id)->toBe($room->id)
        ->and($client->fresh()->site_id)->toBe($this->site->id);
});

test('room reassignment clears both sides of the previous placement', function () {
    $previous = Client::factory()->create([
        'organization_id' => 41,
        'site_id' => $this->site->id,
    ]);
    $replacement = Client::factory()->create([
        'organization_id' => 41,
        'site_id' => $this->site->id,
    ]);
    $room = SiteHouseRoom::create([
        'site_id' => $this->site->id,
        'tenant_id' => 41,
        'name' => 'Bedroom 4',
        'assigned_client_id' => $previous->id,
        'assigned_from' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'is_assignable' => true,
    ]);
    $previous->update(['room_id' => $room->id]);

    $this->actingAs($this->admin)
        ->post(route('sites.rooms.assign', [$this->site, $room]), [
            'client_id' => $replacement->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($previous->fresh()->room_id)->toBeNull()
        ->and($replacement->fresh()->room_id)->toBe($room->id)
        ->and($room->fresh()->assigned_client_id)->toBe($replacement->id);
});

test('room assignment rejects clients outside the site organization and clears client state on unassign', function () {
    $client = Client::factory()->create([
        'organization_id' => 41,
        'site_id' => $this->site->id,
    ]);
    $foreignClient = Client::factory()->create([
        'organization_id' => 99,
        'site_id' => null,
    ]);
    $room = SiteHouseRoom::create([
        'site_id' => $this->site->id,
        'tenant_id' => 41,
        'name' => 'Bedroom 5',
        'assigned_client_id' => $client->id,
        'assigned_from' => now()->toDateString(),
        'is_active' => true,
        'is_assignable' => true,
    ]);
    $client->update(['room_id' => $room->id]);

    $this->actingAs($this->admin)
        ->from(route('sites.rooms.index', $this->site))
        ->post(route('sites.rooms.assign', [$this->site, $room]), [
            'client_id' => $foreignClient->id,
        ])
        ->assertRedirect(route('sites.rooms.index', $this->site))
        ->assertSessionHasErrors('client_id');

    expect($room->fresh()->assigned_client_id)->toBe($client->id)
        ->and($foreignClient->fresh()->room_id)->toBeNull();

    $this->actingAs($this->admin)
        ->post(route('sites.rooms.assign', [$this->site, $room]), [
            'client_id' => null,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($room->fresh()->assigned_client_id)->toBeNull()
        ->and($client->fresh()->room_id)->toBeNull();
});
