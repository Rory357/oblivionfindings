<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\FleetVehicleGeofenceAssignment;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ClientLocationWorkspaceFixture;
use Tests\TestCase;

class Pkg07BoundariesTest extends TestCase
{
    use RefreshDatabase;

    private function user(Site $site, bool $manage = true): User
    {
        $u = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
        HrEmployeeProfile::factory()->create(['user_id' => $u->id, 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => today()->subYear(), 'end_date' => null]);
        foreach (['fleet.viewAny', 'assets.viewAny', ...($manage ? ['assets.geofences.manage'] : [])] as $key) {
            $p = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'test', 'module' => 'Test']);
            $u->permissionOverrides()->attach($p->id, ['allowed' => true]);
        }

        return $u;
    }

    private function payload(Site $site): array
    {
        return ['name' => 'Shared library', 'address' => 'Library entrance', 'site_id' => $site->id,
            'geometry' => ['type' => 'circle', 'center' => ['lat' => -41.29, 'lng' => 174.77], 'radius_m' => 180],
            'uses' => ['Vehicles', 'Assets'], 'verified' => true, 'reason' => 'Verified the public entrance.', 'request_key' => (string) Str::uuid()];
    }

    public function test_versioned_writes_are_scoped_retry_safe_and_keep_original_evidence(): void
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $foreign = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $u = $this->user($site);
        $outsider = $this->user($foreign);
        $reader = $this->user($site, false);
        $data = $this->payload($site);
        $id = $this->actingAs($u)->postJson('/fleet-assets/geofences', $data)->assertCreated()->json('boundary.id');
        $this->assertDatabaseHas('asset_geofences', ['id' => $id, 'is_active' => false, 'geometry_version' => 1, 'revision' => 1]);
        $this->postJson('/fleet-assets/geofences', $data)->assertCreated()->assertJsonPath('boundary.id', $id);
        $this->postJson('/fleet-assets/geofences', array_replace($data, ['name' => 'Conflicting retry']))->assertConflict();
        $this->postJson('/fleet-assets/geofences', array_replace($data, ['request_key' => (string) Str::uuid(), 'site_id' => $foreign->id]))->assertNotFound();
        $edit = array_replace($data, ['name' => 'Shared library east entrance', 'expected_revision' => 1, 'request_key' => (string) Str::uuid()]);
        $this->putJson('/fleet-assets/geofences/'.$id, $edit)->assertOk()->assertJsonPath('boundary.geometry_version', 1)->assertJsonPath('boundary.revision', 2);
        $this->putJson('/fleet-assets/geofences/'.$id, array_replace($edit, ['request_key' => (string) Str::uuid()]))->assertConflict();
        $edit['expected_revision'] = 2;
        $edit['request_key'] = (string) Str::uuid();
        $edit['geometry']['radius_m'] = 220;
        $this->putJson('/fleet-assets/geofences/'.$id, $edit)->assertOk()->assertJsonPath('boundary.geometry_version', 2);
        $this->getJson('/fleet-assets/geofences/'.$id.'/versions/1')->assertOk()->assertJsonPath('version.snapshot.geometry.radius_m', 180);
        $this->getJson('/fleet-assets/geofences/'.$id.'/history')->assertOk()->assertJsonCount(3, 'data');
        $this->get('/fleet-assets/geofences/'.$id.'/history/export')->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->actingAs($outsider)->getJson('/fleet-assets/geofences/'.$id)->assertNotFound();
        $this->getJson('/fleet-assets/geofences/'.$id.'/history')->assertNotFound();
        $this->getJson('/fleet-assets/geofences/catalogue?q=library')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/fleet-assets/geofences/summary?site_id='.$site->id)->assertOk()->assertJsonPath('boundaries', 0)->assertJsonPath('rules', 0)->assertJsonPath('follow_up', 0);
        $this->actingAs($reader)->getJson('/fleet-assets/geofences/'.$id.'/history')->assertOk();
        $this->deleteJson('/fleet-assets/geofences/'.$id, ['expected_revision' => 3, 'reason' => 'Remove area'])->assertForbidden();
        $this->get('/fleet-assets/geofences/'.$id.'/history/export')->assertForbidden();
        $this->actingAs($u)->deleteJson('/fleet-assets/geofences/'.$id, ['expected_revision' => 3, 'reason' => 'No longer needed.'])->assertOk();
        $this->assertDatabaseHas('asset_geofences', ['id' => $id, 'revision' => 4]);
        $this->assertSame(4, DB::table('boundary_versions')->where('boundary_id', $id)->count());
        $this->assertDatabaseCount('fleet_signals', 0);
        $this->getJson('/fleet-assets/geofences/catalogue')->assertJsonPath('total', 0);
        $this->getJson('/fleet-assets/geofences/catalogue?status=retired')->assertJsonPath('total', 1);
    }

    public function test_scale_search_rule_proposals_and_dependency_checks_share_the_canonical_register(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $u = $this->user($site);
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create(['client_id' => null]);
        $payload = $this->payload($site);
        $b = $this->actingAs($u)->postJson('/fleet-assets/geofences', $payload)->assertCreated()->json('boundary');
        $now = now();
        $rows = [];
        for ($i = 0; $i < 125; $i++) {
            $rows[] = ['site_id' => $site->id, 'name' => sprintf('Area %03d', $i), 'type' => 'circle', 'scope' => 'site', 'shape' => json_encode(['center' => ['lat' => -41.29, 'lng' => 174.77], 'radius_m' => 80]), 'is_active' => false, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('asset_geofences')->insert($rows);
        $this->getJson('/fleet-assets/geofences/catalogue?size=12&page=11')->assertOk()->assertJsonPath('total', 126)->assertJsonCount(6, 'data');
        $this->getJson('/fleet-assets/geofences/catalogue?q=Area%20124')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/fleet-assets/geofences/catalogue?size=5000')->assertUnprocessable();
        $rule = ['asset_id' => $vehicle->id, 'boundary_id' => $b['id'], 'boundary_revision' => 1, 'label' => 'Library arrival', 'purpose' => 'Review arrival notification proposal.', 'reason' => 'Initial operating proposal.', 'request_key' => (string) Str::uuid(), 'policy' => ['direction' => 'entry', 'timing' => 'unconfigured', 'windows' => []]];
        $id = $this->postJson('/fleet-assets/geofences/rules', $rule)->assertCreated()->json('id');
        $this->assertDatabaseHas('fleet_vehicle_geofence_assignments', ['id' => $id, 'monitoring' => 'inactive']);
        $this->assertDatabaseCount('asset_geofence_assignments', 0);
        $this->assertDatabaseCount('fleet_signals', 0);
        $this->postJson('/fleet-assets/geofences/rules', $rule)->assertCreated()->assertJsonPath('id', $id);
        $this->deleteJson('/fleet-assets/geofences/'.$b['id'], ['expected_revision' => 1, 'reason' => 'Retire while linked'])->assertConflict();
        $edit = array_replace($payload, ['expected_revision' => 1, 'request_key' => (string) Str::uuid()]);
        $edit['geometry']['radius_m'] = 200;
        $this->putJson('/fleet-assets/geofences/'.$b['id'], $edit)->assertOk();
        $this->getJson('/fleet-assets/geofences/rules')->assertJsonPath('data.0.source_changed', true);
        $this->getJson('/fleet-assets/geofences/summary?site_id='.$site->id)->assertOk()->assertJsonPath('boundaries', 126)->assertJsonPath('rules', 1)->assertJsonPath('review', 1);
        $this->getJson('/fleet-assets/geofences/rules?review=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $id);
        $this->assertEquals(180, FleetVehicleGeofenceAssignment::findOrFail($id)->geometry_snapshot['radius_m']);
        $this->getJson('/fleet-assets/geofences/resources')->assertOk()->assertJsonPath('data.0.position', null);
        $this->getJson('/fleet-assets/geofences/'.$b['id'].'/rule-history')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson('/fleet-assets/geofences/rules/'.$id, ['expected_version' => 1, 'reason' => 'Replace with another proposal.'])->assertOk();
        $this->assertSame(2, DB::table('boundary_rule_versions')->where('assignment_id', $id)->count());
        $this->deleteJson('/fleet-assets/geofences/'.$b['id'], ['expected_revision' => 2, 'reason' => 'Unused area retired.'])->assertOk();
    }

    public function test_client_handoff_is_opaque_and_rechecks_consent_and_source_versions(): void
    {
        $f = ClientLocationWorkspaceFixture::make();
        foreach (['fleet.viewAny', 'assets.geofences.manage'] as $key) {
            $p = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'test', 'module' => 'Test']);
            $f['actor']->permissionOverrides()->attach($p->id, ['allowed' => true]);
        }
        $u = $f['actor']->fresh();
        $base = '/operations/clients/'.$f['client']->id.'/location/zones/boundary-handoff';
        $handoff = $this->actingAs($u)->postJson($base, ['access_fingerprint' => $f['fingerprint']])->assertOk()->json();
        $this->assertSame('/fleet-assets/geofences?handoff='.$handoff['token'].'&tab=boundaries', $handoff['href']);
        $context = $this->getJson('/fleet-assets/geofences/handoffs/'.$handoff['token'])->assertOk();
        $this->assertArrayNotHasKey('client_id', $context->json());
        $data = $this->payload($f['site']);
        $data['handoff_token'] = $handoff['token'];
        $b = $this->postJson('/fleet-assets/geofences', $data)->assertCreated()->assertJsonPath('boundary.personal_eligible', true)->json('boundary');
        $this->postJson('/fleet-assets/geofences/handoffs/'.$handoff['token'].'/return', ['boundary_id' => $b['id'], 'revision' => 1])->assertOk();
        $this->getJson($base.'/'.$handoff['token'])->assertOk()->assertJsonPath('boundary.id', $b['id'])->assertJsonPath('ready', true);
        $this->assertDatabaseCount('client_geofence_rules', 0);
        $this->assertDatabaseCount('client_geofence_monitors', 0);
        $this->postJson('/fleet-assets/geofences/handoffs/'.$handoff['token'].'/return', ['boundary_id' => $b['id'], 'revision' => 1])->assertConflict();
        $cancelled = $this->postJson($base, ['access_fingerprint' => $f['fingerprint']])->assertOk()->json('token');
        $this->deleteJson($base.'/'.$cancelled)->assertOk();
        $this->getJson('/fleet-assets/geofences/handoffs/'.$cancelled)->assertNotFound();
        $other = $this->user($f['site']);
        $this->actingAs($other)->getJson('/fleet-assets/geofences/handoffs/'.$handoff['token'])->assertNotFound();
        $this->actingAs($u);
        $edit = array_replace($data, ['expected_revision' => 1, 'request_key' => (string) Str::uuid()]);
        $edit['geometry']['radius_m'] = 190;
        $this->putJson('/fleet-assets/geofences/'.$b['id'], $edit)->assertOk();
        $this->getJson($base.'/'.$handoff['token'])->assertConflict();
        $f['consent']->forceFill(['status' => 'withdrawn', 'withdrawn_at' => now()])->save();
        $this->getJson($base.'/'.$handoff['token'])->assertForbidden();
    }

    public function test_protected_geometry_and_public_geocoding_cannot_be_bypassed(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $u = $this->user($site);
        $b = AssetGeofence::create(['site_id' => $site->id, 'name' => 'Protected site area', 'type' => 'circle', 'scope' => 'house', 'shape' => ['center' => ['lat' => -41.29, 'lng' => 174.77], 'radius_m' => 100], 'is_active' => true]);
        $data = array_replace($this->payload($site), ['expected_revision' => 1]);
        $this->actingAs($u)->putJson('/fleet-assets/geofences/'.$b->id, $data)->assertConflict();
        $this->postJson('/fleet-assets/geofences/'.$b->id.'/legacy-links', ['action' => 'pause', 'expected_revision' => 1, 'reason' => 'Attempt pause'])->assertConflict();
        $this->deleteJson('/fleet-assets/geofences/'.$b->id, ['expected_revision' => 1, 'reason' => 'Attempt retirement'])->assertConflict();
        config(['fleet.maps.boundary_address_search_enabled' => true, 'fleet.maps.address_search_autocomplete' => true, 'fleet.maps.address_search_endpoint' => 'https://nominatim.openstreetmap.org']);
        Http::preventStrayRequests();
        $this->postJson('/fleet-assets/geofences/address-search', ['q' => 'Private address'])->assertStatus(503);
        $this->assertDatabaseCount('fleet_signals', 0);
    }

    public function test_submitted_public_address_search_returns_coordinates_caches_results_and_requires_management_access(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $manager = $this->user($site);
        $reader = $this->user($site, false);
        config(['fleet.maps.boundary_address_search_enabled' => true, 'fleet.maps.address_search_autocomplete' => false,
            'fleet.maps.address_search_endpoint' => 'https://nominatim.openstreetmap.org', 'fleet.maps.address_search_cache_store' => 'database']);
        Http::preventStrayRequests();
        Http::fake(['nominatim.openstreetmap.org/search*' => Http::response([[
            'display_name' => 'Parliament, Wellington, New Zealand', 'lat' => '-41.2784', 'lon' => '174.7767',
            'address' => ['road' => 'Molesworth Street', 'city' => 'Wellington', 'country' => 'New Zealand'],
        ]])]);
        $query = 'Public landmark '.Str::uuid();
        $this->actingAs($reader)->postJson('/fleet-assets/geofences/address-search', ['q' => $query])->assertForbidden();
        Http::assertNothingSent();
        $this->actingAs($manager)->postJson('/fleet-assets/geofences/address-search', ['q' => $query])->assertOk()
            ->assertJsonPath('results.0.display_name', 'Parliament, Wellington, New Zealand')
            ->assertJsonPath('results.0.lat', -41.2784)->assertJsonPath('results.0.lng', 174.7767);
        $this->postJson('/fleet-assets/geofences/address-search', ['q' => $query])->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['q'] === $query && $request['countrycodes'] === 'nz'
            && $request->hasHeader('User-Agent') && ! isset($request['client_id']));
        config(['fleet.maps.boundary_address_search_enabled' => false]);
        $this->postJson('/fleet-assets/geofences/address-search', ['q' => $query])->assertStatus(503);
        Http::assertSentCount(1);
    }

    public function test_schedule_windows_and_rule_retry_retain_only_valid_reviewed_proposals(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $u = $this->user($site);
        $asset = Asset::factory()->vehicle()->forSite($site)->create(['client_id' => null]);
        $b = $this->actingAs($u)->postJson('/fleet-assets/geofences', $this->payload($site))->assertCreated()->json('boundary');
        $schedule = ['timezone' => 'Pacific/Auckland', 'weekdays' => [1, 2], 'start' => '22:00', 'end' => '06:00', 'following_day' => true,
            'first_date' => '2026-09-28', 'last_date' => '2026-10-31', 'exception_dates' => ['2026-10-05']];
        $rule = ['asset_id' => $asset->id, 'boundary_id' => $b['id'], 'boundary_revision' => 1, 'label' => 'Overnight review', 'purpose' => 'Night arrival proposal',
            'reason' => 'Review overnight schedule.', 'request_key' => (string) Str::uuid(), 'schedule' => $schedule,
            'policy' => ['direction' => 'entry', 'timing' => 'scheduled', 'windows' => [['start' => '22:00', 'end' => '06:00', 'following_day' => true]]]];
        $bad = $rule;
        $bad['policy']['windows'][] = ['start' => '05:00', 'end' => '07:00', 'following_day' => false];
        $this->postJson('/fleet-assets/geofences/rules', $bad)->assertUnprocessable();
        $id = $this->postJson('/fleet-assets/geofences/rules', $rule)->assertCreated()->json('id');
        $rule['expected_version'] = 1;
        $rule['request_key'] = (string) Str::uuid();
        $rule['label'] = 'Reviewed overnight rule';
        $this->putJson('/fleet-assets/geofences/rules/'.$id, $rule)->assertOk();
        $this->putJson('/fleet-assets/geofences/rules/'.$id, $rule)->assertOk();
        $this->assertSame(2, DB::table('boundary_rule_versions')->where('assignment_id', $id)->count());
        $this->getJson('/fleet-assets/geofences/rules?id='.$id)->assertJsonPath('data.0.revision', 2)->assertJsonPath('data.0.schedule.following_day', true);
    }
}
