<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Events\FleetSignalEmitted;
use App\Events\FleetVehiclePositionUpdated;
use App\Events\FleetWanderingAlertTriggered;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\FleetSignal;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\FleetRealtimeAuthorizationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetRealtimePrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_broadcast_auth_route_and_record_channels_fail_closed(): void
    {
        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-fleet.assets.1.positions',
            'socket_id' => '1.1',
        ])->assertUnauthorized();

        $localSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $user = $this->makeSiteUser($localSite, [
            'fleet.viewAny',
            'assets.telemetry.view',
            'clients.viewAssigned',
        ]);
        $localVehicle = Asset::factory()->vehicle()->create([
            'site_id' => $localSite->id,
            'home_site_id' => $localSite->id,
            'status' => 'active',
        ]);
        $otherVehicle = Asset::factory()->vehicle()->create([
            'site_id' => $otherSite->id,
            'home_site_id' => $otherSite->id,
            'status' => 'active',
        ]);
        $client = Client::factory()->create([
            'site_id' => $localSite->id,
            'status' => 'active',
        ]);
        $client->supportWorkers()->attach($user->id);
        $consent = $this->createTrackingConsent($client);
        $device = Device::factory()->tracking()->create();
        $assignment = $this->assignDeviceToClient($device, $client, $consent);

        $authorizer = app(FleetRealtimeAuthorizationService::class);

        $this->assertTrue($authorizer->canViewAssetPosition($user, $localVehicle->id));
        $this->assertFalse($authorizer->canViewAssetPosition($user, $otherVehicle->id));
        $this->assertTrue($authorizer->canViewClientAlert($user, $client->id));

        $assignment->update(['collection_stopped_at' => now()]);

        $this->assertFalse($authorizer->canViewClientAlert($user, $client->id));
    }

    public function test_wandering_alert_provenance_requires_exact_active_consent_device_asset_and_site(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);
        $consent = $this->createTrackingConsent($client);
        $asset = Asset::factory()->create([
            'site_id' => $site->id,
            'home_site_id' => $site->id,
            'client_id' => $client->id,
            'category' => 'Personal Tracker',
            'status' => 'active',
        ]);
        $device = Device::factory()->tracking()->create();
        $this->assignDeviceToClient($device, $client, $consent);
        $link = DeviceAssetLink::query()->create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'link_type' => 'installed_in',
            'linked_at' => now(),
        ]);
        $signal = FleetSignal::query()->create([
            'asset_id' => $asset->id,
            'device_id' => $device->id,
            'signal_type' => 'geofence.breach',
            'severity_hint' => 'high',
            'occurred_at' => now(),
            'idempotency_key' => 'realtime-provenance-exact',
            'payload' => ['latitude' => -36.8485, 'longitude' => 174.7633],
        ]);

        $authorizer = app(FleetRealtimeAuthorizationService::class);

        $this->assertTrue($authorizer->consentedClientForSignal($signal)?->is($client));

        $otherDevice = Device::factory()->tracking()->create();
        $signal->forceFill(['device_id' => $otherDevice->id]);
        $this->assertNull($authorizer->consentedClientForSignal($signal));

        $signal->forceFill(['device_id' => $device->id]);
        $otherAsset = Asset::factory()->create([
            'site_id' => $otherSite->id,
            'home_site_id' => $otherSite->id,
            'status' => 'active',
        ]);
        $otherLink = DeviceAssetLink::query()->create([
            'device_id' => $device->id,
            'asset_id' => $otherAsset->id,
            'link_type' => 'installed_in',
            'linked_at' => now(),
        ]);
        $this->assertNull($authorizer->consentedClientForSignal($signal));

        $otherLink->update(['unlinked_at' => now()]);
        $this->assertTrue($authorizer->consentedClientForSignal($signal)?->is($client));

        $link->update(['unlinked_at' => now()]);
        $this->assertNull($authorizer->consentedClientForSignal($signal));

        $link->update(['unlinked_at' => null]);
        $consent->update([
            'status' => 'withdrawn',
            'withdrawn_at' => now(),
        ]);
        $this->assertNull($authorizer->consentedClientForSignal($signal));
    }

    public function test_fleet_realtime_events_use_private_record_channels_and_minimal_payloads(): void
    {
        $signalEvent = new FleetSignalEmitted(new FleetSignal([
            'payload' => ['private' => 'raw-signal-data'],
        ]));
        $this->assertNotInstanceOf(ShouldBroadcast::class, $signalEvent);

        $position = new FleetVehiclePositionUpdated(
            assetId: 42,
            latitude: -36.8485,
            longitude: 174.7633,
            speed_kph: 30.5,
            heading_deg: 90,
            status: 'online',
            motion_status: 'moving',
        );
        $this->assertInstanceOf(PrivateChannel::class, $position->broadcastOn());
        $this->assertSame('private-fleet.assets.42.positions', $position->broadcastOn()->name);
        $this->assertSame([
            'asset_id' => 42,
            'latitude' => -36.8485,
            'longitude' => 174.7633,
            'speed_kph' => 30.5,
            'heading_deg' => 90,
            'status' => 'online',
        ], $position->broadcastWith());
        $this->assertArrayNotHasKey('motion_status', $position->broadcastWith());

        $alert = new FleetWanderingAlertTriggered(clientId: 73, severity: 'high');
        $this->assertInstanceOf(PrivateChannel::class, $alert->broadcastOn());
        $this->assertSame('private-fleet.clients.73.wandering-alerts', $alert->broadcastOn()->name);
        $this->assertSame(['severity' => 'high'], $alert->broadcastWith());
    }

    private function assignDeviceToClient(
        Device $device,
        Client $client,
        ClientConsent $consent,
    ): DeviceAssignment {
        return DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $client->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
            'consent_id' => $consent->id,
        ]);
    }

    private function createTrackingConsent(Client $client): ClientConsent
    {
        $type = ConsentType::query()->firstOrCreate(
            ['name' => 'Fleet Tracking'],
            [
                'category' => 'operational',
                'description' => 'Fleet location tracking',
                'purpose' => 'Resident tracker safety',
                'legal_basis' => 'consent',
                'allows_withdrawal' => true,
                'active' => true,
            ],
        );
        $version = ConsentTypeVersion::query()->firstOrCreate(
            ['consent_type_id' => $type->id, 'version' => 1],
            [
                'description' => 'Fleet tracking v1',
                'purpose' => 'Resident tracker safety',
                'legal_basis' => 'consent',
                'effective_from' => now()->subDay(),
            ],
        );

        return ClientConsent::query()->create([
            'client_id' => $client->id,
            'consent_type_id' => $type->id,
            'consent_type_version_id' => $version->id,
            'status' => 'given',
            'given_at' => now(),
            'given_method' => 'electronic',
            'expires_at' => now()->addMonth(),
        ]);
    }

    /** @param list<string> $permissions */
    private function makeSiteUser(Site $site, array $permissions): User
    {
        $user = User::factory()->create([
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        $permissionMap = collect($permissions)
            ->map(function (string $key): int {
                $module = str($key)->before('.')->value() ?: 'fleet';

                return Permission::query()->firstOrCreate(
                    ['key' => $key],
                    [
                        'description' => $key,
                        'group' => $module,
                        'module' => $module,
                    ],
                )->id;
            })
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);

        return $user;
    }
}
