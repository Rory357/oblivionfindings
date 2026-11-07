<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Models\DiscoveryResult;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Domain\SecurityDevices\Presenters\MonitoringOperationsPresenter;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use RuntimeException;
use Tests\TestCase;

if (getenv('MONITORING_USE_PREBUILT_TEST_DATABASE') === '1') {
    $databasePath = getenv('DB_DATABASE');
    if (getenv('APP_ENV') !== 'testing'
        || getenv('DB_CONNECTION') !== 'sqlite'
        || ! is_string($databasePath)
        || $databasePath === ''
        || $databasePath === ':memory:'
        || ! is_file($databasePath)) {
        throw new RuntimeException(
            'MONITORING_USE_PREBUILT_TEST_DATABASE requires APP_ENV=testing, DB_CONNECTION=sqlite, and an existing file-backed database.',
        );
    }

    RefreshDatabaseState::$migrated = true;
}

class OperationsWorkspacesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create([

            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    }

    public function test_central_site_readiness_omits_inaccessible_sites_devices_and_counts(): void
    {
        [$viewer, $allowedSite, $hiddenSite] = $this->siteScopedViewer([
            'securityDevices.events.view',
        ]);
        $profile = MonitoringProfile::factory()->create(['stale_after_seconds' => 300]);
        foreach ([
            [$allowedSite, 'Allowed central gateway'],
            [$hiddenSite, 'Hidden central gateway sentinel'],
        ] as [$site, $name]) {
            $device = Device::factory()->itInfrastructure()->create(['name' => $name]);
            $this->assignToSite($device, $site);
            Monitor::factory()->create([
                'device_id' => $device->id,
                'profile_id' => $profile->id,
                'name' => $name.' direct check',
                'current_state' => MonitorState::Healthy,
                'effective_state' => MonitorState::Healthy,
                'last_observation_at' => now()->subMinute(),
            ]);
        }

        $workspace = app(MonitoringOperationsPresenter::class)->present($viewer, ['tab' => 'collection']);
        $encoded = json_encode($workspace, JSON_THROW_ON_ERROR);

        $this->assertSame(
            [$allowedSite->id],
            collect($workspace['collection']['direct_sites'])->pluck('site.id')->all(),
        );
        $this->assertSame(['Allowed central gateway'], collect($workspace['monitors'])->pluck('device.name')->all());
        $this->assertStringNotContainsString('Hidden central gateway sentinel', $encoded);
        $this->assertStringNotContainsString($hiddenSite->name, $encoded);
    }

    public function test_monitoring_resolves_client_staff_and_vehicle_devices_into_canonical_site_readiness(): void
    {
        $site = Site::factory()->create(['name' => 'Integrated Care Site']);
        $client = Client::withoutEvents(fn () => Client::forceCreate([
            'first_name' => 'Integrated',
            'last_name' => 'Client',
            'site_id' => $site->id,
            'status' => 'active',
        ]));
        $staff = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $staff->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $vehicle = Asset::factory()->vehicle()->create([
            'site_id' => $site->id,
            'home_site_id' => $site->id,
            'status' => 'active',
        ]);
        $devices = collect([
            [
                Device::factory()->create([
                    'name' => 'Client healthcare sensor',
                    'domain' => 'iot_healthcare',
                    'category' => 'medical_device',
                ]),
                DeviceAssignment::TARGET_CLIENT,
                $client->id,
            ],
            [
                Device::factory()->itInfrastructure()->create(['name' => 'Staff field laptop']),
                DeviceAssignment::TARGET_STAFF,
                $staff->id,
            ],
            [
                Device::factory()->itInfrastructure()->create(['name' => 'Fleet vehicle gateway']),
                DeviceAssignment::TARGET_VEHICLE,
                $vehicle->id,
            ],
        ]);
        $profile = MonitoringProfile::factory()->create(['stale_after_seconds' => 300]);
        foreach ($devices as [$device, $targetType, $targetId]) {
            DeviceAssignment::create([
                'device_id' => $device->id,
                'assignable_type' => $targetType,
                'assignable_id' => $targetId,
                'assigned_at' => now()->subHour(),
            ]);
            Monitor::factory()->create([
                'device_id' => $device->id,
                'profile_id' => $profile->id,
                'name' => $device->name.' direct check',
                'current_state' => MonitorState::Healthy,
                'effective_state' => MonitorState::Healthy,
                'last_observation_at' => now()->subMinute(),
            ]);
        }

        $workspace = app(MonitoringOperationsPresenter::class)->present($this->admin, ['tab' => 'collection']);
        $siteReadiness = collect($workspace['collection']['direct_sites'])->firstWhere('site.id', $site->id);

        $this->assertNotNull($siteReadiness);
        $this->assertSame(3, $siteReadiness['devices']);
        $this->assertSame(3, $siteReadiness['direct_devices']);
        $this->assertSame(3, $siteReadiness['direct_monitors']);
        $this->assertSame(
            [$site->id],
            collect($workspace['monitors'])->pluck('site.id')->unique()->values()->all(),
        );
    }

    public function test_maintenance_mutations_enforce_device_visibility_and_accept_configuration_work(): void
    {
        [$viewer, $allowedSite, $hiddenSite] = $this->siteScopedViewer([
            'securityDevices.maintenance.view',
            'securityDevices.maintenance.manage',
        ]);
        $allowedDevice = Device::factory()->create([]);
        $hiddenDevice = Device::factory()->create([]);
        $this->assignToSite($allowedDevice, $allowedSite);
        $this->assignToSite($hiddenDevice, $hiddenSite);

        $payload = [
            'type' => 'configuration_change',
            'description' => 'Apply approved configuration baseline',
            'scheduled_for' => now()->addDay()->toDateString(),
        ];

        $this->actingAs($viewer)
            ->post("/security-devices/devices/{$allowedDevice->id}/maintenance", $payload)
            ->assertRedirect();
        $this->assertDatabaseHas('device_maintenance_records', [
            'device_id' => $allowedDevice->id,
            'type' => 'configuration_change',
        ]);

        $this->actingAs($viewer)
            ->post("/security-devices/devices/{$hiddenDevice->id}/maintenance", $payload)
            ->assertNotFound();
    }

    private function assignToSite(Device $device, Site $site): void
    {
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
    }

    /** @param list<string> $additionalPermissions */
    private function siteScopedViewer(array $additionalPermissions): array
    {
        $allowedSite = Site::factory()->create(['name' => 'Allowed Site']);
        $hiddenSite = Site::factory()->create(['name' => 'Hidden Site']);
        $viewer = User::factory()->create([

            'approved_at' => now(),
        ]);
        $viewer->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        // Support workers hold only the care-alert key in S&D now (Stephan, 7 Nov 2026). This viewer gets
        // the module's two view keys directly, as the role gave them before.
        $viewer->permissionOverrides()->syncWithoutDetaching(Permission::query()
            ->whereIn('key', ['securityDevices.viewAny', 'securityDevices.devices.view'])
            ->pluck('id')->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all());
        $permissionIds = Permission::query()
            ->whereIn('key', $additionalPermissions)
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
            ->all();
        $viewer->permissionOverrides()->syncWithoutDetaching($permissionIds);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $allowedSite->id,
            'secondary_site_ids' => [],
        ]);

        return [$viewer, $allowedSite, $hiddenSite];
    }
}
