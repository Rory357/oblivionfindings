<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\IcmpTransport;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\IcmpTransportResult;
use App\Domain\Monitoring\Discovery\Contracts\DiscoveryAdapter;
use App\Domain\Monitoring\Discovery\Data\DiscoveryProbeResult;
use App\Domain\Monitoring\Discovery\Data\DiscoveryTarget;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// S&D Phase B (plan v10): daily scans run on hubs by themselves once switched on, and ping and
// offline checks run on the hub that covers the Site, with the server only as the fallback.

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $this->admin = User::factory()->create(['approved_at' => now()]);
    $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    $this->house = sdbHubSite('Kauri House');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function sdbHubSite(string $name): Site
{
    return Site::factory()->create(['name' => $name, 'is_active' => true, 'archived' => false, 'archived_at' => null]);
}

function sdbHubDevice(Site $site, string $address): Device
{
    $device = Device::factory()->itInfrastructure()->create(['name' => 'Lounge switch', 'ip_address' => $address]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => AssignmentType::Permanent,
        'assigned_at' => now()->subHour(),
    ]);

    return $device;
}

/** A small, fixed scan plan so a run never depends on the real network. */
function sdbHubFakeScanPlan(): void
{
    app()->instance(DiscoveryAdapter::class, new class implements DiscoveryAdapter
    {
        public function begin(DiscoveryScope $scope): void {}

        public function targets(DiscoveryScope $scope): iterable
        {
            return [new DiscoveryTarget('192.168.20.1', 'cidr'), new DiscoveryTarget('192.168.20.2', 'cidr')];
        }

        public function discover(DiscoveryScope $scope, DiscoveryTarget $target): DiscoveryProbeResult
        {
            return DiscoveryProbeResult::unresolved('hub_runs_this');
        }
    });
}

function sdbHubHouseManager(Site $site): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    $role = Role::query()->create([
        'name' => 'sd_house_manager_'.str()->uuid(),
        'label' => 'House manager',
        'level' => 50,
        'type' => 'custom',
    ]);
    $keys = ['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.monitoring.manage'];
    foreach ($keys as $key) {
        Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'security_devices', 'module' => 'Security & Devices'],
        );
    }
    $role->permissions()->sync(Permission::query()->whereIn('key', $keys)->pluck('id'));
    $user->roles()->attach($role);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);

    return $user->fresh();
}

it('starts a daily hub scan by itself once its time has come, once a day, and only when switched on', function () {
    Queue::fake();
    sdbHubFakeScanPlan();
    // 1 am on 1 November in New Zealand (UTC+13).
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));
    $hub = MonitoringCollector::factory()->create(['site_id' => $this->house->id, 'name' => 'Kauri House hub']);
    $daily = DiscoveryScope::factory()->create([
        'site_id' => $this->house->id, 'collector_id' => $hub->id, 'cidrs' => ['192.168.20.0/24'], 'daily_scan_at' => '02:00',
    ]);
    $off = DiscoveryScope::factory()->create([
        'site_id' => $this->house->id, 'collector_id' => $hub->id, 'cidrs' => ['192.168.21.0/24'],
    ]);

    $this->artisan('monitoring:run-daily-discovery')->assertSuccessful();
    expect(DiscoveryRun::query()->count())->toBe(0);

    // 2:05 am: the scan starts, through the same runner path as "Run now".
    $this->travelTo(CarbonImmutable::parse('2026-10-31T13:05:00Z'));
    $this->artisan('monitoring:run-daily-discovery')->assertSuccessful();
    $run = DiscoveryRun::query()->sole();
    expect((int) $run->discovery_scope_id)->toBe($daily->id)
        ->and($run->trigger)->toBe('schedule:daily')
        ->and($run->status)->toBe('queued')
        ->and(AuditLog::query()->where('action', 'monitoring.discovery.scope.daily_scan_started')->count())->toBe(1);

    // Still going five minutes later: nothing starts on top of it.
    $this->travelTo(CarbonImmutable::parse('2026-10-31T13:10:00Z'));
    $this->artisan('monitoring:run-daily-discovery')->assertSuccessful();
    expect(DiscoveryRun::query()->count())->toBe(1);

    // Finished, and later the same day: still once a day.
    DiscoveryRun::query()->whereKey($run->id)->update(['status' => 'completed']);
    $this->travelTo(CarbonImmutable::parse('2026-10-31T20:00:00Z'));
    $this->artisan('monitoring:run-daily-discovery')->assertSuccessful();
    expect(DiscoveryRun::query()->count())->toBe(1);

    // The next night it runs again; the scan area that was never switched on never runs.
    $this->travelTo(CarbonImmutable::parse('2026-11-01T13:05:00Z'));
    $this->artisan('monitoring:run-daily-discovery')->assertSuccessful();
    expect(DiscoveryRun::query()->where('discovery_scope_id', $daily->id)->count())->toBe(2)
        ->and(DiscoveryRun::query()->where('discovery_scope_id', $off->id)->exists())->toBeFalse();
});

it('gives daily scans only to approved hubs, and a time set after tonight\'s slot starts the next night', function () {
    Queue::fake();
    sdbHubFakeScanPlan();
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));
    $waiting = MonitoringCollector::factory()->waitingForApproval()->create(['site_id' => $this->house->id]);
    $removed = MonitoringCollector::factory()->create(['site_id' => $this->house->id, 'revoked_at' => now()]);
    DiscoveryScope::factory()->create([
        'site_id' => $this->house->id, 'collector_id' => $waiting->id, 'cidrs' => ['192.168.20.0/24'], 'daily_scan_at' => '02:00',
    ]);
    DiscoveryScope::factory()->create([
        'site_id' => $this->house->id, 'collector_id' => $removed->id, 'cidrs' => ['192.168.21.0/24'], 'daily_scan_at' => '02:00',
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-10-31T13:05:00Z'));
    $this->artisan('monitoring:run-daily-discovery')->assertSuccessful();
    expect(DiscoveryRun::query()->count())->toBe(0);

    // Switched on at 9 am: no surprise daytime scan, the first one is at 2 am.
    $this->travelTo(CarbonImmutable::parse('2026-10-31T20:00:00Z'));
    $hub = MonitoringCollector::factory()->create(['site_id' => $this->house->id]);
    $late = DiscoveryScope::factory()->create([
        'site_id' => $this->house->id, 'collector_id' => $hub->id, 'cidrs' => ['192.168.22.0/24'], 'daily_scan_at' => '02:00',
    ]);
    $this->artisan('monitoring:run-daily-discovery')->assertSuccessful();
    expect(DiscoveryRun::query()->count())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-11-01T13:05:00Z'));
    $this->artisan('monitoring:run-daily-discovery')->assertSuccessful();
    expect(DiscoveryRun::query()->count())->toBe(1)
        ->and(DiscoveryRun::query()->where('discovery_scope_id', $late->id)->count())->toBe(1);
});

it('switches a daily scan on and off through the scan area edit, for hub scan areas only', function () {
    $hub = MonitoringCollector::factory()->create(['site_id' => $this->house->id]);
    $hubScope = DiscoveryScope::factory()->create(['site_id' => $this->house->id, 'collector_id' => $hub->id, 'cidrs' => ['192.168.20.0/24']]);
    $serverScope = DiscoveryScope::factory()->create(['site_id' => $this->house->id, 'cidrs' => ['10.44.0.0/16']]);
    $update = fn (DiscoveryScope $scope): string => route('security-devices.discovery.scopes.update', ['scope' => $scope->id]);
    expect($hubScope->fresh()->daily_scan_at)->toBeNull();

    $this->actingAs($this->admin)->patchJson($update($hubScope), ['daily_scan_at' => '02:30'])
        ->assertOk()->assertJsonPath('scope.daily_scan_at', '02:30');
    expect($hubScope->fresh()->daily_scan_at)->toBe('02:30');

    // Leaving the field out keeps the time; null switches it off.
    $this->actingAs($this->admin)->patchJson($update($hubScope), ['name' => 'Kauri House LAN'])
        ->assertOk()->assertJsonPath('scope.daily_scan_at', '02:30');
    $this->actingAs($this->admin)->patchJson($update($hubScope), ['daily_scan_at' => null])
        ->assertOk()->assertJsonPath('scope.daily_scan_at', null);

    $this->actingAs($this->admin)->patchJson($update($hubScope), ['daily_scan_at' => '25:00'])
        ->assertUnprocessable()->assertJsonValidationErrors('daily_scan_at');
    $this->actingAs($this->admin)->patchJson($update($serverScope), ['daily_scan_at' => '02:00'])
        ->assertUnprocessable()->assertJsonValidationErrors(['daily_scan_at' => 'Daily scans run on a hub']);
    expect($serverScope->fresh()->daily_scan_at)->toBeNull();
});

it('puts the offline check on the hub that covers the Site, preferring its home hub, and says so', function () {
    $office = sdbHubSite('Head office');
    // Lower id, but it only covers the house over the office link.
    $officeHub = MonitoringCollector::factory()->create([
        'site_id' => $office->id, 'name' => 'Head office', 'config' => ['covered_site_ids' => [$this->house->id]],
    ]);
    $houseHub = MonitoringCollector::factory()->create(['site_id' => $this->house->id, 'name' => 'Kauri House hub']);
    $device = sdbHubDevice($this->house, '192.168.20.15');
    $toggle = "/security-devices/devices/{$device->id}/offline-alert";

    $this->actingAs($this->admin)->postJson($toggle, ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('checked_by.mode', 'hub')
        ->assertJsonPath('checked_by.hub_id', $houseHub->id)
        ->assertJsonPath('message', 'Offline alerts turned on for this device. Checked by Kauri House hub.');

    $monitor = Monitor::query()->where('device_id', $device->id)->where('kind', MonitorKind::Icmp->value)->sole();
    expect((int) $monitor->collector_id)->toBe($houseHub->id)
        ->and((bool) $monitor->affects_availability)->toBeTrue()
        ->and((bool) $monitor->is_enabled)->toBeTrue()
        ->and(DiscoveryScope::query()->where('collector_id', $houseHub->id)->sole()->cidrs)->toBe(['192.168.20.15/32'])
        ->and(DiscoveryScope::query()->whereNull('collector_id')->exists())->toBeFalse();

    $this->actingAs($this->admin)->get("/security-devices/devices/{$device->id}?section=monitors")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.offlineAlert.enabled', true)
            ->where('profile.offlineAlert.monitorId', $monitor->id));

    // The house hub is removed: the head office hub takes the check over.
    $houseHub->forceFill(['revoked_at' => now(), 'status' => 'revoked'])->save();
    $this->actingAs($this->admin)->postJson($toggle, ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('checked_by.hub_id', $officeHub->id)
        ->assertJsonPath('message', 'Offline alerts turned on for this device. Checked by Head office hub.');
    expect((int) $monitor->fresh()->collector_id)->toBe($officeHub->id);

    $this->actingAs($this->admin)->postJson($toggle, ['enabled' => false])
        ->assertOk()->assertJsonPath('enabled', false);
    expect((bool) $monitor->fresh()->is_enabled)->toBeFalse();
});

it('checks from the server only when no approved hub covers the Site, then moves to the hub once approved', function () {
    $waiting = MonitoringCollector::factory()->waitingForApproval()->create(['site_id' => $this->house->id, 'name' => 'Kauri House hub']);
    MonitoringCollector::factory()->create(['site_id' => $this->house->id, 'revoked_at' => now(), 'status' => 'revoked']);
    $device = sdbHubDevice($this->house, '172.16.0.46');
    $toggle = "/security-devices/devices/{$device->id}/offline-alert";

    $this->actingAs($this->admin)->postJson($toggle, ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('checked_by.mode', 'server')
        ->assertJsonPath('checked_by.hub_id', null)
        ->assertJsonPath('message', 'Offline alerts turned on for this device. Checked from the server.');
    $monitor = Monitor::query()->where('device_id', $device->id)->sole();
    expect($monitor->collector_id)->toBeNull();

    $waiting->forceFill(['approved_at' => now(), 'status' => 'online'])->save();
    $this->actingAs($this->admin)->postJson($toggle, ['enabled' => true])
        ->assertOk()->assertJsonPath('checked_by.hub_id', $waiting->id);
    expect(Monitor::query()->where('device_id', $device->id)->count())->toBe(1)
        ->and((int) $monitor->fresh()->collector_id)->toBe($waiting->id);
});

it('needs an IP address for a hub check, and access to the hub\'s own Site', function () {
    $office = sdbHubSite('Head office');
    MonitoringCollector::factory()->create([
        'site_id' => $office->id, 'name' => 'Head office', 'config' => ['covered_site_ids' => [$this->house->id]],
    ]);

    $named = sdbHubDevice($this->house, 'nvr.kauri.local');
    $this->actingAs($this->admin)->postJson("/security-devices/devices/{$named->id}/offline-alert", ['enabled' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['offline_alert' => 'not a hostname, so Head office hub can check it']);

    $device = sdbHubDevice($this->house, '192.168.20.16');
    $this->actingAs(sdbHubHouseManager($this->house))->postJson("/security-devices/devices/{$device->id}/offline-alert", ['enabled' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['offline_alert' => 'Head office hub checks this Site']);

    expect(Monitor::query()->exists())->toBeFalse()
        ->and(DiscoveryScope::query()->exists())->toBeFalse();
});

it('says a one-off ping ran from the server, never from the hub, when a hub covers the Site', function () {
    app()->instance(IcmpTransport::class, new class implements IcmpTransport
    {
        public function probe(AuthorizedProbeTarget $target): IcmpTransportResult
        {
            return new IcmpTransportResult(false, null, 100.0, 'timeout');
        }
    });
    $device = sdbHubDevice($this->house, '172.16.0.47');
    $timeout = 'No reply before the ping timed out. Check that the device is powered on, connected to the network, and reachable from this server.';

    $this->actingAs($this->admin)->postJson("/security-devices/devices/{$device->id}/ping")
        ->assertOk()
        ->assertJsonPath('checked_from', 'server')
        ->assertJsonPath('covering_hub', null)
        ->assertJsonPath('message', $timeout);

    MonitoringCollector::factory()->create(['site_id' => $this->house->id, 'name' => 'Kauri House hub']);
    $this->actingAs($this->admin)->postJson("/security-devices/devices/{$device->id}/ping")
        ->assertOk()
        ->assertJsonPath('checked_from', 'server')
        ->assertJsonPath('covering_hub', 'Kauri House hub')
        ->assertJsonPath('message', $timeout.' Checked from the server, not from Kauri House hub.');
});
