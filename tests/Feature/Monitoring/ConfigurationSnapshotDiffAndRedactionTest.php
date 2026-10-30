<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Services\ConfigurationSnapshotService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Presenters\DeviceProfilePresenter;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Integration\Contracts\SnapshotCollectionCapability;
use App\Services\Integration\Data\ProviderSnapshotPage;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Database\Seeders\SecurityDevicesSignalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

final class RedactionTestSnapshotCapability implements SnapshotCollectionCapability
{
    public function collectSnapshots(
        IntegrationSiteConfig $siteConfig,
        IntegrationProviderConnection $providerConnection,
        ?string $cursor,
        int $limit,
    ): ProviderSnapshotPage {
        return new ProviderSnapshotPage([]);
    }
}

final class RedactionTestMemorySnapshotStore implements SnapshotStore
{
    /** @var array<string, string> */
    public array $objects = [];

    public function put(string $path, string $contents): void
    {
        $this->objects[$path] = $contents;
    }

    public function read(string $path): string
    {
        return $this->objects[$path] ?? throw new RuntimeException('Missing snapshot object: '.$path);
    }

    public function delete(string $path): void
    {
        unset($this->objects[$path]);
    }

    public function exists(string $path): bool
    {
        return isset($this->objects[$path]);
    }
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26T06:00:00Z'));
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $this->seed(SecurityDevicesSignalSeeder::class);
    Queue::fake();
    $this->snapshotStore = new RedactionTestMemorySnapshotStore;
    app()->instance(SnapshotStore::class, $this->snapshotStore);
});

afterEach(function (): void {
    $this->travelBack();
});

function createSnapshotRoleUser(Site $site, string $roleName): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);

    return $user;
}

it('redacts CLI and structured secrets before storage and emits a value-free config_changed event when configuration changes', function (): void {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $device = Device::factory()->itInfrastructure()->create([
        'name' => 'Coast Core Router',
        'category' => 'network',
        'meta' => [],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subDay(),
    ]);

    $service = app(ConfigurationSnapshotService::class);
    $capability = new RedactionTestSnapshotCapability;

    $v1 = $service->captureFromProvider(
        capability: $capability,
        device: $device,
        siteId: $site->id,
        provider: 'aruba',
        payload: [
            'hostname' => 'coast-rtr-01',
            'firmware_version' => '17.09.03',
            'vlans' => ['20' => ['name' => 'Staff_Legacy']],
            'running_config_lines' => [
                'hostname coast-rtr-01',
                'enable secret 5 $1$ab$RAW-ENABLE-SECRET-V1',
                'snmp-server community RAW-SNMP-COMMUNITY-V1 RO',
                'radius-server key RAW-RADIUS-SECRET-V1',
                'wpa-psk ascii 0 RAW-WIFI-PSK-V1',
                'vlan 20 name Staff_Legacy',
            ],
            'configuration' => [
                'vlans' => ['20' => ['name' => 'Staff_Legacy']],
                'wpa_psk' => 'RAW-JSON-PSK-V1',
                'private_key' => 'RAW-PRIVATE-KEY-V1',
            ],
        ],
        capturedAt: CarbonImmutable::now()->subHour(),
    );

    $storedV1 = $this->snapshotStore->read($v1->storage_path);
    foreach ([
        'RAW-ENABLE-SECRET-V1',
        'RAW-SNMP-COMMUNITY-V1',
        'RAW-RADIUS-SECRET-V1',
        'RAW-WIFI-PSK-V1',
        'RAW-JSON-PSK-V1',
        'RAW-PRIVATE-KEY-V1',
    ] as $sentinel) {
        expect($storedV1)->not->toContain($sentinel);
    }
    expect($storedV1)->toContain('[REDACTED]')
        ->and(DeviceEvent::query()->where('device_id', $device->id)->where('event_type', 'config_changed')->count())->toBe(0);

    $v2 = $service->captureFromProvider(
        capability: $capability,
        device: $device->fresh(),
        siteId: $site->id,
        provider: 'aruba',
        payload: [
            'hostname' => 'coast-rtr-01',
            'firmware_version' => '17.09.04a',
            'vlans' => ['20' => ['name' => 'Staff_Voice']],
            'running_config_lines' => [
                'hostname coast-rtr-01',
                'enable secret 5 $1$ab$RAW-ENABLE-SECRET-V2',
                'vlan 20 name Staff_Voice',
                'ntp server 10.20.0.10',
            ],
            'configuration' => [
                'vlans' => ['20' => ['name' => 'Staff_Voice']],
                'ntp' => ['server' => '10.20.0.10'],
            ],
        ],
        capturedAt: CarbonImmutable::now(),
    );

    $changeEvent = DeviceEvent::query()
        ->where('device_id', $device->id)
        ->where('event_type', 'config_changed')
        ->sole();

    $serializedPayload = json_encode($changeEvent->payload, JSON_THROW_ON_ERROR);
    expect($changeEvent->payload['snapshot_id'])->toBe($v2->id)
        ->and($changeEvent->payload['previous_snapshot_id'])->toBe($v1->id)
        ->and($serializedPayload)->not->toContain('Staff_Voice')
        ->and($serializedPayload)->not->toContain('RAW-ENABLE-SECRET-V2');
});

it('serves unified configuration diffs to authorised IT roles while denying frontline care roles and cross-Site users', function (): void {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);

    $device = Device::factory()->itInfrastructure()->create([
        'name' => 'Harbour Firewall',
        'category' => 'network',
        'meta' => [],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subDay(),
    ]);

    $service = app(ConfigurationSnapshotService::class);
    $capability = new RedactionTestSnapshotCapability;

    $v1 = $service->captureFromProvider(
        $capability,
        $device,
        $site->id,
        'fortinet',
        [
            'hostname' => 'harbour-fw-01',
            'running_config_lines' => [
                'hostname harbour-fw-01',
                'vlan 10 name Corporate_Old',
            ],
            'configuration' => [
                'vlans' => ['10' => ['name' => 'Corporate_Old']],
            ],
        ],
        CarbonImmutable::now()->subHour(),
    );

    $v2 = $service->captureFromProvider(
        $capability,
        $device->fresh(),
        $site->id,
        'fortinet',
        [
            'hostname' => 'harbour-fw-01',
            'running_config_lines' => [
                'hostname harbour-fw-01',
                'vlan 10 name Corporate_Main',
                'dns primary 10.10.0.53',
            ],
            'configuration' => [
                'vlans' => ['10' => ['name' => 'Corporate_Main']],
                'dns' => ['primary' => '10.10.0.53'],
            ],
        ],
        CarbonImmutable::now(),
    );

    $itManager = createSnapshotRoleUser($site, 'it_manager');
    $supportWorker = createSnapshotRoleUser($site, 'support_worker');
    $teamLead = createSnapshotRoleUser($site, 'team_lead');
    $otherSiteCoordinator = createSnapshotRoleUser($otherSite, 'coordinator');

    $diffResponse = $this->actingAs($itManager)
        ->getJson("/security-devices/devices/{$device->id}/configuration-snapshots/{$v2->id}/compare/{$v1->id}")
        ->assertOk()
        ->json();

    expect($diffResponse['identical'])->toBeFalse()
        ->and($diffResponse['line_source'])->toBe('running_config')
        ->and($diffResponse['summary']['added_lines'])->toBeGreaterThanOrEqual(2)
        ->and($diffResponse['summary']['removed_lines'])->toBeGreaterThanOrEqual(1)
        ->and(collect($diffResponse['field_changes'])->contains(fn (array $change): bool => str_contains($change['path'], 'vlans.10.name')))->toBeTrue();

    // Frontline support_worker and team_lead are denied snapshot downloads and diffs (403)
    foreach ([$supportWorker, $teamLead] as $frontlineActor) {
        $this->actingAs($frontlineActor)
            ->get("/security-devices/devices/{$device->id}/configuration-snapshots/{$v2->id}")
            ->assertForbidden();

        $this->actingAs($frontlineActor)
            ->getJson("/security-devices/devices/{$device->id}/configuration-snapshots/{$v2->id}/compare")
            ->assertForbidden();
    }

    // Presenter conceals download/compare links from team_lead while exposing them to it_manager
    $presenter = app(DeviceProfilePresenter::class);
    $loadedDevice = $device->fresh()->load(['assignments', 'configurationSnapshots']);
    $teamLeadProfile = $presenter->present($teamLead, $loadedDevice, null);
    $itManagerProfile = $presenter->present($itManager, $loadedDevice, null);

    expect($teamLeadProfile['configuration']['canViewSnapshots'])->toBeFalse()
        ->and($teamLeadProfile['configuration']['latestSnapshot']['downloadHref'])->toBeNull()
        ->and($teamLeadProfile['configuration']['latestSnapshot']['compareHref'])->toBeNull()
        ->and($itManagerProfile['configuration']['canViewSnapshots'])->toBeTrue()
        ->and($itManagerProfile['configuration']['latestSnapshot']['downloadHref'])->not->toBeNull()
        ->and($itManagerProfile['configuration']['latestSnapshot']['compareHref'])->not->toBeNull();

    // Cross-Site user without viewAllSites is concealed (404)
    $this->actingAs($otherSiteCoordinator)
        ->getJson("/security-devices/devices/{$device->id}/configuration-snapshots/{$v2->id}/compare")
        ->assertNotFound();
});
