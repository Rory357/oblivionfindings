<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AssetAlert;
use App\Models\AssetGeofence;
use App\Models\Client;
use App\Models\ControlRoom\AlertQueue;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\Device;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomOperationalSurfaceSiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_escalation_board_and_mutations_are_limited_to_the_operators_sites(): void
    {
        [$siteA, $siteB, $operator] = $this->sitePairAndOperator([
            'controlRoom.viewAny',
            'controlRoom.alerts.manage',
            'controlRoom.alerts.assign',
        ]);
        $tier2 = TriageQueue::query()->create([
            'name' => 'Tier 2',
            'code' => 'site-isolation-tier-2',
            'tier' => 2,
            'is_active' => true,
        ]);
        $tier1 = TriageQueue::query()->create([
            'name' => 'Tier 1',
            'code' => 'site-isolation-tier-1',
            'tier' => 1,
            'escalate_to_queue_id' => $tier2->id,
            'is_active' => true,
        ]);
        $visible = ControlRoomAlert::factory()->open()->create([
            'site_id' => $siteA->id,
            'queue_id' => $tier1->id,
        ]);
        $hidden = ControlRoomAlert::factory()->open()->create([
            'site_id' => $siteB->id,
            'queue_id' => $tier1->id,
        ]);

        $this->actingAs($operator)
            ->get('/control-room/escalations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('queues.0.alert_count', 1)
                ->has('worklist.data', 1)
                ->where('worklist.data.0.id', $visible->id)
            );

        $this->actingAs($operator)
            ->post("/control-room/escalations/{$hidden->id}/acknowledge")
            ->assertForbidden();
        $this->actingAs($operator)
            ->post("/control-room/escalations/{$hidden->id}/assign-to-me")
            ->assertForbidden();
        $this->actingAs($operator)
            ->post("/control-room/escalations/{$hidden->id}/move", [
                'target_queue_id' => $tier2->id,
            ])
            ->assertForbidden();
        $this->actingAs($operator)
            ->post('/control-room/escalations/bulk-escalate', [
                'alert_ids' => [$visible->id, $hidden->id],
                'reason' => 'Attempted mixed-site escalation.',
            ])
            ->assertForbidden();

        $visible->refresh();
        $hidden->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $visible->status);
        $this->assertSame($tier1->id, $visible->queue_id);
        $this->assertNull($visible->assigned_to_user_id);
        $this->assertSame(0, (int) $visible->escalation_level);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $hidden->status);
        $this->assertSame($tier1->id, $hidden->queue_id);
        $this->assertNull($hidden->assigned_to_user_id);
        $this->assertSame(0, (int) $hidden->escalation_level);
    }

    public function test_direct_escalation_mutations_reject_terminal_alerts_without_rewriting_history(): void
    {
        [$site, , $operator] = $this->sitePairAndOperator([
            'controlRoom.alerts.manage',
            'controlRoom.alerts.assign',
        ]);
        $historicalAssignee = User::factory()->create();
        $tier2 = TriageQueue::query()->create([
            'name' => 'Terminal guard tier 2',
            'code' => 'terminal-guard-tier-2',
            'tier' => 2,
            'is_active' => true,
        ]);
        $tier1 = TriageQueue::query()->create([
            'name' => 'Terminal guard tier 1',
            'code' => 'terminal-guard-tier-1',
            'tier' => 1,
            'escalate_to_queue_id' => $tier2->id,
            'is_active' => true,
        ]);

        foreach (ControlRoomAlert::TERMINAL_STATUSES as $status) {
            $alert = ControlRoomAlert::factory()->create([
                'site_id' => $site->id,
                'status' => $status,
                'queue_id' => $tier1->id,
                'assigned_to_user_id' => $historicalAssignee->id,
                'assigned_at' => now()->subHours(2),
                'assigned_by_user_id' => $historicalAssignee->id,
                'escalation_level' => 2,
                'context' => ['history_marker' => "preserve-{$status}"],
            ]);
            AlertQueue::query()->create([
                'alert_id' => $alert->id,
                'queue_id' => $tier1->id,
                'entered_at' => now()->subHour(),
            ]);

            $this->actingAs($operator)
                ->post("/control-room/escalations/{$alert->id}/assign-to-me")
                ->assertSessionHasErrors('alert');
            $this->actingAs($operator)
                ->post("/control-room/escalations/{$alert->id}/move", [
                    'target_queue_id' => $tier2->id,
                ])
                ->assertSessionHasErrors('alert');

            $alert->refresh();
            $this->assertSame($status, $alert->status);
            $this->assertSame($tier1->id, $alert->queue_id);
            $this->assertSame($historicalAssignee->id, $alert->assigned_to_user_id);
            $this->assertSame(2, (int) $alert->escalation_level);
            $this->assertSame("preserve-{$status}", data_get($alert->context, 'history_marker'));
            $this->assertSame(1, AlertQueue::query()->where('alert_id', $alert->id)->count());
            $this->assertDatabaseHas('control_room_alert_queue', [
                'alert_id' => $alert->id,
                'queue_id' => $tier1->id,
                'exited_at' => null,
            ]);
        }
    }

    public function test_bulk_escalation_skips_terminal_alerts_and_only_mutates_actionable_work(): void
    {
        [$site, , $operator] = $this->sitePairAndOperator(['controlRoom.alerts.manage']);
        $tier2 = TriageQueue::query()->create([
            'name' => 'Bulk terminal guard tier 2',
            'code' => 'bulk-terminal-guard-tier-2',
            'tier' => 2,
            'is_active' => true,
        ]);
        $tier1 = TriageQueue::query()->create([
            'name' => 'Bulk terminal guard tier 1',
            'code' => 'bulk-terminal-guard-tier-1',
            'tier' => 1,
            'escalate_to_queue_id' => $tier2->id,
            'is_active' => true,
        ]);
        $actionable = ControlRoomAlert::factory()->open()->create([
            'site_id' => $site->id,
            'queue_id' => $tier1->id,
            'escalation_level' => 0,
        ]);
        AlertQueue::query()->create([
            'alert_id' => $actionable->id,
            'queue_id' => $tier1->id,
            'entered_at' => now()->subHour(),
        ]);

        $terminalAlerts = collect(ControlRoomAlert::TERMINAL_STATUSES)->map(function (string $status) use ($site, $tier1) {
            $alert = ControlRoomAlert::factory()->create([
                'site_id' => $site->id,
                'status' => $status,
                'queue_id' => $tier1->id,
                'escalation_level' => 3,
                'context' => ['history_marker' => "bulk-preserve-{$status}"],
            ]);
            AlertQueue::query()->create([
                'alert_id' => $alert->id,
                'queue_id' => $tier1->id,
                'entered_at' => now()->subHour(),
            ]);

            return $alert;
        });

        $this->actingAs($operator)
            ->post('/control-room/escalations/bulk-escalate', [
                'alert_ids' => $terminalAlerts->pluck('id')->prepend($actionable->id)->all(),
                'reason' => 'Escalate only actionable work.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $actionable->refresh();
        $this->assertSame($tier2->id, $actionable->queue_id);
        $this->assertSame(1, (int) $actionable->escalation_level);
        $this->assertSame(2, AlertQueue::query()->where('alert_id', $actionable->id)->count());

        foreach ($terminalAlerts as $terminalAlert) {
            $terminalAlert->refresh();
            $this->assertSame($tier1->id, $terminalAlert->queue_id);
            $this->assertSame(3, (int) $terminalAlert->escalation_level);
            $this->assertSame(
                "bulk-preserve-{$terminalAlert->status}",
                data_get($terminalAlert->context, 'history_marker'),
            );
            $this->assertSame(1, AlertQueue::query()->where('alert_id', $terminalAlert->id)->count());
            $this->assertDatabaseHas('control_room_alert_queue', [
                'alert_id' => $terminalAlert->id,
                'queue_id' => $tier1->id,
                'exited_at' => null,
            ]);
        }
    }

    public function test_stats_only_aggregate_alerts_from_the_operators_sites(): void
    {
        [$siteA, $siteB, $operator] = $this->sitePairAndOperator(['controlRoom.viewAny']);
        ControlRoomAlert::factory()->open()->create([
            'site_id' => $siteA->id,
            'source' => 'visible_site_source',
            'alert_type' => 'visible_site_type',
            'triggered_at' => now(),
        ]);
        ControlRoomAlert::factory()->open()->create([
            'site_id' => $siteB->id,
            'source' => 'hidden_site_source',
            'alert_type' => 'hidden_site_type',
            'triggered_at' => now(),
        ]);

        $this->actingAs($operator)
            ->get('/control-room/stats')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.open_alerts', 1)
                ->where('kpis.alerts_today', 1)
                ->where('top_sources', [[
                    'name' => 'visible_site_source',
                    'count' => 1,
                ]])
                ->where('top_alert_types', [[
                    'name' => 'visible_site_type',
                    'count' => 1,
                ]])
            );
    }

    public function test_live_map_limits_devices_sites_geofences_alerts_filters_and_counts_to_the_operators_sites(): void
    {
        [$siteA, $siteB, $operator] = $this->sitePairAndOperator(['controlRoom.viewAny']);
        $siteA->update(['latitude' => -36.8485, 'longitude' => 174.7633, 'is_active' => true]);
        $siteB->update(['latitude' => -41.2866, 'longitude' => 174.7756, 'is_active' => true]);
        $visibleDevice = Device::query()->create([
            'name' => 'Visible personal tracker',
            'type' => Device::TYPE_PERSONAL_TRACKER,
            'site_id' => $siteA->id,
            'latitude' => -36.8485,
            'longitude' => 174.7633,
            'status' => 'online',
        ]);
        Device::query()->create([
            'name' => 'Hidden personal tracker',
            'type' => Device::TYPE_PERSONAL_TRACKER,
            'site_id' => $siteB->id,
            'latitude' => -41.2866,
            'longitude' => 174.7756,
            'status' => 'offline',
        ]);
        $visibleGeofence = AssetGeofence::query()->create([
            'site_id' => $siteA->id,
            'name' => 'Visible boundary',
            'type' => 'circle',
            'scope' => 'vehicle',
            'shape' => ['center' => ['lat' => -36.8485, 'lng' => 174.7633], 'radius_m' => 500],
            'breach_type' => 'both',
            'is_active' => true,
        ]);
        AssetGeofence::query()->create([
            'site_id' => $siteB->id,
            'name' => 'Hidden boundary',
            'type' => 'circle',
            'scope' => 'vehicle',
            'shape' => ['center' => ['lat' => -41.2866, 'lng' => 174.7756], 'radius_m' => 500],
            'breach_type' => 'both',
            'is_active' => true,
        ]);
        $visibleAlert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $siteA->id,
            'device_id' => $visibleDevice->id,
        ]);
        ControlRoomAlert::factory()->open()->create(['site_id' => $siteB->id]);

        $this->actingAs($operator)
            ->get('/control-room/map')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('devices', 1)
                ->where('devices.0.id', $visibleDevice->id)
                ->has('sites', 1)
                ->where('sites.0.id', $siteA->id)
                ->has('geofences', 1)
                ->where('geofences.0.id', $visibleGeofence->id)
                ->has('alerts', 1)
                ->where('alerts.0.id', $visibleAlert->id)
                ->has('all_sites', 1)
                ->where('all_sites.0.id', $siteA->id)
                ->where('stats.total_devices', 1)
                ->where('stats.online', 1)
                ->where('stats.offline', 0)
                ->where('stats.active_alerts', 1)
            );

        $this->actingAs($operator)
            ->get('/control-room/map?site_id='.$siteB->id)
            ->assertForbidden();
    }

    public function test_live_map_uses_authoritative_device_and_geofence_site_precedence(): void
    {
        [$localSite, $foreignSite, $operator] = $this->sitePairAndOperator(['controlRoom.viewAny']);
        $localClient = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $localSite->id,
        ]);
        $foreignClient = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $foreignSite->id,
        ]);
        $localAsset = Asset::factory()->forSite($localSite)->create(['home_site_id' => $localSite->id]);
        $foreignAsset = Asset::factory()->forSite($foreignSite)->create(['home_site_id' => $foreignSite->id]);

        $recordSiteWins = Device::query()->create([
            'name' => 'Local record site with foreign fallbacks',
            'type' => Device::TYPE_PERSONAL_TRACKER,
            'site_id' => $localSite->id,
            'client_id' => $foreignClient->id,
            'asset_id' => $foreignAsset->id,
            'latitude' => -36.8010,
            'longitude' => 174.8010,
            'status' => 'online',
        ]);
        $foreignRecordCannotBeOverridden = Device::query()->create([
            'name' => 'Foreign record site with local fallbacks',
            'type' => Device::TYPE_PERSONAL_TRACKER,
            'site_id' => $foreignSite->id,
            'client_id' => $localClient->id,
            'asset_id' => $localAsset->id,
            'latitude' => -36.8020,
            'longitude' => 174.8020,
            'status' => 'online',
        ]);
        $clientWins = Device::query()->create([
            'name' => 'Local client with foreign asset fallback',
            'type' => Device::TYPE_PERSONAL_TRACKER,
            'site_id' => null,
            'client_id' => $localClient->id,
            'asset_id' => $foreignAsset->id,
            'latitude' => -36.8030,
            'longitude' => 174.8030,
            'status' => 'online',
        ]);
        $foreignClientCannotBeOverridden = Device::query()->create([
            'name' => 'Foreign client with local asset fallback',
            'type' => Device::TYPE_PERSONAL_TRACKER,
            'site_id' => null,
            'client_id' => $foreignClient->id,
            'asset_id' => $localAsset->id,
            'latitude' => -36.8040,
            'longitude' => 174.8040,
            'status' => 'online',
        ]);
        $assetFallback = Device::query()->create([
            'name' => 'Local asset-only fallback',
            'type' => Device::TYPE_PERSONAL_TRACKER,
            'site_id' => null,
            'client_id' => null,
            'asset_id' => $localAsset->id,
            'latitude' => -36.8050,
            'longitude' => 174.8050,
            'status' => 'online',
        ]);

        $recordSiteFence = AssetGeofence::query()->create([
            'site_id' => $localSite->id,
            'asset_id' => $foreignAsset->id,
            'name' => 'Local authoritative fence',
            'type' => 'circle',
            'scope' => 'vehicle',
            'shape' => ['center' => ['lat' => -36.80, 'lng' => 174.80], 'radius_m' => 100],
            'breach_type' => 'both',
            'is_active' => true,
        ]);
        $foreignFenceCannotBeOverridden = AssetGeofence::query()->create([
            'site_id' => $foreignSite->id,
            'asset_id' => $localAsset->id,
            'name' => 'Foreign authoritative fence',
            'type' => 'circle',
            'scope' => 'vehicle',
            'shape' => ['center' => ['lat' => -36.81, 'lng' => 174.81], 'radius_m' => 100],
            'breach_type' => 'both',
            'is_active' => true,
        ]);
        $assetFallbackFence = AssetGeofence::query()->create([
            'site_id' => null,
            'asset_id' => $localAsset->id,
            'name' => 'Local asset fallback fence',
            'type' => 'circle',
            'scope' => 'vehicle',
            'shape' => ['center' => ['lat' => -36.82, 'lng' => 174.82], 'radius_m' => 100],
            'breach_type' => 'both',
            'is_active' => true,
        ]);
        $localAlertWithForeignDevice = ControlRoomAlert::factory()->open()->create([
            'site_id' => $localSite->id,
            'device_id' => $foreignRecordCannotBeOverridden->id,
            'asset_id' => $foreignAsset->id,
        ]);

        $this->actingAs($operator)
            ->get('/control-room/map')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('devices', fn ($devices) => collect($devices)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all() === collect([$recordSiteWins->id, $clientWins->id, $assetFallback->id])
                    ->sort()
                    ->values()
                    ->all())
                ->where('devices', fn ($devices) => ! collect($devices)
                    ->pluck('id')
                    ->contains($foreignRecordCannotBeOverridden->id))
                ->where('devices', fn ($devices) => ! collect($devices)
                    ->pluck('id')
                    ->contains($foreignClientCannotBeOverridden->id))
                ->where('geofences', fn ($geofences) => collect($geofences)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all() === collect([$recordSiteFence->id, $assetFallbackFence->id])
                    ->sort()
                    ->values()
                    ->all())
                ->where('geofences', fn ($geofences) => ! collect($geofences)
                    ->pluck('id')
                    ->contains($foreignFenceCannotBeOverridden->id))
                ->has('alerts', 1)
                ->where('alerts.0.id', $localAlertWithForeignDevice->id)
                ->where('alerts.0.device_id', null)
                ->where('alerts.0.latitude', null)
                ->where('alerts.0.longitude', null)
                ->where('alerts.0.asset_name', null)
                ->where('stats.total_devices', 3)
            );
    }

    public function test_live_map_report_bypass_remains_tenant_scoped(): void
    {
        $localSite = Site::factory()->create([
            'tenant_id' => 1,
            'latitude' => -36.8500,
            'longitude' => 174.7500,
        ]);
        $outsideSite = Site::factory()->create([
            'tenant_id' => 202,
            'latitude' => -41.2800,
            'longitude' => 174.7700,
        ]);
        $operator = $this->siteBoundOperator($localSite, ['controlRoom.viewAny', 'reports.viewAny']);
        $localAsset = Asset::factory()->forSite($localSite)->create(['home_site_id' => $localSite->id]);
        $outsideAsset = Asset::factory()->forSite($outsideSite)->create(['home_site_id' => $outsideSite->id]);
        $localDevice = Device::query()->create([
            'name' => 'Tenant map tracker',
            'type' => Device::TYPE_PERSONAL_TRACKER,
            'site_id' => $localSite->id,
            'latitude' => -36.8500,
            'longitude' => 174.7500,
            'status' => 'online',
        ]);
        $outsideDevice = Device::query()->create([
            'name' => 'Outside tenant tracker',
            'type' => Device::TYPE_PERSONAL_TRACKER,
            'site_id' => $outsideSite->id,
            'latitude' => -41.2800,
            'longitude' => 174.7700,
            'status' => 'online',
        ]);
        $localFence = AssetGeofence::query()->create([
            'site_id' => null,
            'asset_id' => $localAsset->id,
            'name' => 'Tenant fallback fence',
            'type' => 'circle',
            'scope' => 'vehicle',
            'shape' => ['center' => ['lat' => -36.85, 'lng' => 174.75], 'radius_m' => 100],
            'breach_type' => 'both',
            'is_active' => true,
        ]);
        $outsideFence = AssetGeofence::query()->create([
            'site_id' => null,
            'asset_id' => $outsideAsset->id,
            'name' => 'Outside tenant fallback fence',
            'type' => 'circle',
            'scope' => 'vehicle',
            'shape' => ['center' => ['lat' => -41.28, 'lng' => 174.77], 'radius_m' => 100],
            'breach_type' => 'both',
            'is_active' => true,
        ]);

        $this->actingAs($operator)
            ->get('/control-room/map')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('devices', fn ($devices) => collect($devices)->pluck('id')->all() === [$localDevice->id])
                ->where('devices', fn ($devices) => ! collect($devices)->pluck('id')->contains($outsideDevice->id))
                ->where('geofences', fn ($geofences) => collect($geofences)->pluck('id')->all() === [$localFence->id])
                ->where('geofences', fn ($geofences) => ! collect($geofences)->pluck('id')->contains($outsideFence->id))
                ->where('all_sites', fn ($sites) => collect($sites)->pluck('id')->all() === [$localSite->id])
                ->where('stats.total_devices', 1)
            );

        $this->actingAs($operator)
            ->get('/control-room/map?site_id='.$outsideSite->id)
            ->assertForbidden();
    }

    public function test_sla_counts_and_breach_rows_are_limited_to_the_operators_sites(): void
    {
        [$siteA, $siteB, $operator] = $this->sitePairAndOperator(['controlRoom.viewAny']);
        $definition = SlaDefinition::query()->create([
            'name' => 'Site-scoped SLA',
            'code' => 'site-scoped-sla',
            'acknowledge_target_minutes' => 5,
            'is_active' => true,
        ]);
        $visible = ControlRoomAlert::factory()->open()->create(['site_id' => $siteA->id]);
        $hidden = ControlRoomAlert::factory()->open()->create(['site_id' => $siteB->id]);

        foreach ([$visible, $hidden] as $alert) {
            AlertSla::query()->create([
                'alert_id' => $alert->id,
                'sla_definition_id' => $definition->id,
                'acknowledge_deadline' => now()->subMinutes(10),
                'acknowledge_breached' => true,
                'first_breach_at' => now()->subMinutes(5),
            ]);
        }

        $this->actingAs($operator)
            ->get('/control-room/sla')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('slaDefinitions', 1)
                ->where('slaDefinitions.0.total_alerts', 1)
            );

        $this->actingAs($operator)
            ->get('/control-room/sla/breaches')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('breaches.data', 1)
                ->where('breaches.data.0.alert_id', $visible->id)
                ->where('stats.total', 1)
                ->where('stats.acknowledge', 1)
            );
    }

    public function test_fleet_alert_list_and_hero_are_limited_to_the_operators_sites(): void
    {
        [$siteA, $siteB, $operator] = $this->sitePairAndOperator([
            'assets.viewAny',
            'assets.alerts.view',
            'fleet.viewAny',
        ]);
        $visible = ControlRoomAlert::factory()->fromFleet()->open()->create([
            'site_id' => $siteA->id,
            'severity' => 'critical',
        ]);
        ControlRoomAlert::factory()->fromFleet()->open()->create([
            'site_id' => $siteB->id,
            'severity' => 'critical',
        ]);
        $visibleAsset = Asset::factory()->forSite($siteA)->create();
        $hiddenAsset = Asset::factory()->forSite($siteB)->create();
        $visibleArchived = AssetAlert::query()->create([
            'asset_id' => $visibleAsset->id,
            'alert_type' => 'archived_visible_site_alert',
            'severity' => 'medium',
            'status' => 'resolved',
            'triggered_at' => now()->subDay(),
        ]);
        AssetAlert::query()->create([
            'asset_id' => $hiddenAsset->id,
            'alert_type' => 'archived_hidden_site_alert',
            'severity' => 'medium',
            'status' => 'resolved',
            'triggered_at' => now()->subDay(),
        ]);

        $this->actingAs($operator)
            ->get('/fleet-assets/alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('control_room_alerts.data', 1)
                ->where('control_room_alerts.data.0.id', $visible->id)
                ->where('control_room_alerts.meta.total', 1)
                ->where('hero.unresolved', 1)
                ->where('hero.critical', 1)
                ->has('archived_asset_alerts', 1)
                ->where('archived_asset_alerts.0.id', $visibleArchived->id)
            );

        $globalFleetManager = $this->siteBoundOperator($siteA, [
            'assets.alerts.view',
            'fleet.viewAny',
            'fleet.manage',
        ]);
        $this->actingAs($globalFleetManager)
            ->get('/fleet-assets/alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('control_room_alerts.data', 2)
                ->where('control_room_alerts.meta.total', 2)
                ->where('hero.unresolved', 2)
                ->where('hero.critical', 2)
                ->has('archived_asset_alerts', 2)
            );
    }

    /**
     * @param  array<int, string>  $permissionKeys
     * @return array{Site, Site, User}
     */
    private function sitePairAndOperator(array $permissionKeys): array
    {
        $siteA = Site::factory()->create(['type' => 'house']);
        $siteB = Site::factory()->create(['type' => 'house']);
        $operator = $this->siteBoundOperator($siteA, $permissionKeys);

        return [$siteA, $siteB, $operator];
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    private function siteBoundOperator(Site $site, array $permissionKeys): User
    {
        $operator = User::factory()->create(['approved_at' => now()]);
        $permissions = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $operator->permissionOverrides()->sync(
            $permissions->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $operator->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $operator;
    }
}
