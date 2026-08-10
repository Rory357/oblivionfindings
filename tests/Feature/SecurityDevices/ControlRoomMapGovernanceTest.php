<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device as CanonicalDevice;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomMapGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
    }

    public function test_live_map_uses_canonical_tracking_identity_and_position_only_when_both_domains_allow_it(): void
    {
        $site = Site::factory()->create(['name' => 'Kowhai House']);
        $operator = $this->operator($site, [
            'controlRoom.viewAny',
            'securityDevices.devices.view',
            'assets.viewAny',
            'assets.telemetry.view',
            'clients.viewAny',
        ]);
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'preferred_name' => 'Mere',
        ]);
        $consent = $this->trackingConsent($client, $operator);
        $canonical = CanonicalDevice::factory()->tracking()->create([
            'name' => 'Mere safety pendant',
            'category' => 'personal_tracker',
            'latitude' => -36.91234567,
            'longitude' => 174.81234567,
            'location_description' => 'Canonical-private-location-sentinel',
            'last_seen_at' => now()->subMinute(),
            'battery_level' => 82,
            'meta' => ['provider_payload' => 'canonical-raw-location-sentinel'],
        ]);
        $assignment = DeviceAssignment::query()->create([
            'device_id' => $canonical->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $client->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
            'assigned_by_user_id' => $operator->id,
            'consent_id' => $consent->id,
        ]);
        $projection = ControlRoomDevice::query()->create([
            'name' => 'Stale duplicate tracker name',
            'device_uid' => 'stale-projection-uid-sentinel',
            'type' => ControlRoomDevice::TYPE_PERSONAL_TRACKER,
            'site_id' => $site->id,
            'client_id' => $client->id,
            'latitude' => -35.11111111,
            'longitude' => 175.22222222,
            'location_description' => 'Stale-projection-location-sentinel',
            'status' => 'online',
            'canonical_device_id' => $canonical->id,
        ]);
        $alert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'device_id' => $projection->id,
            'notes' => 'raw-alert-notes-sentinel',
        ]);

        $this->actingAs($operator)
            ->get('/control-room/map')
            ->assertOk()
            ->assertInertia(function ($page) use ($alert, $canonical): void {
                $props = $page->toArray()['props'];
                $device = $props['devices'][0];

                $this->assertTrue($props['location_boundary']['position_access']);
                $this->assertSame($canonical->id, $device['id']);
                $this->assertSame('Mere safety pendant', $device['name']);
                $this->assertSame('canonical', $device['identity_source']);
                $this->assertSame(-36.91234567, $device['latitude']);
                $this->assertSame(174.81234567, $device['longitude']);
                $this->assertSame(
                    "/security-devices/devices/{$canonical->id}",
                    $device['detail_url'],
                );
                $this->assertSame($alert->id, $props['alerts'][0]['id']);
                $this->assertSame(-36.91234567, $props['alerts'][0]['latitude']);
                $this->assertSame(174.81234567, $props['alerts'][0]['longitude']);
                $this->assertArrayNotHasKey('notes', $props['alerts'][0]);

                $payload = json_encode($props, JSON_THROW_ON_ERROR);
                foreach ([
                    'Stale duplicate tracker name',
                    'stale-projection-uid-sentinel',
                    'Stale-projection-location-sentinel',
                    'raw-alert-notes-sentinel',
                    'canonical-raw-location-sentinel',
                    'Canonical-private-location-sentinel',
                    '-35.11111111',
                    '175.22222222',
                ] as $forbidden) {
                    $this->assertStringNotContainsString($forbidden, $payload);
                }
            });

        $assignment->update(['access_audience' => ['health_and_safety']]);

        $this->actingAs($operator)
            ->get('/control-room/map')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];

                $this->assertSame([], $props['devices']);
                $this->assertSame(1, $props['stats']['location_restricted']);
                $this->assertNull($props['alerts'][0]['device_id']);
                $this->assertNull($props['alerts'][0]['latitude']);
                $this->assertNull($props['alerts'][0]['longitude']);
                $payload = json_encode($props, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('-36.91234567', $payload);
                $this->assertStringNotContainsString('174.81234567', $payload);
            });

        $assignment->update(['access_audience' => ['control_room', 'health_and_safety']]);

        $consent->update([
            'status' => 'withdrawn',
            'withdrawn_at' => now(),
        ]);

        $this->actingAs($operator)
            ->get('/control-room/map')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];

                $this->assertSame([], $props['devices']);
                $this->assertSame(1, $props['stats']['location_restricted']);
                $this->assertNull($props['alerts'][0]['latitude']);
                $this->assertNull($props['alerts'][0]['longitude']);
                $payload = json_encode($props, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('-36.91234567', $payload);
                $this->assertStringNotContainsString('174.81234567', $payload);
            });
    }

    public function test_control_room_permission_alone_never_grants_security_device_identity_or_exact_location(): void
    {
        $site = Site::factory()->create(['name' => 'Miro House']);
        $operator = $this->operator($site, ['controlRoom.viewAny']);
        $canonical = CanonicalDevice::factory()->tracking()->create([
            'name' => 'Source-restricted-tracker-sentinel',
            'category' => 'vehicle_tracker',
            'latitude' => -37.76543210,
            'longitude' => 175.12345670,
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $canonical->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
            'assigned_by_user_id' => $operator->id,
        ]);
        ControlRoomDevice::query()->create([
            'name' => 'Signal projection',
            'type' => ControlRoomDevice::TYPE_VEHICLE_TRACKER,
            'site_id' => $site->id,
            'latitude' => -37.76543210,
            'longitude' => 175.12345670,
            'status' => 'online',
            'canonical_device_id' => $canonical->id,
        ]);

        $this->actingAs($operator)
            ->get('/control-room/map')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];

                $this->assertFalse($props['location_boundary']['position_access']);
                $this->assertSame([], $props['devices']);
                $payload = json_encode($props, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('Source-restricted-tracker-sentinel', $payload);
                $this->assertStringNotContainsString('-37.7654321', $payload);
                $this->assertStringNotContainsString('175.1234567', $payload);
            });
    }

    public function test_report_bypass_uses_the_single_application_site_boundary(): void
    {
        $homeSite = Site::factory()->create([
            'name' => 'Home Site',
        ]);
        $otherApplicationSite = Site::factory()->create([
            'name' => 'Other Application Site',
        ]);
        $operator = $this->operator($homeSite, [
            'controlRoom.viewAny',
            'reports.viewAny',
        ]);

        $this->actingAs($operator)
            ->get('/control-room/map')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('all_sites', fn ($sites): bool => collect($sites)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all() === collect([$homeSite->id, $otherApplicationSite->id])
                    ->sort()
                    ->values()
                    ->all())
            );

        $this->actingAs($operator)
            ->get("/control-room/map?site_id={$otherApplicationSite->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.site_id', (string) $otherApplicationSite->id)
            );
    }

    /** @param list<string> $permissionKeys */
    private function operator(Site $site, array $permissionKeys): User
    {
        $operator = User::factory()->create([
            'approved_at' => now(),
        ]);
        $permissions = Permission::query()->whereIn('key', $permissionKeys)->get()->keyBy('key');
        $this->assertCount(count($permissionKeys), $permissions);
        $operator->permissionOverrides()->sync(
            $permissions->mapWithKeys(fn (Permission $permission): array => [
                $permission->id => ['allowed' => true],
            ]),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $operator->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);

        return $operator;
    }

    private function trackingConsent(Client $client, User $actor): ClientConsent
    {
        $type = ConsentType::factory()->create([
            'name' => 'Asset Location Tracking (Safety)',
            'category' => 'privacy',
            'purpose' => 'Personal safety location tracking',
            'legal_basis' => 'consent',
            'active' => true,
        ]);

        return ClientConsent::query()->create([
            'client_id' => $client->id,
            'consent_type_id' => $type->id,
            'status' => 'given',
            'given_at' => now()->subHour(),
            'expires_at' => now()->addMonth(),
            'given_by_user_id' => $actor->id,
            'given_method' => 'written',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }
}
