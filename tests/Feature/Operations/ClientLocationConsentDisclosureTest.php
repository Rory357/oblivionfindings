<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\NextOfKin;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
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
): User {
    $user = User::factory()->create([
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

function makeClientLocationConsentClient(string $siteName): Client
{
    $site = Site::factory()->create([
        'name' => $siteName,
        'is_active' => true,
    ]);

    return Client::factory()->create([
        'site_id' => $site->id,
        'status' => 'active',
    ]);
}

function makeClientLocationConsentStaff(
    Site $site,
    string $roleName,
    array $permissionKeys,
): User {
    $user = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    grantClientLocationConsentRole($user, $roleName, $permissionKeys);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'position_role' => 'support_worker',
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    return $user;
}

function clientLocationTrackingConsentType(): ConsentType
{
    return ConsentType::query()->firstOrCreate(
        ['name' => 'Asset Location Tracking (Safety)'],
        [
            'category' => 'safety',
            'description' => 'Consent to location monitoring of a personal tracker for safety.',
            'purpose' => 'Client personal safety location tracking',
            'legal_basis' => 'consent',
            'is_mandatory' => false,
            'requires_capacity_assessment' => false,
            'allows_withdrawal' => true,
            'validity_period_days' => 365,
            'renewal_required' => true,
            'renewal_reminder_days' => 30,
            'version' => 1,
            'active' => true,
        ],
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

function assignClientLocationConsentTracker(
    Client $client,
    ?ClientConsent $consent = null,
): Device {
    $device = Device::factory()->tracking()->create([
        'name' => 'Consent-gated tracker',
        'latitude' => -36.8485,
        'longitude' => 174.7633,
        'meta' => ['speed' => 4.5, 'heading' => 90],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_CLIENT,
        'assignable_id' => $client->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
        'assigned_by_user_id' => $consent?->given_by_user_id,
        'consent_id' => $consent?->id,
        'tracking_purpose' => 'Client personal safety location tracking',
        'authority_basis' => 'assignment_linked_client_consent',
        'access_audience' => ['authorised_client_care', 'control_room', 'health_and_safety'],
        'retention_days' => 90,
        'collection_started_at' => now(),
    ]);

    return $device;
}

it('hard denies portal current and history location without active tracking consent for NOK and self identities', function () {
    $client = makeClientLocationConsentClient('Portal Consent Denial Home');
    assignClientLocationConsentTracker($client);
    $nok = makeClientLocationConsentPortalUser($client);

    $this->actingAs($nok)
        ->get(route('portal.clients.location', $client, false))
        ->assertForbidden();
    $this->getJson(route('portal.clients.location.history', $client, false))
        ->assertForbidden();

    $selfClient = makeClientLocationConsentClient('Self Consent Denial Home');
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
    $client = makeClientLocationConsentClient('Portal Consent Lifecycle Home');
    $nok = makeClientLocationConsentPortalUser($client);

    $expired = recordClientLocationTrackingConsent($client, $nok, [
        'expires_at' => now()->subMinute(),
    ]);
    $device = assignClientLocationConsentTracker($client, $expired);

    $this->actingAs($nok)
        ->get(route('portal.clients.location', $client, false))
        ->assertForbidden();
    $this->getJson(route('portal.clients.location.history', $client, false))
        ->assertForbidden();

    $active = recordClientLocationTrackingConsent($client, $nok);
    app(PersonalTrackingPrivacyService::class)->resumeClientAssignment(
        $device->assignments()->firstOrFail(),
        $active,
        $nok->id,
    );

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

it('denies unlinked and other-client portal identities even when the client has tracking consent', function () {
    $client = makeClientLocationConsentClient('Target Portal Home');
    $staff = makeClientLocationConsentStaff(
        $client->site,
        'location_consent_recorder_'.$client->id,
        [],
    );
    $consent = recordClientLocationTrackingConsent($client, $staff);
    assignClientLocationConsentTracker($client, $consent);

    $unlinked = User::factory()->create([
        'role' => 'next_of_kin',
        'approved_at' => now(),
    ]);
    grantClientLocationConsentRole($unlinked, 'next_of_kin', ['clients.viewPortal']);

    $this->actingAs($unlinked)
        ->get(route('portal.clients.location', $client, false))
        ->assertForbidden();
    $this->getJson(route('portal.clients.location.history', $client, false))
        ->assertForbidden();

    $otherClient = makeClientLocationConsentClient('Other Portal Home');
    $otherClientPortalUser = makeClientLocationConsentPortalUser($otherClient);
    $this->actingAs($otherClientPortalUser)
        ->get(route('portal.clients.location', $client, false))
        ->assertForbidden();
    $this->getJson(route('portal.clients.location.history', $client, false))
        ->assertForbidden();
});

it('redacts staff profile location and forbids staff history and commands without active tracking consent', function () {
    $client = makeClientLocationConsentClient('Staff Consent Denial Home');
    assignClientLocationConsentTracker($client);
    $manager = makeClientLocationConsentStaff($client->site, 'location_consent_manager', [
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
    $client = makeClientLocationConsentClient('Staff Consent Access Home');
    $viewer = makeClientLocationConsentStaff($client->site, 'location_consent_viewer', [
        'clients.viewAny',
        'assets.viewAny',
        'assets.telemetry.view',
    ]);
    $consent = recordClientLocationTrackingConsent($client, $viewer);
    $device = assignClientLocationConsentTracker($client, $consent);

    $this->actingAs($viewer)
        ->get(route('operations.clients.show', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('location.canManage', false)
            ->where('location.tracker.id', $device->id)
            ->where('location.tracker.detail_url', null)
            ->where('location.currentLocation.lat', -36.8485)
            ->where('location.trackingConsent.status', 'given'));

    $this->getJson(route('operations.clients.location.history', $client, false))
        ->assertOk()
        ->assertJsonStructure(['locations']);

    $this->get("/security-devices/devices/{$device->id}")->assertForbidden();

    $otherSite = Site::factory()->create(['is_active' => true]);
    $otherSiteViewer = makeClientLocationConsentStaff($otherSite, 'location_other_site_device_viewer', [
        'clients.viewAny',
        'assets.viewAny',
        'assets.telemetry.view',
        'securityDevices.viewAny',
        'securityDevices.devices.view',
    ]);

    $this->actingAs($otherSiteViewer)
        ->get(route('operations.clients.show', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('location.tracker.id', $device->id)
            ->where('location.tracker.detail_url', null));

    $this->get("/security-devices/devices/{$device->id}")->assertNotFound();

    $deviceViewer = makeClientLocationConsentStaff($client->site, 'location_device_viewer', [
        'clients.viewAny',
        'assets.viewAny',
        'assets.telemetry.view',
        'securityDevices.viewAny',
        'securityDevices.devices.view',
    ]);

    $this->actingAs($deviceViewer)
        ->get(route('operations.clients.show', $client, false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('location.tracker.id', $device->id)
            ->where('location.tracker.detail_url', "/security-devices/devices/{$device->id}"));

    $this->get("/security-devices/devices/{$device->id}")->assertOk();
});
