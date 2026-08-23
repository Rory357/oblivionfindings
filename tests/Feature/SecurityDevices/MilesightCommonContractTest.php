<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Site;
use App\Services\Integration\Adapters\MilesightAdapter;
use App\Services\Integration\Contracts\DeviceSyncCapability;
use App\Services\Integration\Contracts\InventoryDiscoveryCapability;
use App\Services\Integration\IntegrationAdapterRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MilesightCommonContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('integration-capabilities.milesight.allowed_hosts', ['milesight.example.test']);
    }

    public function test_oauth_connection_and_bounded_paginated_application_discovery_use_common_contracts(): void
    {
        $connection = $this->connection();
        $requestedPages = [];

        Http::fake(function (Request $request) use (&$requestedPages) {
            if ($request->url() === 'https://milesight.example.test/oauth/token') {
                return Http::response(['data' => ['access_token' => 'access-token']], 200);
            }

            $this->assertSame('Bearer access-token', $request->header('Authorization')[0] ?? null);
            $pageNumber = (int) $request['pageNumber'];
            $requestedPages[] = $pageNumber;

            return Http::response([
                'data' => [
                    'pageSize' => 100,
                    'pageNumber' => $pageNumber,
                    'total' => 101,
                    'content' => $pageNumber === 1
                        ? [$this->inventoryDevice('device-1', 'application-b', 'South sensors')]
                        : [$this->inventoryDevice('device-2', 'application-a', 'North sensors')],
                ],
            ]);
        });

        $registry = app(IntegrationAdapterRegistry::class);
        $this->assertTrue($registry->hasCapability('milesight', InventoryDiscoveryCapability::class));
        $this->assertTrue($registry->hasCapability('milesight', DeviceSyncCapability::class));

        $adapter = app(MilesightAdapter::class);
        $this->assertTrue($adapter->testConnection($connection));
        $this->assertSame([
            [
                'external_id' => 'application-a',
                'name' => 'North sensors',
                'type' => 'application',
                'meta' => ['device_count' => 1],
            ],
            [
                'external_id' => 'application-b',
                'name' => 'South sensors',
                'type' => 'application',
                'meta' => ['device_count' => 1],
            ],
        ], $adapter->discoverSites($connection));
        $this->assertSame([1, 2], $requestedPages);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://milesight.example.test/oauth/token'
            && $request['client_id'] === 'client-123'
            && $request['client_secret'] === 'secret-9876'
            && $request['grant_type'] === 'client_credentials');
    }

    public function test_device_sync_writes_site_scoped_canonical_gateway_and_healthcare_sensor(): void
    {
        $site = Site::factory()->create(['name' => 'Care Site']);
        $siteConfig = $this->siteConfig($site, 'application-a');
        $connection = $this->connection();
        $lastUpdate = now()->subMinutes(3)->toIso8601String();
        $this->fakeInventory([
            array_merge($this->inventoryDevice('gateway-1', 'application-a', 'Care sensors'), [
                'name' => 'LoRaWAN gateway',
                'deviceType' => 'GATEWAY',
                'model' => 'UG67',
                'sn' => 'GW-001',
                'mac' => 'AA:BB:CC:DD:EE:01',
                'connectStatus' => 'ONLINE',
                'lastUpdateTime' => $lastUpdate,
            ]),
            array_merge($this->inventoryDevice('bed-1', 'application-a', 'Care sensors'), [
                'name' => 'Room 4 bed sensor',
                'deviceType' => 'SUB_DEVICE',
                'model' => 'Bed Occupancy Sensor',
                'devEUI' => 'EUI-001',
                'imei' => '123456789012345',
                'electricity' => 67,
                'connectStatus' => 'OFFLINE',
                'lastUpdateTime' => $lastUpdate,
            ]),
            $this->inventoryDevice('other-app-device', 'application-b', 'Other sensors'),
        ]);

        $result = app(MilesightAdapter::class)->syncDevices($siteConfig, $connection);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(2, $result->processed);
        $this->assertSame(2, $result->created);
        $this->assertSame(0, $result->updated);
        $this->assertSame(0, $result->errored);

        $gateway = Device::query()->where('external_ref->provider_entity_id', 'gateway-1')->firstOrFail();
        $sensor = Device::query()->where('external_ref->provider_entity_id', 'bed-1')->firstOrFail();
        $this->assertSame(['it_infrastructure', 'network', 'lte_gateway'], [
            $gateway->domain, $gateway->category, $gateway->subcategory,
        ]);
        $this->assertSame(DeviceStatus::Active, $gateway->status);
        $this->assertSame(HealthStatus::Healthy, $gateway->health_status);
        $this->assertSame(['iot_healthcare', 'bed_sensor', 'pressure_mat'], [
            $sensor->domain, $sensor->category, $sensor->subcategory,
        ]);
        $this->assertSame(DeviceStatus::Offline, $sensor->status);
        $this->assertSame(HealthStatus::Critical, $sensor->health_status);
        $this->assertSame(67, $sensor->battery_level);
        $this->assertSame('123456789012345', $sensor->imei);
        $this->assertSame('application-a', $sensor->external_ref['application_id']);
        $this->assertArrayNotHasKey('raw_payload', $sensor->meta ?? []);
        $this->assertSame(2, DeviceAssignment::query()
            ->where('assignable_type', DeviceAssignment::TARGET_SITE)
            ->where('assignable_id', $site->id)
            ->whereNull('released_at')
            ->count());
        $this->assertSame(1, MonitoringProfile::query()
            ->where('name', 'Milesight provider status')
            ->where('is_active', true)
            ->count());
        $this->assertSame(2, Monitor::query()
            ->whereIn('device_id', [$gateway->id, $sensor->id])
            ->where('kind', MonitorKind::Provider->value)
            ->where('current_state', MonitorState::Unknown->value)
            ->where('effective_state', MonitorState::Unknown->value)
            ->where('affects_availability', true)
            ->where('is_enabled', true)
            ->count());
        $this->assertSame(2, Monitor::query()
            ->whereIn('device_id', [$gateway->id, $sensor->id])
            ->where('target', 'like', 'provider:milesight:%')
            ->count());
        $this->assertFalse(Monitor::query()
            ->whereIn('device_id', [$gateway->id, $sensor->id])
            ->get()
            ->contains(fn (Monitor $monitor): bool => str_contains($monitor->target, 'gateway-1')
                || str_contains($monitor->target, 'bed-1')));
        $this->assertDatabaseMissing('devices', ['provider' => 'milesight', 'name' => 'other-app-device']);
    }

    public function test_device_sync_discards_inventory_when_the_canonical_site_is_retired_in_flight(): void
    {
        $site = Site::factory()->create(['name' => 'Retiring Care Site']);
        $siteConfig = $this->siteConfig($site, 'application-a');
        $connection = $this->connection();
        $existing = Device::factory()->iotHealthcare()->create([
            'name' => 'Existing healthcare sensor',
            'provider' => 'milesight',
            'status' => DeviceStatus::Active,
            'external_ref' => [
                'provider' => 'milesight',
                'provider_entity_id' => 'existing-milesight-device',
                'application_id' => 'application-a',
            ],
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $existing->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now()->subDay(),
        ]);

        Http::fake(function (Request $request) use ($site) {
            if ($request->url() === 'https://milesight.example.test/oauth/token') {
                return Http::response(['data' => ['access_token' => 'access-token']]);
            }

            if ($request->url() === 'https://milesight.example.test/device/openapi/v1/devices/search') {
                $site->update(['archived' => true, 'archived_at' => now()]);

                return Http::response(['data' => [
                    'pageSize' => 100,
                    'pageNumber' => 1,
                    'total' => 2,
                    'content' => [
                        array_merge($this->inventoryDevice(
                            'existing-milesight-device',
                            'application-a',
                            'Retired care sensors',
                        ), [
                            'name' => 'Provider attempted overwrite',
                            'connectStatus' => 'OFFLINE',
                        ]),
                        $this->inventoryDevice('new-milesight-device', 'application-a', 'Retired care sensors'),
                    ],
                ]]);
            }

            return Http::response([], 404);
        });

        $result = app(MilesightAdapter::class)->syncDevices($siteConfig, $connection);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('Existing healthcare sensor', $existing->refresh()->name);
        $this->assertSame(DeviceStatus::Active, $existing->status);
        $this->assertSame(1, Device::query()->where('provider', 'milesight')->count());
        $this->assertSame(1, DeviceAssignment::query()->where('device_id', $existing->id)->count());
        $this->assertDatabaseMissing('devices', [
            'provider' => 'milesight',
            'name' => 'new-milesight-device',
        ]);
    }

    public function test_sync_fails_closed_for_identity_owned_by_another_site(): void
    {
        $sourceSite = Site::factory()->create();
        $mappedSite = Site::factory()->create();
        $siteConfig = $this->siteConfig($mappedSite, 'application-a');
        $connection = $this->connection();
        $device = Device::factory()->iotHealthcare()->create([
            'provider' => 'milesight',
            'name' => 'Protected sensor',
            'external_ref' => ['provider_entity_id' => 'protected-1'],
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $sourceSite->id,
            'assigned_at' => now(),
        ]);
        $before = $device->fresh()->getAttributes();
        $this->fakeInventory([array_merge(
            $this->inventoryDevice('protected-1', 'application-a', 'Care sensors'),
            ['name' => 'Attempted relocation', 'connectStatus' => 'OFFLINE'],
        )]);

        $result = app(MilesightAdapter::class)->syncDevices($siteConfig, $connection);

        $this->assertTrue($result->isPartial());
        $this->assertSame(1, $result->processed);
        $this->assertSame(1, $result->errored);
        $this->assertSame(0, $result->created);
        $this->assertSame(0, $result->updated);
        $this->assertSame($before, $device->fresh()->getAttributes());
        $this->assertSame($sourceSite->id, $device->assignments()->active()->sole()->assignable_id);
        $this->assertSame(1, Device::query()->where('external_ref->provider_entity_id', 'protected-1')->count());
    }

    public function test_repeat_sync_updates_provider_state_without_erasing_existing_bounded_metadata(): void
    {
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site, 'application-a');
        $connection = $this->connection();
        $device = Device::factory()->iotHealthcare()->create([
            'provider' => 'milesight',
            'name' => 'Existing sensor',
            'model' => 'Existing model',
            'battery_level' => 51,
            'external_ref' => [
                'provider_entity_id' => 'existing-1',
                'migration_reference' => 'keep',
            ],
            'meta' => ['operator_label' => 'keep'],
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
        $this->fakeInventory([[
            'deviceId' => 'existing-1',
            'name' => 'Updated sensor',
            'deviceType' => 'SUB_DEVICE',
            'connectStatus' => 'ONLINE',
            'application' => [
                'applicationId' => 'application-a',
                'applicationName' => 'Care sensors',
            ],
        ]]);

        $result = app(MilesightAdapter::class)->syncDevices($siteConfig, $connection);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->updated);
        $device->refresh();
        $this->assertSame('Existing sensor', $device->name);
        $this->assertSame('Updated sensor', data_get($device->provider_observed_state, 'name.value'));
        $this->assertSame('Existing model', $device->model);
        $this->assertSame(51, $device->battery_level);
        $this->assertSame('keep', $device->external_ref['migration_reference']);
        $this->assertSame('keep', $device->meta['operator_label']);
        $this->assertSame(1, $device->assignments()->active()->count());
    }

    public function test_invalid_or_unbounded_provider_page_creates_no_devices_and_returns_safe_failure(): void
    {
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site, 'application-a');
        $connection = $this->connection();
        Http::fake([
            'https://milesight.example.test/oauth/token' => Http::response(['data' => ['access_token' => 'access-token']]),
            'https://milesight.example.test/device/openapi/v1/devices/search' => Http::response([
                'data' => [
                    'pageNumber' => 1,
                    'pageSize' => 100,
                    'total' => 10_001,
                    'content' => [],
                ],
            ]),
        ]);

        $result = app(MilesightAdapter::class)->syncDevices($siteConfig, $connection);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(0, $result->processed);
        $this->assertSame(0, Device::query()->where('provider', 'milesight')->count());
        $this->assertSame('Provider operation failed. Review the bounded diagnostic state and retry.', $result->error);
        $this->assertStringNotContainsString('access-token', $result->error ?? '');
    }

    #[DataProvider('unsafeBaseUrlProvider')]
    public function test_credentials_are_never_sent_to_an_unapproved_or_malformed_endpoint(string $baseUrl): void
    {
        Http::preventStrayRequests();
        $connection = $this->connection();
        $connection->forceFill(['config' => [
            'client_id' => 'client-123',
            'base_url' => $baseUrl,
        ]])->save();

        $this->assertFalse(app(MilesightAdapter::class)->testConnection($connection->refresh()));

        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function unsafeBaseUrlProvider(): array
    {
        return [
            'unapproved public host' => ['https://attacker.example.test'],
            'private address' => ['https://127.0.0.1'],
            'embedded credentials' => ['https://user:pass@milesight.example.test'],
            'non-default port' => ['https://milesight.example.test:8443'],
            'query credentials' => ['https://milesight.example.test?redirect=attacker'],
            'nested path' => ['https://milesight.example.test/proxy'],
        ];
    }

    #[DataProvider('redirectStatusProvider')]
    public function test_oauth_credentials_are_not_forwarded_to_provider_redirects(int $status): void
    {
        $connection = $this->connection();

        Http::fake(function (Request $request) use ($status) {
            if ($request->url() === 'https://milesight.example.test/oauth/token') {
                return Http::response([], $status, [
                    'Location' => 'https://attacker.example.test/collect',
                ]);
            }

            return Http::response(['data' => ['access_token' => 'redirected-token']]);
        });

        $this->assertFalse(app(MilesightAdapter::class)->testConnection($connection));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://milesight.example.test/oauth/token'
            && $request['client_secret'] === 'secret-9876');
        Http::assertNotSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://attacker.example.test/',
        ));
    }

    /** @return array<string, array{int}> */
    public static function redirectStatusProvider(): array
    {
        return [
            'temporary redirect' => [307],
            'permanent redirect' => [308],
        ];
    }

    private function connection(): IntegrationProviderConnection
    {
        return IntegrationProviderConnection::query()->create([
            'provider' => 'milesight',
            'secret_encrypted' => Crypt::encryptString('secret-9876'),
            'secret_last4' => '9876',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'config' => [
                'client_id' => 'client-123',
                'base_url' => 'https://milesight.example.test',
            ],
        ]);
    }

    private function siteConfig(Site $site, string $applicationId): IntegrationSiteConfig
    {
        return IntegrationSiteConfig::query()->create([
            'site_id' => $site->id,
            'provider' => 'milesight',
            'status' => IntegrationSiteConfig::STATUS_HYBRID,
            'mapped_external_site_id' => $applicationId,
            'mapped_external_site_name' => 'Care sensors',
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function inventoryDevice(string $deviceId, string $applicationId, string $applicationName): array
    {
        return [
            'deviceId' => $deviceId,
            'name' => $deviceId,
            'deviceType' => 'SUB_DEVICE',
            'model' => 'Temperature Sensor',
            'connectStatus' => 'ONLINE',
            'application' => [
                'applicationId' => $applicationId,
                'applicationName' => $applicationName,
            ],
        ];
    }

    /** @param array<int, array<string, mixed>> $devices */
    private function fakeInventory(array $devices): void
    {
        Http::fake([
            'https://milesight.example.test/oauth/token' => Http::response(['data' => ['access_token' => 'access-token']]),
            'https://milesight.example.test/device/openapi/v1/devices/search' => Http::response([
                'data' => [
                    'pageSize' => 100,
                    'pageNumber' => 1,
                    'total' => count($devices),
                    'content' => $devices,
                ],
            ]),
        ]);
    }
}
