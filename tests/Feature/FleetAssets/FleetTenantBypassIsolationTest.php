<?php

namespace Tests\Feature\FleetAssets;

use App\Models\Asset;
use App\Models\AssetAlert;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\FleetShiftHandover;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FleetTenantBypassIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_tenant_fleet_and_report_managers_cannot_read_foreign_archived_alerts_or_filter_by_foreign_assets(): void
    {
        $localSite = Site::factory()->create(['tenant_id' => 11]);
        $foreignSite = Site::factory()->create(['tenant_id' => 22]);
        $localAsset = Asset::factory()->vehicle()->create(['site_id' => $localSite->id]);
        $foreignAsset = Asset::factory()->vehicle()->create(['site_id' => $foreignSite->id]);

        $localAlert = ControlRoomAlert::factory()->fromFleet()->open()->create([
            'site_id' => $localSite->id,
            'asset_id' => $localAsset->id,
        ]);
        ControlRoomAlert::factory()->fromFleet()->open()->create([
            'site_id' => $foreignSite->id,
            'asset_id' => $foreignAsset->id,
        ]);

        $localArchivedAlert = AssetAlert::query()->create([
            'asset_id' => $localAsset->id,
            'alert_type' => 'Local archived fleet alert',
            'severity' => 'medium',
            'status' => 'resolved',
            'triggered_at' => now()->subHour(),
        ]);
        AssetAlert::query()->create([
            'asset_id' => $foreignAsset->id,
            'alert_type' => 'Foreign archived fleet alert',
            'severity' => 'high',
            'status' => 'resolved',
            'triggered_at' => now(),
        ]);

        foreach (['fleet.manage', 'reports.viewAny'] as $bypassPermission) {
            $manager = $this->tenantUser(11, ['assets.alerts.view', $bypassPermission]);

            $this->actingAs($manager)
                ->get('/fleet-assets/alerts')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('fleet-assets/alerts/index')
                    ->where('hero.unresolved', 1)
                    ->where('control_room_alerts.meta.total', 1)
                    ->where('control_room_alerts.data.0.id', $localAlert->id)
                    ->has('archived_asset_alerts', 1)
                    ->where('archived_asset_alerts.0.id', $localArchivedAlert->id)
                );

            $this->actingAs($manager)
                ->get('/fleet-assets/alerts?asset_id='.$foreignAsset->id)
                ->assertForbidden();
        }
    }

    public function test_alert_asset_filter_uses_authoritative_site_then_home_site_then_client_provenance(): void
    {
        $localSite = Site::factory()->create(['tenant_id' => 31]);
        $foreignSite = Site::factory()->create(['tenant_id' => 32]);
        $localClient = Client::factory()->create([
            'organization_id' => 31,
            'site_id' => $localSite->id,
        ]);
        $foreignClientUsingLocalSite = Client::factory()->create([
            'organization_id' => 32,
            'site_id' => $localSite->id,
        ]);
        $manager = $this->tenantUser(31, ['assets.alerts.view', 'fleet.manage']);

        $directLocal = Asset::factory()->vehicle()->create([
            'site_id' => $localSite->id,
            'home_site_id' => $foreignSite->id,
            'client_id' => null,
        ]);
        $directLocalWithForeignClient = Asset::factory()->vehicle()->create([
            'site_id' => $localSite->id,
            'home_site_id' => null,
            'client_id' => $foreignClientUsingLocalSite->id,
        ]);
        $directForeignWithLocalFallbacks = Asset::factory()->vehicle()->create([
            'site_id' => $foreignSite->id,
            'home_site_id' => $localSite->id,
            'client_id' => $localClient->id,
        ]);
        $localHomeFallback = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $localSite->id,
            'client_id' => null,
        ]);
        $localHomeWithForeignClient = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $localSite->id,
            'client_id' => $foreignClientUsingLocalSite->id,
        ]);
        $foreignHomeWithLocalClientFallback = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $foreignSite->id,
            'client_id' => $localClient->id,
        ]);
        $localClientFallback = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => $localClient->id,
        ]);
        $foreignClientWithLocalSite = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => $foreignClientUsingLocalSite->id,
        ]);
        $unattributed = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => null,
        ]);

        foreach ([$directLocal, $localHomeFallback, $localClientFallback] as $asset) {
            $this->actingAs($manager)
                ->get('/fleet-assets/alerts?asset_id='.$asset->id)
                ->assertOk();
        }

        foreach ([
            $directForeignWithLocalFallbacks,
            $directLocalWithForeignClient,
            $foreignHomeWithLocalClientFallback,
            $localHomeWithForeignClient,
            $foreignClientWithLocalSite,
            $unattributed,
        ] as $asset) {
            $this->actingAs($manager)
                ->get('/fleet-assets/alerts?asset_id='.$asset->id)
                ->assertForbidden();
        }
    }

    public function test_handover_vehicle_options_are_tenant_bounded_and_use_authoritative_asset_provenance(): void
    {
        $localSite = Site::factory()->create(['tenant_id' => 41]);
        $foreignSite = Site::factory()->create(['tenant_id' => 42]);
        $localClient = Client::factory()->create([
            'organization_id' => 41,
            'site_id' => $localSite->id,
        ]);
        $foreignClientUsingLocalSite = Client::factory()->create([
            'organization_id' => 42,
            'site_id' => $localSite->id,
        ]);
        $manager = $this->tenantUser(41, ['fleet.viewAny', 'fleet.manage']);

        $directLocal = Asset::factory()->vehicle()->create([
            'site_id' => $localSite->id,
            'home_site_id' => $foreignSite->id,
            'client_id' => $foreignClientUsingLocalSite->id,
        ]);
        Asset::factory()->vehicle()->create([
            'site_id' => $foreignSite->id,
            'home_site_id' => $localSite->id,
            'client_id' => $localClient->id,
        ]);
        $localHomeFallback = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $localSite->id,
            'client_id' => $foreignClientUsingLocalSite->id,
        ]);
        Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $foreignSite->id,
            'client_id' => $localClient->id,
        ]);
        Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => $localClient->id,
        ]);
        Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => $foreignClientUsingLocalSite->id,
        ]);
        Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => null,
        ]);

        $expectedIds = collect([$directLocal->id, $localHomeFallback->id])
            ->sort()
            ->values()
            ->all();

        $this->actingAs($manager)
            ->get('/fleet-assets/handovers?new=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/handovers/index')
                ->where('vehicles', fn ($vehicles) => collect($vehicles)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all() === $expectedIds)
                ->where('wizard.vehicles', fn ($vehicles) => collect($vehicles)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all() === $expectedIds)
            );
    }

    public function test_explicit_platform_admin_with_bypass_permission_can_use_installation_wide_fleet_asset_options(): void
    {
        $firstSite = Site::factory()->create(['tenant_id' => 51]);
        $secondSite = Site::factory()->create(['tenant_id' => 52]);
        $firstVehicle = Asset::factory()->vehicle()->create(['site_id' => $firstSite->id]);
        $secondVehicle = Asset::factory()->vehicle()->create(['site_id' => $secondSite->id]);
        $platformAdmin = $this->platformAdmin([
            'assets.alerts.view',
            'fleet.viewAny',
            'fleet.manage',
        ]);

        $this->actingAs($platformAdmin)
            ->get('/fleet-assets/alerts?asset_id='.$secondVehicle->id)
            ->assertOk();

        $expectedIds = collect([$firstVehicle->id, $secondVehicle->id])->sort()->values()->all();

        $this->actingAs($platformAdmin)
            ->get('/fleet-assets/handovers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('vehicles', fn ($vehicles) => collect($vehicles)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all() === $expectedIds)
            );
    }

    public function test_handover_records_follow_direct_site_before_home_site_for_lists_details_and_acceptance(): void
    {
        $localSite = Site::factory()->create(['tenant_id' => 61]);
        $foreignSite = Site::factory()->create(['tenant_id' => 62]);
        $manager = $this->tenantUser(61, ['fleet.viewAny', 'fleet.manage']);
        $foreignParticipant = User::factory()->create(['organization_id' => 62]);
        $poisonedVehicle = Asset::factory()->vehicle()->create([
            'site_id' => $foreignSite->id,
            'home_site_id' => $localSite->id,
        ]);

        $hidden = FleetShiftHandover::query()->create([
            'asset_id' => $poisonedVehicle->id,
            'outgoing_user_id' => $foreignParticipant->id,
            'incoming_user_id' => $foreignParticipant->id,
            'exterior_condition' => 'good',
            'interior_condition' => 'clean',
            'status' => 'pending_acceptance',
            'handed_over_at' => now(),
        ]);
        $participantAttempt = FleetShiftHandover::query()->create([
            'asset_id' => $poisonedVehicle->id,
            'outgoing_user_id' => $foreignParticipant->id,
            'incoming_user_id' => $manager->id,
            'exterior_condition' => 'good',
            'interior_condition' => 'clean',
            'status' => 'pending_acceptance',
            'handed_over_at' => now(),
        ]);

        $this->actingAs($manager)
            ->get('/fleet-assets/handovers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('handovers.meta.total', 0)
                ->where('stats.total', 0));

        $this->actingAs($manager)
            ->get("/fleet-assets/handovers/{$hidden->id}")
            ->assertForbidden();
        $this->actingAs($manager)
            ->post("/fleet-assets/handovers/{$participantAttempt->id}/accept")
            ->assertForbidden();

        $this->assertSame('pending_acceptance', $participantAttempt->fresh()->status);
        $this->assertNull($participantAttempt->fresh()->accepted_at);
    }

    public function test_fleet_alert_lists_and_bulk_actions_reject_a_foreign_client_on_a_local_alert_site(): void
    {
        $localSite = Site::factory()->create(['tenant_id' => 71]);
        $foreignClient = Client::factory()->create([
            'organization_id' => 72,
            'site_id' => $localSite->id,
        ]);
        $manager = $this->tenantUser(71, [
            'assets.alerts.view',
            'fleet.manage',
            'controlRoom.alerts.manage',
        ]);
        $poisonedAlert = ControlRoomAlert::factory()->fromFleet()->open()->create([
            'site_id' => $localSite->id,
            'client_id' => $foreignClient->id,
        ]);

        $this->actingAs($manager)
            ->get('/fleet-assets/alerts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('hero.unresolved', 0)
                ->where('control_room_alerts.meta.total', 0));

        $this->actingAs($manager)
            ->post('/fleet-assets/alerts/bulk-action', [
                'action' => 'acknowledge',
                'ids' => [$poisonedAlert->id],
            ])
            ->assertForbidden();

        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $poisonedAlert->fresh()->status);
    }

    public function test_fleet_alert_list_redacts_a_conflicting_nested_asset_and_its_location_context(): void
    {
        $localSite = Site::factory()->create(['tenant_id' => 81]);
        $foreignSite = Site::factory()->create(['tenant_id' => 82]);
        $foreignAsset = Asset::factory()->vehicle()->create([
            'site_id' => $foreignSite->id,
            'name' => 'Foreign confidential vehicle',
            'asset_tag' => 'FOREIGN-SECRET',
        ]);
        $manager = $this->tenantUser(81, ['assets.alerts.view', 'fleet.manage']);
        $alert = ControlRoomAlert::factory()->fromFleet()->open()->create([
            'site_id' => $localSite->id,
            'asset_id' => $foreignAsset->id,
            'context' => [
                'safe_operator_note' => 'Keep this lifecycle note.',
                'fleet_context' => ['vehicle_name' => 'Foreign confidential vehicle'],
                'latitude' => -36.8485,
                'longitude' => 174.7633,
                'normalized_data' => [
                    'asset_id' => $foreignAsset->id,
                    'fleet_context' => ['asset_tag' => 'FOREIGN-SECRET'],
                    'coordinates' => [-36.8485, 174.7633],
                ],
            ],
        ]);

        $this->actingAs($manager)
            ->get('/fleet-assets/alerts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('control_room_alerts.meta.total', 1)
                ->where('control_room_alerts.data.0.id', $alert->id)
                ->where('control_room_alerts.data.0.asset', null)
                ->where('control_room_alerts.data.0.context.safe_operator_note', 'Keep this lifecycle note.')
                ->missing('control_room_alerts.data.0.context.fleet_context')
                ->missing('control_room_alerts.data.0.context.latitude')
                ->missing('control_room_alerts.data.0.context.longitude')
                ->missing('control_room_alerts.data.0.context.normalized_data.asset_id')
                ->missing('control_room_alerts.data.0.context.normalized_data.fleet_context')
                ->missing('control_room_alerts.data.0.context.normalized_data.coordinates'));
    }

    /** @param array<int, string> $permissionKeys */
    private function tenantUser(int $organizationId, array $permissionKeys): User
    {
        $user = User::factory()->create([
            'organization_id' => $organizationId,
            'approved_at' => now(),
            'role' => 'manager',
        ]);

        $this->grantPermissions($user, $permissionKeys);

        return $user;
    }

    /** @param array<int, string> $permissionKeys */
    private function platformAdmin(array $permissionKeys): User
    {
        $user = User::factory()->create([
            'organization_id' => null,
            'approved_at' => now(),
            'role' => 'admin',
        ]);
        $user->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->grantPermissions($user, $permissionKeys);

        return $user;
    }

    /** @param array<int, string> $permissionKeys */
    private function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = collect($permissionKeys)
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
    }
}
