<?php

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\NextOfKin;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function grantClientLocationConsentRole(User $user, string $roleName, array $permissionKeys): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        [
            'label' => str($roleName)->headline(),
            'level' => 50,
            'type' => $roleName === 'client' || $roleName === 'next_of_kin' ? 'system' : 'custom',
        ],
    );

    foreach ($permissionKeys as $key) {
        Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'test', 'module' => 'Test'],
        );
    }

    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all(),
    );
    $user->roles()->attach($role->id);
}

function makeClientLocationConsentPortalUser(
    Client $client,
    string $roleName = 'next_of_kin',
    string $relation = 'next_of_kin',
    ?int $organizationId = null,
): User {
    $user = User::factory()->create([
        'organization_id' => $organizationId ?? $client->organization_id,
        'role' => $roleName,
        'approved_at' => now(),
    ]);
    grantClientLocationConsentRole($user, $roleName, ['clients.viewPortal']);
    $client->portalUsers()->attach($user->id, ['relation' => $relation]);

    if ($roleName !== 'client') {
        NextOfKin::query()->create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'relationship' => 'guardian',
        ]);
    }

    return $user;
}

function clientLocationTrackingConsentType(): ConsentType
{
    return ConsentType::query()->firstOrCreate(
        ['name' => 'Asset Location Tracking (Safety)'],
        ConsentType::factory()->make()->only([
            'category',
            'description',
            'purpose',
            'legal_basis',
            'is_mandatory',
            'requires_capacity_assessment',
            'allows_withdrawal',
            'withdrawal_notice_days',
            'validity_period_days',
            'renewal_required',
            'renewal_reminder_days',
            'version',
            'active',
        ]),
    );
}

function recordClientLocationTrackingConsent(
    Client $client,
    User $actor,
    array $overrides = [],
): ClientConsent {
    return ClientConsent::query()->create([
        'client_id' => $client->id,
        'consent_type_id' => clientLocationTrackingConsentType()->id,
        'status' => 'given',
        'given_at' => now()->subHour(),
        'expires_at' => now()->addMonth(),
        'given_by_user_id' => $actor->id,
        'given_by_relationship' => $actor->role === 'client' ? 'self' : 'staff',
        'given_method' => 'written',
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
        ...$overrides,
    ]);
}

function assignClientLocationConsentTracker(Client $client): Device
{
    $device = Device::factory()->tracking()->create([
        'tenant_id' => $client->organization_id ?? 1,
        'name' => 'Consent-gated tracker',
        'latitude' => -36.8485,
        'longitude' => 174.7633,
        'meta' => ['speed' => 4.5, 'heading' => 90],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_CLIENT,
        'assignable_id' => $client->id,
        'assigned_at' => now(),
    ]);

    return $device;
}

it('hard denies portal current and history location without active tracking consent for NOK and self identities', function () {
    $client = Client::factory()->create(['organization_id' => 1]);
    assignClientLocationConsentTracker($client);
    $nok = makeClientLocationConsentPortalUser($client);

    $this->actingAs($nok)
        ->get(route('portal.clients.location', $client, false))
        ->assertForbidden();
    $this->getJson(route('portal.clients.location.history', $client, false))
        ->assertForbidden();

    $selfClient = Client::factory()->create(['organization_id' => 1]);
    assignClientLocationConsentTracker($selfClient);
    $portalClient = makeClientLocationConsentPortalUser(
        $selfClient,
        roleName: 'client',
        relation: 'self',
    );

    $this->actingAs($portalClient)
        ->get(route('portal.clients.location', $selfClient, false))
        ->assertForbidden();
    $this->getJson(route('portal.clients.location.history', $selfClient, false))
        ->assertForbidden();
});

it('allows linked portal current and history location only while tracking consent is active', function () {
    $client = Client::factory()->create(['organization_id' => 2]);
    $device = assignClientLocationConsentTracker($client);
    $nok = makeClientLocationConsentPortalUser($client);

    recordClientLocationTrackingConsent($client, $nok, [
        'expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($nok)
        ->get(route('portal.clients.location', $client, false))
        ->assertForbidden();
    $this->getJson(route('portal.clients.location.history', $client, false))
        ->assertForbidden();

    $active = recordClientLocationTrackingConsent($client, $nok);

    $this->get(route('portal.clients.location', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/location')
            ->where('tracker.id', $device->id)
            ->where('currentLocation.lat', -36.8485)
            ->where('currentLocation.lng', 174.7633)
            ->where('trackingConsent.status', 'active'));
    $this->getJson(route('portal.clients.location.history', $client, false))
        ->assertOk()
        ->assertJsonStructure(['locations']);

    $active->update([
        'status' => 'withdrawn',
        'withdrawn_at' => now(),
        'withdrawn_by_user_id' => $nok->id,
    ]);

    $this->get(route('portal.clients.location', $client, false))
        ->assertForbidden();
    $this->getJson(route('portal.clients.location.history', $client, false))
        ->assertForbidden();
});

it('denies unlinked and cross-organization portal identities even when the client has tracking consent', function () {
    $client = Client::factory()->create(['organization_id' => 2]);
    assignClientLocationConsentTracker($client);
    $staff = User::factory()->create(['organization_id' => 2]);
    recordClientLocationTrackingConsent($client, $staff);

    $unlinked = User::factory()->create([
        'organization_id' => 2,
        'role' => 'next_of_kin',
        'approved_at' => now(),
    ]);
    grantClientLocationConsentRole($unlinked, 'next_of_kin', ['clients.viewPortal']);

    $this->actingAs($unlinked)
        ->get(route('portal.clients.location', $client, false))
        ->assertForbidden();
    $this->getJson(route('portal.clients.location.history', $client, false))
        ->assertForbidden();

    $crossOrganization = makeClientLocationConsentPortalUser(
        $client,
        organizationId: 1,
    );
    $this->actingAs($crossOrganization)
        ->get(route('portal.clients.location', $client, false))
        ->assertForbidden();
    $this->getJson(route('portal.clients.location.history', $client, false))
        ->assertForbidden();
});

it('redacts staff profile location and forbids staff history and commands without active tracking consent', function () {
    $client = Client::factory()->create(['organization_id' => 1]);
    assignClientLocationConsentTracker($client);
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientLocationConsentRole($manager, 'location_consent_manager_'.$manager->id, [
        'clients.viewAny',
        'assets.viewAny',
        'assets.telemetry.view',
        'assets.trackers.manage',
    ]);

    $this->actingAs($manager)
        ->get(route('operations.clients.show', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('location.trackingRestricted', true)
            ->where('location.canManage', false)
            ->where('location.tracker', null)
            ->where('location.currentLocation', null)
            ->where('location.trackingConsent', null)
            ->has('location.geofences', 0));

    $this->getJson(route('operations.clients.location.history', $client, false))
        ->assertForbidden();
    $this->post(route('operations.clients.location.locate-now', $client, false))
        ->assertForbidden();
    $this->post(route('operations.clients.location.acknowledge-panic', $client, false))
        ->assertForbidden();
});

it('preserves staff profile and history location access with active tracking consent', function () {
    $client = Client::factory()->create(['organization_id' => 1]);
    $device = assignClientLocationConsentTracker($client);
    $viewer = User::factory()->create(['organization_id' => 1]);
    grantClientLocationConsentRole($viewer, 'location_consent_viewer_'.$viewer->id, [
        'clients.viewAny',
        'assets.viewAny',
        'assets.telemetry.view',
    ]);
    recordClientLocationTrackingConsent($client, $viewer);

    $this->actingAs($viewer)
        ->get(route('operations.clients.show', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('location.canManage', false)
            ->where('location.tracker.id', $device->id)
            ->where('location.currentLocation.lat', -36.8485)
            ->where('location.trackingConsent.status', 'given'));

    $this->getJson(route('operations.clients.location.history', $client, false))
        ->assertOk()
        ->assertJsonStructure(['locations']);
});
