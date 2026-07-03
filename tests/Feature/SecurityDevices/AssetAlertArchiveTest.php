<?php

namespace Tests\Feature\SecurityDevices;

use App\Models\Asset;
use App\Models\AssetAlert;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetAlertArchiveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    }

    public function test_legacy_asset_alert_archive_redirects_and_legacy_action_routes_are_removed(): void
    {
        $asset = Asset::factory()->create(['name' => 'Archive Asset']);
        $alert = $this->createAssetAlert($asset, [
            'status' => 'open',
        ]);

        // The standalone archive page was retired; `/fleet-assets/alerts`
        // renders the archived asset alerts inline (see test below).
        $this->actingAs($this->admin)
            ->get('/assets/alerts')
            ->assertRedirect('/fleet-assets/alerts');

        $this->actingAs($this->admin)
            ->post("/assets/alerts/{$alert->id}/acknowledge")
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->post("/assets/alerts/{$alert->id}/resolve")
            ->assertNotFound();
    }

    public function test_legacy_asset_detail_redirects_to_fleet_assets(): void
    {
        $asset = Asset::factory()->create(['name' => 'Asset Detail Archive']);

        $this->actingAs($this->admin)
            ->get("/assets/{$asset->id}")
            ->assertRedirect("/fleet-assets/assets/{$asset->id}");
    }

    public function test_fleet_alert_index_keeps_archived_asset_alerts_separate_from_operational_alerts(): void
    {
        $asset = Asset::factory()->create(['name' => 'Fleet Alert Asset']);

        ControlRoomAlert::create([
            'source' => 'asset',
            'alert_type' => 'speed_violation',
            'severity' => 'critical',
            'status' => 'open',
            'asset_id' => $asset->id,
            'triggered_at' => now()->subMinute(),
            'context' => ['source' => 'control_room'],
        ]);

        $legacyAlert = $this->createAssetAlert($asset, [
            'alert_type' => 'geofence',
            'severity' => 'medium',
            'status' => 'resolved',
        ]);

        $response = $this->actingAs($this->admin)->get('/fleet-assets/alerts');

        $response->assertOk();
        $response->assertInertia(function ($page) use ($legacyAlert) {
            $props = $page->toArray()['props'];

            $this->assertCount(1, $props['control_room_alerts']['data']);
            $this->assertCount(1, $props['archived_asset_alerts']);
            $this->assertSame($legacyAlert->id, $props['archived_asset_alerts'][0]['id']);
            $this->assertSame('resolved', $props['archived_asset_alerts'][0]['status']);
        });
    }

    public function test_fleet_asset_detail_exposes_archived_alert_history_under_archived_alerts_key(): void
    {
        $asset = Asset::factory()->vehicle()->create(['name' => 'Fleet Archive Asset']);
        $this->createAssetAlert($asset, [
            'alert_type' => 'sos',
            'severity' => 'critical',
        ]);

        $response = $this->actingAs($this->admin)->get("/fleet-assets/assets/{$asset->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $asset = $page->toArray()['props']['asset'];

            $this->assertArrayHasKey('archived_alerts', $asset);
            $this->assertArrayNotHasKey('alerts', $asset);
            $this->assertCount(1, $asset['archived_alerts']);
            $this->assertSame('sos', $asset['archived_alerts'][0]['alert_type']);
        });
    }

    private function createAssetAlert(Asset $asset, array $overrides = []): AssetAlert
    {
        return AssetAlert::create(array_merge([
            'asset_id' => $asset->id,
            'asset_tracker_id' => null,
            'asset_alert_policy_id' => null,
            'alert_type' => 'tamper',
            'severity' => 'medium',
            'status' => 'open',
            'triggered_at' => now()->subMinutes(5),
            'context' => ['source' => 'legacy_asset_alert'],
        ], $overrides));
    }
}
