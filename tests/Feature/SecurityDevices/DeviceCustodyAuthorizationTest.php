<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthoritativeConsentFixture;
use Tests\TestCase;

class DeviceCustodyAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
    }

    public function test_site_bound_resident_surfaces_conceal_foreign_device_location_and_counts(): void
    {
        $visibleSite = Site::factory()->create();
        $hiddenSite = Site::factory()->create();
        $viewer = $this->siteViewer($visibleSite, [
            'fleet.viewAny',
            'fleet.manage',
            'assets.telemetry.view',
            'assets.telemetry.export',
            'clients.viewAny',
            'securityDevices.viewAny',
            'securityDevices.devices.view',
            'securityDevices.devices.assign',
        ]);
        $visibleClient = Client::factory()->create(['site_id' => $visibleSite->id, 'status' => 'active']);
        $hiddenClient = Client::factory()->create(['site_id' => $hiddenSite->id, 'status' => 'active']);
        $visibleDevice = $this->residentTracker($visibleClient, $viewer);
        $hiddenDevice = $this->residentTracker($hiddenClient, $viewer);

        $this->actingAs($viewer)
            ->get('/fleet-assets/resident-tracking')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('residents', 1)
                ->where('residents.0.client_id', $visibleClient->id)
                ->where('stats.tracked', 1));

        $this->actingAs($viewer)
            ->get("/fleet-assets/resident-tracking/history/{$hiddenClient->id}")
            ->assertNotFound();
        $this->actingAs($viewer)
            ->get("/fleet-assets/resident-tracking/history/{$hiddenClient->id}/privacy-status")
            ->assertNotFound();
        $this->actingAs($viewer)
            ->post("/fleet-assets/resident-tracking/{$hiddenDevice->id}/unassign")
            ->assertNotFound();
        $this->actingAs($viewer)
            ->get("/security-devices/devices/{$hiddenDevice->id}")
            ->assertNotFound();

        $this->assertTrue(DeviceAssignment::query()->where('device_id', $hiddenDevice->id)->current()->exists());
        $this->assertTrue(DeviceAssignment::query()->where('device_id', $visibleDevice->id)->current()->exists());
    }

    public function test_explicit_global_role_can_view_both_resident_sites(): void
    {
        $admin = $this->globalAdmin();
        $firstClient = Client::factory()->create(['site_id' => Site::factory()->create()->id, 'status' => 'active']);
        $secondClient = Client::factory()->create(['site_id' => Site::factory()->create()->id, 'status' => 'active']);
        $this->residentTracker($firstClient, $admin);
        $this->residentTracker($secondClient, $admin);

        $this->actingAs($admin)
            ->get('/fleet-assets/resident-tracking')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('residents', 2)
                ->where('stats.tracked', 2));
    }

    public function test_generic_fleet_or_asset_consent_does_not_authorise_resident_location(): void
    {
        $admin = $this->globalAdmin();
        $client = Client::factory()->create(['site_id' => Site::factory()->create()->id, 'status' => 'active']);
        $device = Device::factory()->tracking()->create();

        foreach (['Fleet Tracking', 'Asset Location Tracking (Safety)'] as $typeName) {
            $consent = $this->consent($client, $admin, $typeName);

            $this->actingAs($admin)
                ->post('/fleet-assets/resident-tracking/assign', [
                    'tracker_id' => $device->id,
                    'client_id' => $client->id,
                    'consent_id' => $consent->id,
                ])
                ->assertSessionHasErrors('consent_id');

            $this->assertDatabaseMissing('device_assignments', [
                'device_id' => $device->id,
                'assignable_type' => DeviceAssignment::TARGET_CLIENT,
                'assignable_id' => $client->id,
                'released_at' => null,
            ]);
        }

        $futureConsent = $this->consent($client, $admin, 'Personal Tracker (Wandering Risk)');
        $futureConsent->update(['given_at' => now()->addDay()]);

        $this->actingAs($admin)
            ->post('/fleet-assets/resident-tracking/assign', [
                'tracker_id' => $device->id,
                'client_id' => $client->id,
                'consent_id' => $futureConsent->id,
            ])
            ->assertSessionHasErrors('consent_id');

        $this->assertDatabaseMissing('device_assignments', [
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $client->id,
            'released_at' => null,
        ]);
    }

    public function test_mismatched_client_site_device_and_consent_binding_fails_closed(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $viewer = $this->siteViewer($site, [
            'fleet.viewAny',
            'assets.telemetry.view',
            'clients.viewAny',
            'securityDevices.viewAny',
            'securityDevices.devices.view',
        ]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $device = $this->residentTracker($client, $viewer);
        $assignment = $device->assignments()->current()->firstOrFail();

        $assignment->forceFill(['custody_site_id' => $otherSite->id])->save();

        $this->actingAs($viewer)
            ->get('/fleet-assets/resident-tracking')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('residents', 0)
                ->where('stats.tracked', 0));
        $this->actingAs($viewer)
            ->get("/fleet-assets/resident-tracking/history/{$client->id}")
            ->assertForbidden();
        $this->actingAs($viewer)
            ->get("/security-devices/devices/{$device->id}")
            ->assertNotFound();
    }

    private function residentTracker(Client $client, User $actor): Device
    {
        $device = Device::factory()->tracking()->create();
        $consent = $this->consent($client, $actor, 'Personal Tracker (Wandering Risk)');
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $client->id,
            'assigned_at' => now()->subHour(),
            'assigned_by_user_id' => $actor->id,
            'consent_id' => $consent->id,
        ]);

        return $device;
    }

    private function consent(Client $client, User $actor, string $typeName): ClientConsent
    {
        $type = ConsentType::factory()->create([
            'name' => $typeName,
            'purpose' => "Purpose for {$typeName}",
            'active' => true,
        ]);

        return AuthoritativeConsentFixture::manualSelf($client, $type, $actor, [
            'status' => 'given',
            'given_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);
    }

    /** @param list<string> $permissions */
    private function siteViewer(Site $site, array $permissions): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $this->grant($user, $permissions);

        return $user;
    }

    private function globalAdmin(): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'admin']);
        $user->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        return $user;
    }

    /** @param list<string> $keys */
    private function grant(User $user, array $keys): void
    {
        $permissions = Permission::query()->whereIn('key', $keys)->get();
        $this->assertCount(count($keys), $permissions, 'A required permission was not seeded.');
        $user->permissionOverrides()->syncWithoutDetaching(
            $permissions->mapWithKeys(fn (Permission $permission): array => [
                $permission->id => ['allowed' => true],
            ]),
        );
    }
}
