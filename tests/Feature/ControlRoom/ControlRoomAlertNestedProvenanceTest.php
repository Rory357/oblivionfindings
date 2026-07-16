<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\AlertWorkspaceService;
use App\Services\Incidents\IncidentJourneyService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomAlertNestedProvenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_workspace_redacts_nested_asset_signal_and_device_when_they_conflict_with_the_alert_site(): void
    {
        $localSite = Site::factory()->create(['tenant_id' => 1]);
        $foreignSite = Site::factory()->create(['tenant_id' => 2]);
        $viewer = $this->siteViewer($localSite);
        $localClient = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $localSite->id,
        ]);
        $foreignAsset = Asset::factory()->forSite($foreignSite)->create();
        $foreignSignal = FleetSignal::query()->create([
            'asset_id' => $foreignAsset->id,
            'signal_type' => 'geofence_breach',
            'severity_hint' => 'critical',
            'occurred_at' => now(),
            'idempotency_key' => 'foreign-workspace-signal',
            'payload' => ['latitude' => -36.8485, 'longitude' => 174.7633],
        ]);
        $foreignDevice = ControlRoomDevice::query()->create([
            'name' => 'Foreign bedroom sensor',
            'device_uid' => 'foreign-workspace-device',
            'type' => ControlRoomDevice::TYPE_SENSOR,
            'site_id' => $foreignSite->id,
            'latitude' => -36.8485,
            'longitude' => 174.7633,
            'location_description' => 'Private bedroom',
        ]);
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'fleet',
            'site_id' => $localSite->id,
            'client_id' => $localClient->id,
            'asset_id' => $foreignAsset->id,
            'fleet_signal_id' => $foreignSignal->id,
            'device_id' => $foreignDevice->id,
        ]);

        $workspace = app(AlertWorkspaceService::class)->build($viewer, $alert->id);

        $this->assertNotNull($workspace);
        $this->assertNull(data_get($workspace, 'alert.asset_id'));
        $this->assertNull(data_get($workspace, 'alert.asset'));
        $this->assertNull(data_get($workspace, 'alert.fleet_signal_id'));
        $this->assertNull(data_get($workspace, 'alert.fleet_signal'));
        $this->assertNull(data_get($workspace, 'location'));
        $this->assertSame($localClient->id, data_get($workspace, 'client.id'));
    }

    public function test_workspace_keeps_nested_relations_that_match_the_authoritative_alert_tuple(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $viewer = $this->siteViewer($site);
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
        ]);
        $asset = Asset::factory()->forSite($site)->create(['client_id' => $client->id]);
        $signal = FleetSignal::query()->create([
            'asset_id' => $asset->id,
            'signal_type' => 'geofence_breach',
            'severity_hint' => 'high',
            'occurred_at' => now(),
            'idempotency_key' => 'local-workspace-signal',
            'payload' => ['safe' => true],
        ]);
        $device = ControlRoomDevice::query()->create([
            'name' => 'Local hallway sensor',
            'device_uid' => 'local-workspace-device',
            'type' => ControlRoomDevice::TYPE_SENSOR,
            'site_id' => $site->id,
            'client_id' => $client->id,
            'asset_id' => $asset->id,
            'latitude' => -41.2865,
            'longitude' => 174.7762,
            'location_description' => 'Hallway',
        ]);
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'fleet',
            'site_id' => $site->id,
            'client_id' => $client->id,
            'asset_id' => $asset->id,
            'fleet_signal_id' => $signal->id,
            'device_id' => $device->id,
        ]);

        $workspace = app(AlertWorkspaceService::class)->build($viewer, $alert->id);

        $this->assertSame($asset->id, data_get($workspace, 'alert.asset.id'));
        $this->assertSame($signal->id, data_get($workspace, 'alert.fleet_signal.id'));
        $this->assertSame('Hallway', data_get($workspace, 'location.description'));
    }

    public function test_workspace_redacts_same_tenant_cross_site_client_asset_assignee_and_context(): void
    {
        $localSite = Site::factory()->create(['tenant_id' => 1]);
        $hiddenSite = Site::factory()->create(['tenant_id' => 1]);
        $viewer = $this->siteViewer($localSite);
        $hiddenClient = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $hiddenSite->id,
        ]);
        $hiddenAsset = Asset::factory()->forSite($hiddenSite)->create([
            'client_id' => $hiddenClient->id,
        ]);
        $hiddenAssignee = User::factory()->create([
            'organization_id' => 1,
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $hiddenAssignee->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 1,
            'user_id' => $hiddenAssignee->id,
            'primary_site_id' => $hiddenSite->id,
            'secondary_site_ids' => [],
        ]);
        $alert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $localSite->id,
            'client_id' => $hiddenClient->id,
            'asset_id' => $hiddenAsset->id,
            'assigned_to_user_id' => $hiddenAssignee->id,
            'context' => [
                'client_name' => 'Hidden Person',
                'asset_name' => 'Hidden Tracker',
                'latitude' => -45.0,
                'longitude' => 170.0,
                'normalized_data' => [
                    'client_id' => $hiddenClient->id,
                    'client_name' => 'Hidden Person',
                    'asset_id' => $hiddenAsset->id,
                    'latitude' => -45.0,
                    'longitude' => 170.0,
                ],
            ],
        ]);

        $workspace = app(AlertWorkspaceService::class)->build($viewer, $alert->id);

        $this->assertNotNull($workspace);
        $this->assertNull(data_get($workspace, 'client'));
        $this->assertNull(data_get($workspace, 'alert.asset'));
        $this->assertNull(data_get($workspace, 'alert.assigned_to'));
        $this->assertNull(data_get($workspace, 'alert.assigned_to_user_id'));
        $this->assertNull(data_get($workspace, 'alert.context.client_name'));
        $this->assertNull(data_get($workspace, 'alert.context.normalized_data.client_name'));
        $this->assertNull(data_get($workspace, 'alert.context.normalized_data.asset_id'));
        $this->assertNull(data_get($workspace, 'alert.context.normalized_data.latitude'));
    }

    public function test_task7_final_gap_context_site_cannot_be_replaced_by_context_client_identity(): void
    {
        $localSite = Site::factory()->create(['tenant_id' => 1]);
        $foreignSite = Site::factory()->create(['tenant_id' => 2]);
        $viewer = $this->siteViewer($localSite);
        $localClient = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $localSite->id,
        ]);
        $foreignClient = Client::factory()->create([
            'organization_id' => 2,
            'site_id' => $foreignSite->id,
        ]);

        $poisoned = ControlRoomAlert::factory()->open()->create([
            'site_id' => null,
            'client_id' => null,
            'context' => [
                'site_id' => $localSite->id,
                'client_id' => $foreignClient->id,
                'client_name' => 'Foreign Client Identity',
                'resident_id' => $foreignClient->id,
                'resident_name' => 'Foreign Resident Identity',
                'client' => ['id' => $foreignClient->id, 'name' => 'Foreign Client Identity'],
                'normalized_data' => [
                    'client_id' => $foreignClient->id,
                    'client_name' => 'Foreign Client Identity',
                    'resident_id' => $foreignClient->id,
                    'resident_name' => 'Foreign Resident Identity',
                    'client' => ['id' => $foreignClient->id, 'name' => 'Foreign Client Identity'],
                ],
            ],
        ]);
        $valid = ControlRoomAlert::factory()->open()->create([
            'site_id' => null,
            'client_id' => null,
            'context' => [
                'site_id' => $localSite->id,
                'client_id' => $localClient->id,
                'client_name' => 'Local Client Identity',
                'resident_id' => $localClient->id,
                'resident_name' => 'Local Resident Identity',
                'normalized_data' => [
                    'client_id' => $localClient->id,
                    'client_name' => 'Local Client Identity',
                    'resident_id' => $localClient->id,
                    'resident_name' => 'Local Resident Identity',
                ],
            ],
        ]);

        $poisonedWorkspace = app(AlertWorkspaceService::class)->build($viewer, $poisoned->id);
        $validWorkspace = app(AlertWorkspaceService::class)->build($viewer, $valid->id);

        $this->assertNotNull($poisonedWorkspace, 'The local context site keeps the alert in the local worklist.');
        $this->assertSame($localSite->id, data_get($poisonedWorkspace, 'alert.context.site_id'));
        foreach ([
            'client_id',
            'client_name',
            'resident_id',
            'resident_name',
            'client',
            'normalized_data.client_id',
            'normalized_data.client_name',
            'normalized_data.resident_id',
            'normalized_data.resident_name',
            'normalized_data.client',
        ] as $identityPath) {
            $this->assertNull(
                data_get($poisonedWorkspace, "alert.context.{$identityPath}"),
                "Unsafe context identity remained at {$identityPath}.",
            );
        }

        $this->assertNotNull($validWorkspace);
        $this->assertSame($localClient->id, data_get($validWorkspace, 'alert.context.client_id'));
        $this->assertSame('Local Client Identity', data_get($validWorkspace, 'alert.context.client_name'));
        $this->assertSame($localClient->id, data_get($validWorkspace, 'alert.context.normalized_data.resident_id'));
        $this->assertSame('Local Resident Identity', data_get($validWorkspace, 'alert.context.normalized_data.resident_name'));
    }

    public function test_task7_spec_followup_direct_client_does_not_trust_conflicting_context_identity(): void
    {
        $localSite = Site::factory()->create(['tenant_id' => 1]);
        $foreignSite = Site::factory()->create(['tenant_id' => 2]);
        $viewer = $this->siteViewer($localSite);
        $localClient = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $localSite->id,
        ]);
        $foreignClient = Client::factory()->create([
            'organization_id' => 2,
            'site_id' => $foreignSite->id,
        ]);

        $poisoned = ControlRoomAlert::factory()->open()->create([
            'site_id' => $localSite->id,
            'client_id' => $localClient->id,
            'context' => [
                'client_id' => $foreignClient->id,
                'client_name' => 'Foreign Client Identity',
                'resident_id' => $foreignClient->id,
                'resident_name' => 'Foreign Resident Identity',
                'client' => ['id' => $foreignClient->id, 'name' => 'Foreign Client Identity'],
                'normalized_data' => [
                    'client_id' => $foreignClient->id,
                    'client_name' => 'Foreign Client Identity',
                    'resident_id' => $foreignClient->id,
                    'resident_name' => 'Foreign Resident Identity',
                    'client' => ['id' => $foreignClient->id, 'name' => 'Foreign Client Identity'],
                ],
            ],
        ]);
        $matching = ControlRoomAlert::factory()->open()->create([
            'site_id' => $localSite->id,
            'client_id' => $localClient->id,
            'context' => [
                'client_id' => $localClient->id,
                'client_name' => 'Local Client Identity',
                'resident_id' => $localClient->id,
                'resident_name' => 'Local Resident Identity',
                'normalized_data' => [
                    'client_id' => $localClient->id,
                    'client_name' => 'Local Client Identity',
                    'resident_id' => $localClient->id,
                    'resident_name' => 'Local Resident Identity',
                ],
            ],
        ]);

        $poisonedWorkspace = app(AlertWorkspaceService::class)->build($viewer, $poisoned->id);
        $matchingWorkspace = app(AlertWorkspaceService::class)->build($viewer, $matching->id);

        $this->assertSame($localClient->id, data_get($poisonedWorkspace, 'client.id'));
        foreach ([
            'client_id',
            'client_name',
            'resident_id',
            'resident_name',
            'client',
            'normalized_data.client_id',
            'normalized_data.client_name',
            'normalized_data.resident_id',
            'normalized_data.resident_name',
            'normalized_data.client',
        ] as $identityPath) {
            $this->assertNull(
                data_get($poisonedWorkspace, "alert.context.{$identityPath}"),
                "Conflicting direct-client context identity remained at {$identityPath}.",
            );
        }

        $this->assertSame($localClient->id, data_get($matchingWorkspace, 'alert.context.client_id'));
        $this->assertSame('Local Client Identity', data_get($matchingWorkspace, 'alert.context.client_name'));
        $this->assertSame($localClient->id, data_get($matchingWorkspace, 'alert.context.normalized_data.resident_id'));
        $this->assertSame('Local Resident Identity', data_get($matchingWorkspace, 'alert.context.normalized_data.resident_name'));
    }

    public function test_incident_submission_rejects_an_alert_with_a_foreign_nested_asset(): void
    {
        $localSite = Site::factory()->create(['tenant_id' => 1]);
        $foreignSite = Site::factory()->create(['tenant_id' => 2]);
        $actor = User::factory()->create(['organization_id' => 1]);
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $localSite->id,
        ]);
        $foreignAsset = Asset::factory()->forSite($foreignSite)->create();
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'sensor',
            'site_id' => $localSite->id,
            'client_id' => $client->id,
            'asset_id' => $foreignAsset->id,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('provenance');

        app(IncidentJourneyService::class)->submitFromAlert($alert, [
            'client_id' => $client->id,
            'site_id' => $localSite->id,
            'type' => 'fall',
            'severity' => 'high',
            'occurred_at' => now(),
            'title' => 'Fall detected',
            'description' => 'The sensor alert carries a poisoned foreign asset.',
        ], $actor);
    }

    public function test_tenant_admin_cannot_create_an_unattributed_site_less_alert(): void
    {
        Site::factory()->create(['tenant_id' => 1]);
        $tenantAdmin = User::factory()->create([
            'organization_id' => 1,
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $tenantAdmin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        $this->actingAs($tenantAdmin)
            ->postJson('/control-room/alerts', [
                'source' => 'manual',
                'alert_type' => 'Unattributed welfare concern',
                'severity' => 'high',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('control_room_alerts', [
            'alert_type' => 'Unattributed welfare concern',
        ]);
    }

    public function test_explicit_platform_admin_can_create_an_installation_level_alert(): void
    {
        $platformAdmin = User::factory()->create([
            'organization_id' => null,
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $platformAdmin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        $this->actingAs($platformAdmin)
            ->postJson('/control-room/alerts', [
                'source' => 'manual',
                'alert_type' => 'Installation service interruption',
                'severity' => 'high',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('control_room_alerts', [
            'alert_type' => 'Installation service interruption',
            'site_id' => null,
            'client_id' => null,
        ]);
    }

    private function siteViewer(Site $site): User
    {
        $viewer = User::factory()->create([
            'organization_id' => (int) $site->tenant_id,
            'approved_at' => now(),
        ]);
        $permissions = Permission::query()
            ->whereIn('key', [
                'controlRoom.viewAny',
                'controlRoom.alerts.view',
            ])
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();
        $viewer->permissionOverrides()->sync($permissions);

        HrEmployeeProfile::factory()->create([
            'tenant_id' => (int) $site->tenant_id,
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $viewer;
    }
}
