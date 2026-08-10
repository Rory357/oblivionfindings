<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Services\DeviceAssignmentService;
use App\Jobs\PrunePersonalTrackingTelemetry;
use App\Models\Asset;
use App\Models\AssetTelemetryHistory;
use App\Models\AssetTelemetrySnapshot;
use App\Models\AssetTracker;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\FleetDeviceRuntimeService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalTrackingConsentWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    public function test_withdrawal_stops_collection_and_blocks_ui_api_export_direct_url_and_cached_recheck(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $site = Site::factory()->create();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->where('name', 'admin')->value('id'));
        $client = Client::factory()->create([
            'site_id' => $site->id,
        ]);
        $consentType = ConsentType::factory()->create([
            'name' => 'Personal Tracker (Wandering Risk)',
            'purpose' => 'Client personal safety tracking',
            'active' => true,
        ]);
        $assignedConsent = $this->givenConsent($client, $consentType, $admin);

        $asset = Asset::factory()->forSite($site)->forClient($client->id)->create();
        $tracker = AssetTracker::query()->create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => 'withdrawal-tracker-1',
            'status' => 'paired',
            'paired_at' => now()->subDay(),
            'consent_id' => $assignedConsent->id,
            'vendor_metadata' => [
                'provider_device_id' => 'safe-reference',
                'position' => ['lat' => -36.8485, 'lng' => 174.7633],
            ],
        ]);
        $device = Device::factory()->tracking()->create([
            'legacy_asset_tracker_id' => $tracker->id,
            'latitude' => -36.8485,
            'longitude' => 174.7633,
            'location_description' => 'Sensitive current address',
            'meta' => [
                'speed' => 4.5,
                'location' => ['lat' => -36.8485, 'lng' => 174.7633],
                'operational_health' => 'online',
            ],
            'external_ref' => [
                'provider_device_id' => 'safe-reference',
                'gps' => ['latitude' => -36.8485, 'longitude' => 174.7633],
            ],
        ]);
        DeviceAssetLink::query()->create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now()->subDays(5),
            'linked_by_user_id' => $admin->id,
        ]);
        $assignment = app(DeviceAssignmentService::class)->assign(
            $device,
            'client',
            $client->id,
            $admin->id,
            consentId: $assignedConsent->id,
        );
        $this->assertSame('Client personal safety tracking', $assignment->tracking_purpose);
        $this->assertSame('assignment_linked_client_consent', $assignment->authority_basis);
        $this->assertSame(
            ['authorised_client_care', 'control_room', 'health_and_safety'],
            $assignment->access_audience,
        );
        $this->assertSame(90, $assignment->retention_days);
        $this->assertNotNull($assignment->collection_started_at);
        $this->assertTrue($assignment->isCollectionActive());
        $assignment->update([
            'retention_days' => 1,
            'assigned_at' => now()->subDays(5),
            'collection_started_at' => now()->subDays(5),
        ]);

        FleetVehicleStateSnapshot::query()->create([
            'asset_id' => $asset->id,
            'last_seen_at' => now(),
            'last_moving_at' => now(),
            'latitude' => -36.8485,
            'longitude' => 174.7633,
            'speed_kph' => 4.5,
            'heading_deg' => 90,
            'ignition' => true,
            'motion_status' => 'moving',
            'status' => 'online',
            'consent_blocked' => false,
        ]);
        $oldSnapshot = AssetTelemetrySnapshot::query()->create([
            'asset_id' => $asset->id,
            'asset_tracker_id' => $tracker->id,
            'device_id' => $device->id,
            'occurred_at' => now()->subDays(3),
            'received_at' => now()->subDays(3),
            'latitude' => -36.9,
            'longitude' => 174.7,
            'speed_kph' => 1.5,
            'vendor_payload_hash' => 'expired-personal-device-detail-snapshot',
            'vendor_metadata' => ['location' => ['lat' => -36.9, 'lng' => 174.7]],
            'consent_blocked' => false,
        ]);
        $currentSnapshot = AssetTelemetrySnapshot::query()->create([
            'asset_id' => $asset->id,
            'asset_tracker_id' => $tracker->id,
            'device_id' => $device->id,
            'occurred_at' => now(),
            'received_at' => now(),
            'latitude' => -36.8485,
            'longitude' => 174.7633,
            'speed_kph' => 4.5,
            'vendor_payload_hash' => 'personal-device-detail-snapshot',
            'vendor_metadata' => ['location' => ['lat' => -36.8485, 'lng' => 174.7633]],
            'consent_blocked' => false,
        ]);
        $oldSummary = AssetTelemetryHistory::query()->create([
            'asset_id' => $asset->id,
            'summary_date' => today()->subDays(3),
            'distance_km' => 2.5,
            'time_moving_minutes' => 12,
            'last_latitude' => -36.9,
            'last_longitude' => 174.7,
        ]);
        $oldEvent = $this->telemetryEvent($asset, $tracker, $device, now()->subDays(3), 'old');
        $currentEvent = $this->telemetryEvent($asset, $tracker, $device, now(), 'current');

        // A later consent for the same client must never replace the exact
        // consent bound to this assignment after that bound consent is withdrawn.
        $unrelatedLaterConsent = $this->givenConsent($client, $consentType, $admin);

        $initialStatus = $this->actingAs($admin)
            ->getJson(route('operations.clients.location.privacy-status', $client, false))
            ->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJson(['active' => true, 'export_allowed' => true]);
        $this->assertStringContainsString('no-store', $initialStatus->headers->get('Cache-Control'));
        $profileResponse = $this->get("/operations/clients/{$client->id}?tab=location")
            ->assertOk()
            ->assertHeader('Cache-Control');
        $this->assertStringContainsString(
            'no-store',
            $profileResponse->headers->get('Cache-Control'),
        );

        // Generic device detail must never become an alternate or cached path
        // to personal telemetry. It keeps safe diagnostics but directs the
        // viewer to the governed Tracking/Client surfaces.
        $this->get("/fleet-assets/devices?device={$device->id}")
            ->assertOk()
            ->assertInertia(function ($page): void {
                $detail = $page->toArray()['props']['device_detail'];
                $this->assertSame([], $detail['telemetry_snapshots']);
                $this->assertFalse($detail['telemetry_access']['allowed']);
                $this->assertSame(
                    'use_governed_tracking_workspace',
                    $detail['telemetry_access']['reason'],
                );
                $this->assertSame(
                    ['provider_device_id' => 'safe-reference'],
                    $detail['vendor_metadata'],
                );
            });

        $historyResponse = $this->getJson(route('operations.clients.location.history', $client, false))
            ->assertOk()
            ->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', $historyResponse->headers->get('Cache-Control'));
        $history = $historyResponse->json('locations');
        $this->assertCount(1, $history);
        $this->assertSame((float) $currentEvent->latitude, $history[0]['lat']);
        $this->assertNotSame((float) $oldEvent->latitude, $history[0]['lat']);

        $this->postJson(route('operations.clients.location.export', $client, false), [
            'date_from' => today()->toDateString(),
            'date_to' => today()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $viewer = User::factory()->create();
        $viewerRole = Role::query()->create([
            'name' => 'tracking_viewer_without_export',
            'label' => 'Tracking Viewer Without Export',
            'level' => 50,
            'type' => 'custom',
        ]);
        $viewerRole->permissions()->sync(Permission::query()->whereIn('key', [
            'clients.viewAny',
            'assets.viewAny',
            'assets.telemetry.view',
        ])->pluck('id'));
        $viewer->roles()->attach($viewerRole->id);

        $this->actingAs($viewer)
            ->postJson(route('operations.clients.location.export', $client, false), [
                'reason' => 'Safety incident review',
                'date_from' => today()->toDateString(),
                'date_to' => today()->toDateString(),
            ])->assertForbidden();

        $export = $this->actingAs($admin)
            ->postJson(route('operations.clients.location.export', $client, false), [
                'reason' => 'Safety incident review',
                'date_from' => today()->toDateString(),
                'date_to' => today()->toDateString(),
            ]);
        $export->assertOk()
            ->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', $export->headers->get('Cache-Control'));
        $this->assertStringContainsString('Timestamp,Latitude,Longitude', $export->streamedContent());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tracking.location_export.authorised',
            'client_id' => $client->id,
            'user_id' => $admin->id,
        ]);

        $withdraw = $this->actingAs($admin)->post(
            route('operations.clients.consents.withdraw', [$client, $assignedConsent], false),
            ['withdrawal_reason' => 'Client withdrew permission'],
        );
        $withdraw->assertRedirect()
            ->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', $withdraw->headers->get('Cache-Control'));

        $assignment->refresh();
        $device->refresh();
        $state = FleetVehicleStateSnapshot::query()->findOrFail($asset->id);
        $this->assertNotNull($assignment->collection_stopped_at);
        $this->assertSame('consent_withdrawn', $assignment->collection_stop_reason);
        $this->assertSame(
            'collection_stopped_and_live_projection_revoked',
            $assignment->withdrawal_outcome,
        );
        $this->assertNull($device->latitude);
        $this->assertNull($device->longitude);
        $this->assertNull($device->location_description);
        $this->assertSame(['operational_health' => 'online'], $device->meta);
        $this->assertSame(
            ['provider_device_id' => 'safe-reference'],
            $tracker->fresh()->vendor_metadata,
        );
        $this->assertNull($state->latitude);
        $this->assertNull($state->longitude);
        $this->assertNull($state->last_event_id);
        $this->assertTrue($state->consent_blocked);
        $this->assertNull(app(FleetDeviceRuntimeService::class)->resolveConsentContext($device)['consent']);
        $this->assertTrue($unrelatedLaterConsent->fresh()->isValid());

        $withdrawnStatus = $this->getJson(route('operations.clients.location.privacy-status', $client, false))
            ->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJson(['active' => false, 'export_allowed' => false]);
        $this->assertStringContainsString('no-store', $withdrawnStatus->headers->get('Cache-Control'));
        $this->getJson(route('operations.clients.location.history', $client, false))
            ->assertForbidden();
        $this->postJson(route('operations.clients.location.export', $client, false), [
            'reason' => 'Attempt from a cached page',
            'date_from' => today()->toDateString(),
            'date_to' => today()->toDateString(),
        ])->assertForbidden();
        $this->get("/fleet-assets/devices?device={$device->id}")
            ->assertOk()
            ->assertInertia(function ($page): void {
                $detail = $page->toArray()['props']['device_detail'];
                $this->assertSame([], $detail['telemetry_snapshots']);
                $this->assertFalse($detail['telemetry_access']['allowed']);
                $this->assertSame(
                    'personal_assignment_ended',
                    $detail['telemetry_access']['reason'],
                );
            });

        (new PrunePersonalTrackingTelemetry)->handle();
        $this->assertDatabaseMissing('fleet_telemetry_events', ['id' => $oldEvent->id]);
        $this->assertDatabaseHas('fleet_telemetry_events', ['id' => $currentEvent->id]);
        $this->assertDatabaseMissing('asset_telemetry_snapshots', ['id' => $oldSnapshot->id]);
        $this->assertDatabaseHas('asset_telemetry_snapshots', ['id' => $currentSnapshot->id]);
        $oldSummary->refresh();
        $this->assertNull($oldSummary->last_latitude);
        $this->assertNull($oldSummary->last_longitude);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tracking.retention.enforced',
            'auditable_id' => $assignment->id,
        ]);

        // Staff location uses a separate operational-safety authority and is
        // revoked immediately when that assignment ends; it never borrows a
        // Client consent.
        $worker = User::factory()->create(['approved_at' => now()]);
        $staffTracker = Device::factory()->tracking()->create([
            'latitude' => -41.2865,
            'longitude' => 174.7762,
            'location_description' => 'Staff safety location',
        ]);
        $staffAssignment = app(DeviceAssignmentService::class)->assign(
            $staffTracker,
            'staff',
            $worker->id,
            $admin->id,
        );
        $this->assertSame('Staff lone-worker safety', $staffAssignment->tracking_purpose);
        $this->assertSame('active_lone_worker_session', $staffAssignment->authority_basis);
        $this->assertSame(
            ['control_room', 'health_and_safety'],
            $staffAssignment->access_audience,
        );
        $this->assertTrue($staffAssignment->isCollectionActive());

        app(DeviceAssignmentService::class)->release(
            $staffTracker,
            $admin->id,
        );
        $staffAssignment->refresh();
        $staffTracker->refresh();
        $this->assertNotNull($staffAssignment->released_at);
        $this->assertNotNull($staffAssignment->collection_stopped_at);
        $this->assertSame('assignment_released', $staffAssignment->collection_stop_reason);
        $this->assertNull($staffTracker->latitude);
        $this->assertNull($staffTracker->longitude);

        $this->assertGreaterThanOrEqual(1, AuditLog::query()
            ->where('action', 'tracking.consent.withdrawal_enforced')
            ->where('client_id', $client->id)
            ->count());
    }

    private function givenConsent(Client $client, ConsentType $type, User $actor): ClientConsent
    {
        return ClientConsent::query()->create([
            'client_id' => $client->id,
            'consent_type_id' => $type->id,
            'status' => 'given',
            'given_at' => now(),
            'given_by_user_id' => $actor->id,
            'given_method' => 'electronic',
            'expires_at' => now()->addMonth(),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function telemetryEvent(
        Asset $asset,
        AssetTracker $tracker,
        Device $device,
        \DateTimeInterface $occurredAt,
        string $suffix,
    ): FleetTelemetryEvent {
        return FleetTelemetryEvent::query()->create([
            'asset_id' => $asset->id,
            'asset_tracker_id' => $tracker->id,
            'device_id' => $device->id,
            'vendor' => 'queclink',
            'vendor_message_id' => "privacy-{$suffix}",
            'occurred_at' => $occurredAt,
            'received_at' => $occurredAt,
            'latitude' => $suffix === 'old' ? -36.9 : -36.8485,
            'longitude' => 174.7633,
            'speed_kph' => 4.5,
            'battery_pct' => 80,
            'event_type' => 'position',
            'idempotency_key' => hash('sha256', "privacy-{$suffix}"),
            'raw_payload' => [],
            'consent_blocked' => false,
        ]);
    }
}
