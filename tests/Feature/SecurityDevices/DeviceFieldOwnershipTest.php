<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\DeviceFieldOwnershipService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceFieldOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DeviceFieldOwnershipService $ownership;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->admin = User::factory()->create(['approved_at' => now()]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->ownership = app(DeviceFieldOwnershipService::class);
    }

    public function test_provider_managed_fields_require_an_attributed_expiring_override(): void
    {
        $device = $this->providerDevice('provider-serial');

        $this->actingAs($this->admin)
            ->from("/security-devices/devices/{$device->id}?dialog=edit-device")
            ->put("/security-devices/devices/{$device->id}", $this->editPayload($device, [
                'serial_number' => 'local-serial',
                '_modal' => true,
            ]))
            ->assertSessionHasErrors(['override_reason', 'override_expires_at']);

        $device->refresh();
        $this->assertSame('provider-serial', $device->serial_number);
        $this->assertSame([], data_get($device->provider_field_overrides, 'active', []));

        $expiresAt = now()->addDay()->startOfMinute();
        $this->actingAs($this->admin)
            ->put("/security-devices/devices/{$device->id}", $this->editPayload($device, [
                'serial_number' => 'local-serial',
                'override_reason' => 'Replacement label verified during maintenance.',
                'override_expires_at' => $expiresAt->toDateTimeString(),
            ]))
            ->assertRedirect();

        $device->refresh();
        $this->assertSame('local-serial', $device->serial_number);
        $this->assertSame(
            'Replacement label verified during maintenance.',
            data_get($device->provider_field_overrides, 'active.serial_number.reason'),
        );
        $this->assertSame(
            $this->admin->id,
            data_get($device->provider_field_overrides, 'active.serial_number.recorded_by_user_id'),
        );
        $this->assertSame(
            'governed_override',
            data_get($device->local_intended_state, 'serial_number.quality'),
        );
    }

    public function test_generic_editor_cannot_rebind_provider_linkage(): void
    {
        $device = $this->providerDevice('provider-serial');

        $this->actingAs($this->admin)
            ->from("/security-devices/devices/{$device->id}?dialog=edit-device")
            ->put("/security-devices/devices/{$device->id}", $this->editPayload($device, [
                'provider' => 'milesight',
                'override_reason' => 'Temporary provider reassignment requested by operations.',
                'override_expires_at' => now()->addDay()->toDateTimeString(),
                '_modal' => true,
            ]))
            ->assertSessionHasErrors('provider');

        $device->refresh();
        $this->assertSame('unifi', $device->provider);
        $this->assertSame('unifi', data_get($device->external_ref, 'provider'));
        $this->assertSame([], data_get($device->provider_field_overrides, 'active', []));
    }

    public function test_provider_sync_records_conflict_without_overwriting_an_active_override(): void
    {
        $device = $this->providerDevice('provider-serial');
        $device = $this->ownership->updateFromLocal(
            $device,
            ['serial_number' => 'local-serial'],
            $this->admin,
            'Temporary serial correction after physical inspection.',
            now()->addHour(),
        );

        $observedAt = now()->addMinute();
        $device = $this->ownership->applyProviderObservation(
            $device,
            'unifi',
            ['serial_number' => 'provider-new', 'status' => 'offline'],
            $observedAt,
        );

        $this->assertSame('local-serial', $device->serial_number);
        $this->assertSame('offline', $device->status->value);
        $this->assertSame('provider-new', data_get($device->provider_observed_state, 'serial_number.value'));
        $this->assertSame('unifi', data_get($device->provider_observed_state, 'serial_number.source'));
        $this->assertSame('authoritative_provider', data_get($device->provider_observed_state, 'serial_number.quality'));
        $this->assertContains('serial_number', $this->ownership->snapshot($device)['conflicts']);
    }

    public function test_expired_override_returns_projection_to_latest_provider_value_and_retains_history(): void
    {
        $device = $this->providerDevice('provider-serial');
        $device = $this->ownership->updateFromLocal(
            $device,
            ['serial_number' => 'local-serial'],
            $this->admin,
            'Temporary serial correction after physical inspection.',
            now()->addMinute(),
        );

        $this->travel(2)->minutes();
        $device = $this->ownership->applyProviderObservation(
            $device,
            'unifi',
            ['serial_number' => 'provider-after-expiry'],
            now(),
        );

        $this->assertSame('provider-after-expiry', $device->serial_number);
        $this->assertNull(data_get($device->provider_field_overrides, 'active.serial_number'));
        $this->assertSame(
            'expired',
            data_get($device->provider_field_overrides, 'history.0.end_reason'),
        );
    }

    public function test_next_partial_sync_reconciles_an_expired_override_from_latest_observed_state(): void
    {
        $device = $this->providerDevice('provider-serial');
        $device = $this->ownership->updateFromLocal(
            $device,
            ['serial_number' => 'local-serial'],
            $this->admin,
            'Temporary serial correction after physical inspection.',
            now()->addMinute(),
        );

        $this->travel(2)->minutes();
        $device = $this->ownership->applyProviderObservation(
            $device,
            'unifi',
            ['status' => 'offline'],
            now(),
        );

        $this->assertSame('provider-serial', $device->serial_number);
        $this->assertSame('offline', $device->status->value);
        $this->assertNull(data_get($device->provider_field_overrides, 'active.serial_number'));
        $this->assertSame(
            'expired',
            data_get($device->provider_field_overrides, 'history.0.end_reason'),
        );
    }

    public function test_conflicting_duplicate_observation_cannot_rewrite_first_provider_evidence(): void
    {
        $device = $this->providerDevice('provider-serial');
        $observedAt = now()->addMinute();
        $device = $this->ownership->applyProviderObservation(
            $device,
            'unifi',
            ['serial_number' => 'FIRST-VALUE'],
            $observedAt,
        );

        $device = $this->ownership->applyProviderObservation(
            $device,
            'unifi',
            ['serial_number' => 'CONFLICTING-DUPLICATE'],
            $observedAt,
        );

        $this->assertSame('FIRST-VALUE', $device->serial_number);
        $this->assertSame(
            'FIRST-VALUE',
            data_get($device->provider_observed_state, 'serial_number.value'),
        );
    }

    public function test_exact_duplicate_observation_is_a_no_op_even_with_a_later_processing_time(): void
    {
        $device = $this->providerDevice('provider-serial');
        $observedAt = now()->addMinute();
        $device = $this->ownership->applyProviderObservation(
            $device,
            'unifi',
            ['serial_number' => 'STABLE-VALUE', 'status' => 'offline'],
            $observedAt,
            providerAttributes: [
                'external_ref' => ['host_id' => 'host-stable'],
                'meta' => ['uptime' => 123],
            ],
        );
        $firstUpdatedAt = $device->updated_at?->toIso8601String();
        $firstEvidenceAt = data_get($device->provider_observed_state, 'serial_number.observed_at');

        $this->travel(2)->minutes();
        $device = $this->ownership->applyProviderObservation(
            $device,
            'unifi',
            ['serial_number' => 'STABLE-VALUE', 'status' => 'offline'],
            $observedAt->copy()->addMinute(),
            providerAttributes: [
                'external_ref' => ['host_id' => 'host-stable'],
                'meta' => ['uptime' => 123],
            ],
        );

        $this->assertSame($firstUpdatedAt, $device->updated_at?->toIso8601String());
        $this->assertSame(
            $firstEvidenceAt,
            data_get($device->provider_observed_state, 'serial_number.observed_at'),
        );
    }

    public function test_partial_provider_attributes_preserve_omitted_values_and_drop_null_placeholders(): void
    {
        $device = $this->providerDevice('provider-serial');
        $firstAt = now()->addMinute();
        $device = $this->ownership->applyProviderObservation(
            $device,
            'unifi',
            ['status' => 'active'],
            $firstAt,
            providerAttributes: [
                'external_ref' => ['host_id' => 'host-1'],
                'meta' => ['firmware_status' => 'current', 'uptime' => 123],
            ],
        );

        $device = $this->ownership->applyProviderObservation(
            $device,
            'unifi',
            ['status' => 'offline'],
            $firstAt->copy()->addMinute(),
            providerAttributes: [
                'external_ref' => ['host_id' => null],
                'meta' => [
                    'firmware_status' => null,
                    'uptime' => null,
                    'experience_score' => 95,
                ],
            ],
        );

        $this->assertSame('host-1', data_get($device->external_ref, 'host_id'));
        $this->assertSame('current', data_get($device->meta, 'firmware_status'));
        $this->assertSame(123, data_get($device->meta, 'uptime'));
        $this->assertSame(95, data_get($device->meta, 'experience_score'));
    }

    public function test_provider_projection_retains_only_bounded_non_sensitive_metadata(): void
    {
        $device = $this->providerDevice('provider-serial');
        $device->forceFill([
            'external_ref' => [
                ...$device->external_ref,
                'legacy_access_token' => 'must-be-removed-on-provider-refresh',
                'raw_payload' => ['serial' => 'legacy-raw-copy'],
            ],
        ])->save();
        $device = $this->ownership->applyProviderObservation(
            $device,
            'unifi',
            ['status' => 'offline'],
            now()->addMinute(),
            providerAttributes: [
                'external_ref' => [
                    'migration_reference' => 'safe-reference',
                    'access_token' => 'must-not-be-stored',
                    'raw_payload' => ['serial' => 'raw-copy'],
                ],
                'meta' => [
                    'uptime' => 456,
                    'credentials' => ['password' => 'must-not-be-stored'],
                    'oversized' => str_repeat('x', 4097),
                ],
            ],
        );

        $this->assertSame('safe-reference', data_get($device->external_ref, 'migration_reference'));
        $this->assertSame(456, data_get($device->meta, 'uptime'));
        $this->assertArrayNotHasKey('access_token', $device->external_ref);
        $this->assertArrayNotHasKey('legacy_access_token', $device->external_ref);
        $this->assertArrayNotHasKey('raw_payload', $device->external_ref);
        $this->assertArrayNotHasKey('credentials', $device->meta);
        $this->assertArrayNotHasKey('oversized', $device->meta);
    }

    public function test_stale_provider_observation_cannot_replace_newer_evidence_or_projection(): void
    {
        $device = $this->providerDevice('provider-serial');
        $newerAt = now()->addMinutes(2);
        $device = $this->ownership->applyProviderObservation(
            $device,
            'unifi',
            ['serial_number' => 'PROVIDER-NEWEST', 'status' => 'active'],
            $newerAt,
            providerAttributes: [
                'external_ref' => ['provider_entity_id' => 'provider-newest'],
            ],
        );

        $device = $this->ownership->applyProviderObservation(
            $device,
            'unifi',
            ['serial_number' => 'PROVIDER-STALE', 'status' => 'offline'],
            $newerAt->copy()->subMinute(),
            providerAttributes: [
                'external_ref' => ['provider_entity_id' => 'provider-stale'],
            ],
        );

        $this->assertSame('PROVIDER-NEWEST', $device->serial_number);
        $this->assertSame('active', $device->status->value);
        $this->assertSame(
            'PROVIDER-NEWEST',
            data_get($device->provider_observed_state, 'serial_number.value'),
        );
        $this->assertSame(
            $newerAt->toIso8601String(),
            data_get($device->provider_observed_state, 'serial_number.observed_at'),
        );
        $this->assertSame('provider-newest', data_get($device->external_ref, 'provider_entity_id'));
    }

    public function test_provider_refresh_does_not_overwrite_local_classification(): void
    {
        $device = $this->providerDevice('provider-serial');
        $device = $this->ownership->updateFromLocal(
            $device,
            [
                'name' => 'Locally governed display name',
                'domain' => 'security',
                'category' => 'cctv',
            ],
            $this->admin,
            null,
            null,
        );

        $device = $this->ownership->applyProviderObservation(
            $device,
            'unifi',
            [
                'name' => 'Provider device name',
                'domain' => 'it_infrastructure',
                'category' => 'network',
            ],
            now()->addMinute(),
        );

        $this->assertSame('Locally governed display name', $device->name);
        $this->assertSame('security', $device->domain);
        $this->assertSame('cctv', $device->category);
        $this->assertSame('Provider device name', data_get($device->provider_observed_state, 'name.value'));
    }

    public function test_manual_device_updates_are_recorded_as_local_intent_without_an_override(): void
    {
        $device = Device::factory()->create([
            // A locally entered vendor label is not integration ownership until
            // provider evidence or linkage is recorded.
            'provider' => 'unifi',
            'manufacturer' => 'Old manufacturer',
        ]);

        $device = $this->ownership->updateFromLocal(
            $device,
            ['manufacturer' => 'Local manufacturer'],
            $this->admin,
            null,
            null,
        );

        $this->assertSame('Local manufacturer', $device->manufacturer);
        $this->assertFalse($this->ownership->snapshot($device)['provider_managed']);
        $this->assertSame('operator_intent', data_get($device->local_intended_state, 'manufacturer.quality'));
        $this->assertSame([], data_get($device->provider_field_overrides, 'active', []));
    }

    public function test_first_provider_sync_keeps_manual_intent_separate_from_observed_state(): void
    {
        $device = Device::factory()->create([
            'name' => 'Locally named tracker',
            'provider' => null,
            'serial_number' => 'LOCAL-SERIAL',
        ]);
        $device = $this->ownership->updateFromLocal(
            $device,
            ['serial_number' => 'LOCAL-INTENDED-SERIAL'],
            $this->admin,
            null,
            null,
        );

        $device = $this->ownership->applyProviderObservation(
            $device,
            'unifi',
            [
                'name' => 'Provider supplied name',
                'serial_number' => 'PROVIDER-SERIAL',
                'provider' => 'unifi',
            ],
            now(),
        );

        $this->assertSame('Locally named tracker', $device->name);
        $this->assertSame('PROVIDER-SERIAL', $device->serial_number);
        $this->assertSame('LOCAL-INTENDED-SERIAL', data_get($device->local_intended_state, 'serial_number.value'));
        $this->assertSame('PROVIDER-SERIAL', data_get($device->provider_observed_state, 'serial_number.value'));
    }

    public function test_manual_registration_records_attributed_local_intent(): void
    {
        $this->actingAs($this->admin)
            ->post('/security-devices/devices', [
                'name' => 'Locally registered camera',
                'domain' => 'security',
                'category' => 'cctv',
                'serial_number' => 'LOCAL-001',
            ])
            ->assertRedirect();

        $device = Device::query()->where('name', 'Locally registered camera')->firstOrFail();
        $this->assertSame('LOCAL-001', data_get($device->local_intended_state, 'serial_number.value'));
        $this->assertSame($this->admin->id, data_get($device->local_intended_state, 'serial_number.recorded_by_user_id'));
        $this->assertSame('local_registry_registration', data_get($device->local_intended_state, 'serial_number.source'));
        $this->assertSame([], $device->provider_observed_state ?? []);
    }

    public function test_quick_local_fields_are_recorded_through_the_same_attributed_intent_owner(): void
    {
        $device = $this->providerDevice('provider-serial');

        $this->actingAs($this->admin)
            ->patch("/security-devices/devices/{$device->id}/fields", [
                'location_description' => 'Locked communications cabinet',
                'notes' => 'Physical access is controlled by facilities.',
                'next_service_due' => now()->addMonth()->toDateString(),
            ])
            ->assertRedirect();

        $device->refresh();
        $this->assertSame('Locked communications cabinet', $device->location_description);
        $this->assertSame(
            'operator_intent',
            data_get($device->local_intended_state, 'location_description.quality'),
        );
        $this->assertSame(
            $this->admin->id,
            data_get($device->local_intended_state, 'location_description.recorded_by_user_id'),
        );
        $this->assertSame(
            'local_registry',
            data_get($device->local_intended_state, 'next_service_due.source'),
        );
    }

    public function test_provider_attributes_cannot_bypass_field_ownership_projection(): void
    {
        $device = $this->providerDevice('provider-serial');

        $this->expectException(\InvalidArgumentException::class);
        $this->ownership->applyProviderObservation(
            $device,
            'unifi',
            ['serial_number' => 'PROVIDER-OBSERVED'],
            now(),
            providerAttributes: ['serial_number' => 'SIDE-CHANNEL-VALUE'],
        );
    }

    public function test_edit_contract_exposes_observation_override_and_conflict_state(): void
    {
        $device = $this->providerDevice('provider-serial');
        $device = $this->ownership->updateFromLocal(
            $device,
            ['serial_number' => 'local-serial'],
            $this->admin,
            'Temporary serial correction after physical inspection.',
            now()->addHour(),
        );
        $device = $this->ownership->applyProviderObservation(
            $device,
            'unifi',
            ['serial_number' => 'provider-new'],
            now()->addMinute(),
        );

        $this->actingAs($this->admin)
            ->getJson("/security-devices/devices/{$device->id}/edit")
            ->assertOk()
            ->assertJsonPath('device.field_ownership.provider_managed', true)
            ->assertJsonPath('device.field_ownership.observed.serial_number.value', 'provider-new')
            ->assertJsonPath('device.field_ownership.active_overrides.serial_number.value', 'local-serial')
            ->assertJsonPath('device.field_ownership.conflicts.0', 'serial_number');
    }

    private function providerDevice(string $serial): Device
    {
        return $this->ownership->applyProviderObservation(
            new Device,
            'unifi',
            [
                'name' => 'Provider camera',
                'domain' => 'security',
                'category' => 'cctv',
                'subcategory' => 'dome_camera',
                'manufacturer' => 'Ubiquiti',
                'model' => 'UVC',
                'serial_number' => $serial,
                'mac_address' => 'AA:BB:CC:DD:EE:FF',
                'firmware_version' => '1.0.0',
                'ip_address' => '192.0.2.10',
                'status' => 'active',
                'health_status' => 'healthy',
                'provider' => 'unifi',
                'last_seen_at' => now(),
            ],
            now(),
            providerAttributes: [
                'external_ref' => [
                    'provider' => 'unifi',
                    'provider_entity_id' => 'provider-camera-1',
                ],
            ],
        );
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function editPayload(Device $device, array $overrides = []): array
    {
        return array_merge([
            'name' => $device->name,
            'domain' => $device->domain,
            'category' => $device->category,
            'subcategory' => $device->subcategory,
            'manufacturer' => $device->manufacturer,
            'model' => $device->model,
            'serial_number' => $device->serial_number,
            'mac_address' => $device->mac_address,
            'imei' => $device->imei,
            'asset_tag' => $device->asset_tag,
            'firmware_version' => $device->firmware_version,
            'ip_address' => $device->ip_address,
            'status' => $device->status?->value,
            'health_status' => $device->health_status?->value,
            'provider' => $device->provider,
            'location_description' => $device->location_description,
            'notes' => $device->notes,
        ], $overrides);
    }
}
