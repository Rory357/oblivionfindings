<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AssetAlert;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\FleetShiftHandover;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FleetSiteAccessIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_ordinary_fleet_viewers_only_see_alerts_and_filters_for_their_current_hr_sites(): void
    {
        $localSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $localAsset = Asset::factory()->vehicle()->create(['site_id' => $localSite->id]);
        $otherAsset = Asset::factory()->vehicle()->create(['site_id' => $otherSite->id]);

        $localAlert = ControlRoomAlert::factory()->fromFleet()->open()->create([
            'site_id' => $localSite->id,
            'asset_id' => $localAsset->id,
        ]);
        ControlRoomAlert::factory()->fromFleet()->open()->create([
            'site_id' => $otherSite->id,
            'asset_id' => $otherAsset->id,
        ]);

        $localArchivedAlert = AssetAlert::query()->create([
            'asset_id' => $localAsset->id,
            'alert_type' => 'Local archived fleet alert',
            'severity' => 'medium',
            'status' => 'resolved',
            'triggered_at' => now()->subHour(),
        ]);
        AssetAlert::query()->create([
            'asset_id' => $otherAsset->id,
            'alert_type' => 'Other Site archived fleet alert',
            'severity' => 'high',
            'status' => 'resolved',
            'triggered_at' => now(),
        ]);

        $viewer = $this->siteUser($localSite, ['assets.alerts.view']);

        $this->actingAs($viewer)
            ->get('/fleet-assets/alerts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/alerts/index')
                ->where('hero.unresolved', 1)
                ->where('control_room_alerts.meta.total', 1)
                ->where('control_room_alerts.data.0.id', $localAlert->id)
                ->has('archived_asset_alerts', 1)
                ->where('archived_asset_alerts.0.id', $localArchivedAlert->id));

        $this->actingAs($viewer)
            ->get('/fleet-assets/alerts?asset_id='.$otherAsset->id)
            ->assertForbidden();
    }

    public function test_alert_asset_filter_requires_one_supported_site_provenance_path(): void
    {
        $localSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $localClient = Client::factory()->create(['site_id' => $localSite->id]);
        $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
        $viewer = $this->siteUser($localSite, ['assets.alerts.view']);

        $directLocal = Asset::factory()->vehicle()->create([
            'site_id' => $localSite->id,
            'home_site_id' => $otherSite->id,
            'client_id' => null,
        ]);
        $directLocalWithMatchingClient = Asset::factory()->vehicle()->create([
            'site_id' => $localSite->id,
            'home_site_id' => null,
            'client_id' => $localClient->id,
        ]);
        $directOtherWithLocalFallbacks = Asset::factory()->vehicle()->create([
            'site_id' => $otherSite->id,
            'home_site_id' => $localSite->id,
            'client_id' => $localClient->id,
        ]);
        $directLocalWithConflictingClient = Asset::factory()->vehicle()->create([
            'site_id' => $localSite->id,
            'home_site_id' => null,
            'client_id' => $otherClient->id,
        ]);
        $localHomeFallback = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $localSite->id,
            'client_id' => null,
        ]);
        $localHomeWithMatchingClient = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $localSite->id,
            'client_id' => $localClient->id,
        ]);
        $otherHomeWithLocalClient = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $otherSite->id,
            'client_id' => $localClient->id,
        ]);
        $localHomeWithConflictingClient = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $localSite->id,
            'client_id' => $otherClient->id,
        ]);
        $localClientFallback = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => $localClient->id,
        ]);
        $otherClientFallback = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => $otherClient->id,
        ]);
        $unattributed = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => null,
        ]);

        foreach ([
            $directLocal,
            $directLocalWithMatchingClient,
            $localHomeFallback,
            $localHomeWithMatchingClient,
            $localClientFallback,
        ] as $asset) {
            $this->actingAs($viewer)
                ->get('/fleet-assets/alerts?asset_id='.$asset->id)
                ->assertOk();
        }

        foreach ([
            $directOtherWithLocalFallbacks,
            $directLocalWithConflictingClient,
            $otherHomeWithLocalClient,
            $localHomeWithConflictingClient,
            $otherClientFallback,
            $unattributed,
        ] as $asset) {
            $this->actingAs($viewer)
                ->get('/fleet-assets/alerts?asset_id='.$asset->id)
                ->assertForbidden();
        }
    }

    public function test_handover_vehicle_options_use_direct_site_before_home_site_and_fail_closed_without_provenance(): void
    {
        $localSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $viewer = $this->siteUser($localSite, ['fleet.viewAny']);

        $directLocal = Asset::factory()->vehicle()->create([
            'site_id' => $localSite->id,
            'home_site_id' => $otherSite->id,
        ]);
        Asset::factory()->vehicle()->create([
            'site_id' => $otherSite->id,
            'home_site_id' => $localSite->id,
        ]);
        $localHomeFallback = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $localSite->id,
        ]);
        Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $otherSite->id,
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

        $this->actingAs($viewer)
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
                    ->all() === $expectedIds));
    }

    public function test_explicit_fleet_management_permission_provides_application_wide_active_site_options(): void
    {
        $firstSite = Site::factory()->create();
        $secondSite = Site::factory()->create();
        $firstVehicle = Asset::factory()->vehicle()->create(['site_id' => $firstSite->id]);
        $secondVehicle = Asset::factory()->vehicle()->create(['site_id' => $secondSite->id]);
        $fleetManager = $this->siteUser($firstSite, [
            'assets.alerts.view',
            'fleet.viewAny',
            'fleet.manage',
        ]);

        $this->actingAs($fleetManager)
            ->get('/fleet-assets/alerts?asset_id='.$secondVehicle->id)
            ->assertOk();

        $expectedIds = collect([$firstVehicle->id, $secondVehicle->id])->sort()->values()->all();

        $this->actingAs($fleetManager)
            ->get('/fleet-assets/handovers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('vehicles', fn ($vehicles) => collect($vehicles)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all() === $expectedIds));
    }

    public function test_handover_records_use_direct_asset_site_and_require_current_site_eligible_participants(): void
    {
        $localSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $viewer = $this->siteUser($localSite, ['fleet.viewAny']);
        $otherOutgoing = $this->siteUser($otherSite);
        $otherIncoming = $this->siteUser($otherSite);
        $asset = Asset::factory()->vehicle()->create([
            'site_id' => $otherSite->id,
            'home_site_id' => $localSite->id,
        ]);

        $hidden = $this->handover($asset, $otherOutgoing, $otherIncoming);
        $ineligibleParticipantAttempt = $this->handover($asset, $otherOutgoing, $viewer);

        $this->actingAs($viewer)
            ->get('/fleet-assets/handovers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('handovers.meta.total', 0)
                ->where('stats.total', 0));

        $this->actingAs($viewer)
            ->get("/fleet-assets/handovers/{$hidden->id}")
            ->assertForbidden();
        $this->actingAs($viewer)
            ->post("/fleet-assets/handovers/{$ineligibleParticipantAttempt->id}/accept")
            ->assertForbidden();

        $this->assertSame('pending_acceptance', $ineligibleParticipantAttempt->fresh()->status);
        $this->assertNull($ineligibleParticipantAttempt->fresh()->accepted_at);
    }

    public function test_alert_counts_and_bulk_actions_follow_the_direct_alert_site(): void
    {
        $localSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $localClient = Client::factory()->create(['site_id' => $localSite->id]);
        $viewer = $this->siteUser($localSite, [
            'assets.alerts.view',
            'controlRoom.alerts.manage',
        ]);
        $otherSiteAlert = ControlRoomAlert::factory()->fromFleet()->open()->create([
            'site_id' => $otherSite->id,
            'client_id' => $localClient->id,
        ]);

        $this->actingAs($viewer)
            ->get('/fleet-assets/alerts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('hero.unresolved', 0)
                ->where('control_room_alerts.meta.total', 0));

        $this->actingAs($viewer)
            ->post('/fleet-assets/alerts/bulk-action', [
                'action' => 'acknowledge',
                'ids' => [$otherSiteAlert->id],
            ])
            ->assertForbidden();

        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $otherSiteAlert->fresh()->status);
    }

    public function test_alert_list_redacts_a_conflicting_nested_asset_and_location_context(): void
    {
        $localSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $otherAsset = Asset::factory()->vehicle()->create([
            'site_id' => $otherSite->id,
            'name' => 'Restricted vehicle',
            'asset_tag' => 'RESTRICTED-ASSET',
        ]);
        $viewer = $this->siteUser($localSite, ['assets.alerts.view']);
        $alert = ControlRoomAlert::factory()->fromFleet()->open()->create([
            'site_id' => $localSite->id,
            'asset_id' => $otherAsset->id,
            'context' => [
                'safe_operator_note' => 'Keep this lifecycle note.',
                'fleet_context' => ['vehicle_name' => 'Restricted vehicle'],
                'latitude' => -36.8485,
                'longitude' => 174.7633,
                'normalized_data' => [
                    'asset_id' => $otherAsset->id,
                    'fleet_context' => ['asset_tag' => 'RESTRICTED-ASSET'],
                    'coordinates' => [-36.8485, 174.7633],
                ],
            ],
        ]);

        $this->actingAs($viewer)
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
    private function siteUser(Site $site, array $permissionKeys = []): User
    {
        $user = User::factory()->create([
            'approved_at' => now(),
            'role' => 'manager',
        ]);

        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->grantPermissions($user, $permissionKeys);

        return $user;
    }

    private function handover(Asset $asset, User $outgoing, User $incoming): FleetShiftHandover
    {
        return FleetShiftHandover::query()->create([
            'asset_id' => $asset->id,
            'outgoing_user_id' => $outgoing->id,
            'incoming_user_id' => $incoming->id,
            'exterior_condition' => 'good',
            'interior_condition' => 'clean',
            'status' => 'pending_acceptance',
            'handed_over_at' => now(),
        ]);
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
