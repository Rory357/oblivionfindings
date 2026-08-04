<?php

use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Data\ProtocolObservation;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Exceptions\SnapshotStoreUnavailable;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Services\ConfigurationSnapshotService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Infrastructure\Monitoring\LaravelSnapshotStore;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-23T12:00:00Z');
    $this->store = new ConfigurationSnapshotFakeStore;
    app()->instance(SnapshotStore::class, $this->store);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('captures only allowlisted provider evidence and projects safe firmware and hashes to the Device', function (): void {
    [$site, $device] = configurationSnapshotDevice();
    $service = app(ConfigurationSnapshotService::class);
    $capability = new ConfigurationSnapshotFixtureCapability;

    $snapshot = $service->captureFromProvider(
        capability: $capability,
        device: $device,
        siteId: $site->id,
        provider: 'fixture',
        payload: [
            'firmware_version' => '8.1.2',
            'hostname' => 'kauri-gateway',
            'configuration' => [
                'interfaces' => ['wan' => ['enabled' => true, 'mtu' => 1500]],
                'api_token' => 'RAW-PROVIDER-SECRET-SENTINEL',
            ],
            'authorization' => 'RAW-AUTHORIZATION-SENTINEL',
            'unsupported_blob' => 'RAW-UNSUPPORTED-SENTINEL',
        ],
        capturedAt: CarbonImmutable::now(),
    );

    $payload = $this->store->objects[$snapshot->storage_path];
    $device->refresh();

    expect($snapshot->site_id)->toBe($site->id)
        ->and($snapshot->device_id)->toBe($device->id)
        ->and($snapshot->source_kind)->toBe('provider')
        ->and($snapshot->source)->toBe('fixture')
        ->and($snapshot->mime_type)->toBe('application/json')
        ->and($snapshot->content_hash)->toBe(hash('sha256', $payload))
        ->and($snapshot->content_size)->toBe(strlen($payload))
        ->and($payload)->toContain('kauri-gateway')
        ->and($payload)->not->toContain('RAW-PROVIDER-SECRET-SENTINEL')
        ->and($payload)->not->toContain('RAW-AUTHORIZATION-SENTINEL')
        ->and($payload)->not->toContain('RAW-UNSUPPORTED-SENTINEL')
        ->and($device->firmware_version)->toBe('8.1.2')
        ->and(data_get($device->meta, 'observed.configuration_hash'))->toBe($snapshot->configuration_hash)
        ->and($snapshot->toArray())->not->toHaveKey('payload');
});

it('stores a bounded structural diff without configuration values', function (): void {
    [$site, $device] = configurationSnapshotDevice();
    $service = app(ConfigurationSnapshotService::class);
    $capability = new ConfigurationSnapshotFixtureCapability;

    $service->captureFromProvider(
        $capability,
        $device,
        $site->id,
        'fixture',
        ['configuration' => ['interfaces' => ['wan' => ['mtu' => 1500]], 'services' => ['ssh' => false]]],
        CarbonImmutable::now()->subHour(),
    );
    $latest = $service->captureFromProvider(
        $capability,
        $device->fresh(),
        $site->id,
        'fixture',
        ['configuration' => ['interfaces' => ['wan' => ['mtu' => 1400]], 'services' => ['https' => true]]],
        CarbonImmutable::now(),
    );

    $encoded = json_encode($latest->diff_summary, JSON_THROW_ON_ERROR);
    expect($latest->previous_snapshot_id)->not->toBeNull()
        ->and($latest->diff_summary['changed'])->toContain('configuration.interfaces.wan.mtu')
        ->and($latest->diff_summary['added'])->toContain('configuration.services.https')
        ->and($latest->diff_summary['removed'])->toContain('configuration.services.ssh')
        ->and($encoded)->not->toContain('1500')
        ->and($encoded)->not->toContain('1400')
        ->and(count($latest->diff_summary['added']) + count($latest->diff_summary['removed']) + count($latest->diff_summary['changed']))
        ->toBeLessThanOrEqual(200);
});

it('accepts approved read-only SSH and WinRM inventory results and rejects other observations', function (): void {
    [$site, $device] = configurationSnapshotDevice();
    $service = app(ConfigurationSnapshotService::class);
    $approved = new ProtocolObservation(
        state: MonitorState::Healthy,
        observedAt: CarbonImmutable::now(),
        value: 3,
        unit: 'facts',
        latencyMs: 20,
        reasonCode: 'ssh_inventory_ok',
        evidence: [
            'firmware_version' => '2.0.1',
            'hostname' => 'edge-01',
            'os_version' => 'NetworkOS 2',
        ],
    );

    $snapshot = $service->captureFromInventory($device, $site->id, $approved);
    expect($snapshot->source_kind)->toBe('ssh')
        ->and($snapshot->source)->toBe('native_read_only_inventory');

    $unapproved = new ProtocolObservation(
        MonitorState::Healthy,
        CarbonImmutable::now(),
        1,
        'facts',
        1,
        'http_ok',
        ['hostname' => 'edge-01'],
    );
    expect(fn () => $service->captureFromInventory($device, $site->id, $unapproved))
        ->toThrow(InvalidArgumentException::class, 'read-only inventory');
});

it('fails closed when the object store is unavailable and leaves no metadata row', function (): void {
    [$site, $device] = configurationSnapshotDevice();
    $this->store->failWrites = true;

    expect(fn () => app(ConfigurationSnapshotService::class)->captureFromProvider(
        new ConfigurationSnapshotFixtureCapability,
        $device,
        $site->id,
        'fixture',
        ['configuration' => ['hostname' => 'should-not-persist']],
        CarbonImmutable::now(),
    ))->toThrow(SnapshotStoreUnavailable::class);

    expect(ConfigurationSnapshot::query()->count())->toBe(0)
        ->and(data_get($device->fresh()->meta, 'observed.configuration_hash'))->toBeNull();
});

it('encrypts payloads on the configured private disk and round trips through the store', function (): void {
    config()->set('monitoring.storage.snapshots.disk', 'monitoring-snapshots');
    config()->set('filesystems.disks.monitoring-snapshots', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/disks/monitoring-snapshots'),
        'serve' => false,
        'throw' => true,
    ]);
    Storage::fake('monitoring-snapshots');
    $store = new LaravelSnapshotStore;
    $plain = '{"configuration":{"hostname":"private-device"}}';

    $store->put('monitoring/configuration-snapshots/example.json.enc', $plain);
    $stored = Storage::disk('monitoring-snapshots')->get('monitoring/configuration-snapshots/example.json.enc');

    expect($stored)->not->toBe($plain)
        ->and($stored)->not->toContain('private-device')
        ->and($store->read('monitoring/configuration-snapshots/example.json.enc'))->toBe($plain);
});

it('requires Device permission and canonical Site visibility before returning a snapshot', function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    [$site, $device] = configurationSnapshotDevice();
    $service = app(ConfigurationSnapshotService::class);
    $snapshot = $service->captureFromProvider(
        new ConfigurationSnapshotFixtureCapability,
        $device,
        $site->id,
        'fixture',
        ['configuration' => ['hostname' => 'permission-gated']],
        CarbonImmutable::now(),
    );
    $admin = User::factory()->create(['approved_at' => now()]);
    $admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    $denied = User::factory()->create(['approved_at' => now()]);

    expect($service->retrieve($snapshot, $admin))->toContain('permission-gated');
    expect(fn () => $service->retrieve($snapshot, $denied))->toThrow(HttpException::class);

    $this->actingAs($admin)
        ->get("/security-devices/devices/{$device->id}/configuration-snapshots/{$snapshot->id}")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertSee('permission-gated', false);
    $otherDevice = Device::factory()->itInfrastructure()->create();
    $this->actingAs($admin)
        ->get("/security-devices/devices/{$otherDevice->id}/configuration-snapshots/{$snapshot->id}")
        ->assertNotFound();
});

it('keeps raw snapshot payloads out of logs and the Network and IT workspace props', function (): void {
    $logHandler = new TestHandler;
    Log::swap(new IlluminateLogger(new MonologLogger('snapshot-test', [$logHandler])));
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    [$site, $device] = configurationSnapshotDevice();
    app(ConfigurationSnapshotService::class)->captureFromProvider(
        new ConfigurationSnapshotFixtureCapability,
        $device,
        $site->id,
        'fixture',
        ['configuration' => ['hostname' => 'RAW-SNAPSHOT-PAYLOAD-SENTINEL']],
        CarbonImmutable::now(),
    );
    $admin = User::factory()->create(['approved_at' => now()]);
    $admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

    $this->actingAs($admin)
        ->get('/security-devices/network-it?tab=configuration-firmware')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'networkItWorkspace.activeTab.configuration.0.deviceId',
            $device->id,
        )->etc());

    $response = $this->actingAs($admin)->get('/security-devices/network-it?tab=configuration-firmware');
    expect($response->getContent())->not->toContain('RAW-SNAPSHOT-PAYLOAD-SENTINEL');
    expect(json_encode($logHandler->getRecords(), JSON_THROW_ON_ERROR))
        ->not->toContain('RAW-SNAPSHOT-PAYLOAD-SENTINEL');
});

/** @return array{Site, Device} */
function configurationSnapshotDevice(): array
{
    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $device = Device::factory()->itInfrastructure()->create(['meta' => []]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subDay(),
    ]);

    return [$site, $device];
}

final class ConfigurationSnapshotFixtureCapability implements SnapshotCollectionCapability
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

final class ConfigurationSnapshotFakeStore implements SnapshotStore
{
    /** @var array<string, string> */
    public array $objects = [];

    public bool $failWrites = false;

    public function put(string $path, string $contents): void
    {
        if ($this->failWrites) {
            throw new SnapshotStoreUnavailable('Snapshot storage is unavailable.');
        }

        $this->objects[$path] = $contents;
    }

    public function read(string $path): string
    {
        if (! isset($this->objects[$path])) {
            throw new SnapshotStoreUnavailable('Snapshot storage is unavailable.');
        }

        return $this->objects[$path];
    }

    public function delete(string $path): void
    {
        if ($this->failWrites) {
            throw new SnapshotStoreUnavailable('Snapshot storage is unavailable.');
        }

        unset($this->objects[$path]);
    }

    public function exists(string $path): bool
    {
        return isset($this->objects[$path]);
    }
}
