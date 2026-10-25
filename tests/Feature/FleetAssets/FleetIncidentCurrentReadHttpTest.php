<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\FleetIncident;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\NotInThisBuild;
use Tests\TestCase;

/** Prospective whole HTTP acceptance for the UNAPPLIED Fleet extraction; all checks UNRUN. */
final class FleetIncidentCurrentReadHttpTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;
    private Site $foreignSite;
    private User $viewer;
    private Asset $asset;
    private Asset $foreignAsset;
    private const WINDOW = ['occurred_from' => '2026-10-11T14:00:00+13:00', 'occurred_to' => '2026-10-12T14:00:00+13:00'];

    protected function setUp(): void
    {
        parent::setUp(); $this->seed(RbacSeeder::class); $this->seed(SeedHrPermissionsSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->foreignSite = Site::factory()->create(['is_active' => true]);
        $this->viewer = $this->staff($this->site, 'Fleet local viewer');
        $this->grant('fleet.viewAny');
        $this->asset = Asset::factory()->vehicle()->create(['site_id' => $this->site->id, 'home_site_id' => $this->foreignSite->id, 'name' => 'Fleet local van']);
        $this->foreignAsset = Asset::factory()->vehicle()->create(['site_id' => $this->foreignSite->id, 'home_site_id' => $this->site->id, 'name' => 'Fleet foreign van']);
    }

    private function staff(Site $site, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);
        return $user;
    }

    private function grant(string $key, bool $allowed = true): void
    {
        $permission = Permission::where('key', $key)->sole();
        $this->viewer->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
    }

    private function incident(Asset $asset, string $at, array $facts = []): FleetIncident
    {
        return FleetIncident::factory()->create(['asset_id' => $asset->id, 'reported_by_user_id' => $this->viewer->id,
            'driver_user_id' => $this->viewer->id, 'occurred_at' => $at, 'status' => 'reported', 'severity' => 'minor',
            'incident_type' => 'damage', 'description' => 'Retained Fleet report', ...$facts]);
    }

    private function indexUrl(array $filters = []): string { return '/fleet-assets/incidents?'.http_build_query($filters); }

    public function test_actual_register_counts_csv_and_filters_use_exact_rolling_interval(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::OTHER_MODULE, 'The Fleet register (main\'s IncidentController, unchanged here) has no occurred_from/occurred_to window; it exists only in Codex\'s unapplied FleetIncidentReadScope extraction. The foreign-Site row is already withheld.', 'apply the Fleet register extraction.'));
        $lower = $this->incident($this->asset, '2026-10-11 01:00:00', ['description' => 'Lower endpoint admitted']);
        $inside = $this->incident($this->asset, '2026-10-12 00:59:59', ['description' => 'Inside interval admitted']);
        $this->incident($this->asset, '2026-10-11 00:59:59', ['description' => 'Earlier occurrence withheld']);
        $this->incident($this->asset, '2026-10-12 01:00:00', ['description' => 'Upper endpoint withheld']);
        $this->incident($this->foreignAsset, '2026-10-11 12:00:00', ['description' => 'Foreign occurrence withheld']);
        $this->actingAs($this->viewer)->get($this->indexUrl(self::WINDOW))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('fleet-assets/incidents/index')->where('incidents.meta.total', 2)->where('tabCounts.all', 2)->where('stats.reported', 2)
            ->where('incidents.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$inside->id, $lower->id])
            ->where('filters.occurred_from', '2026-10-11T01:00:00.000000Z')->where('filters.occurred_to', '2026-10-12T01:00:00.000000Z'));
        $csv = $this->actingAs($this->viewer)->get($this->indexUrl([...self::WINDOW, 'export' => 'csv']))->assertOk()->streamedContent();
        foreach (['Lower endpoint admitted', 'Inside interval admitted'] as $value) { $this->assertStringContainsString($value, $csv); }
        foreach (['Earlier occurrence withheld', 'Upper endpoint withheld', 'Foreign occurrence withheld'] as $value) { $this->assertStringNotContainsString($value, $csv); }
    }

    public function test_actual_pagination_keeps_filters_and_detail_modal_remains_independent_of_list_filters(): void
    {
        for ($i = 0; $i < 26; $i++) { $this->incident($this->asset, '2026-10-11 12:00:00', ['location' => 'Harbour filtered']); }
        $outside = $this->incident($this->asset, '2026-10-10 12:00:00');
        $this->actingAs($this->viewer)->get($this->indexUrl([...self::WINDOW, 'search' => 'Harbour', 'page' => 2, 'incident' => $outside->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('incidents.meta.total', 26)->where('incidents.meta.current_page', 2)
                ->has('incidents.data', 1)->where('detail.id', $outside->id)
                ->where('incidents.links', fn ($links) => collect($links)->filter(fn ($link) => $link['url'] !== null)->every(fn ($link) => str_contains($link['url'], 'occurred_from=') && str_contains($link['url'], 'search=Harbour'))));
    }

    public function test_legacy_civil_filters_and_scalar_fields_still_intersect_the_additive_window(): void
    {
        $hit = $this->incident($this->asset, '2026-10-11 12:00:00', ['location' => 'Retained quay', 'status' => 'investigating', 'severity' => 'major', 'incident_type' => 'collision']);
        $this->incident($this->asset, '2026-10-12 00:30:00', ['location' => 'Retained quay']);
        $this->actingAs($this->viewer)->get($this->indexUrl([...self::WINDOW, 'date_from' => '2026-10-11', 'date_to' => '2026-10-11',
            'vehicle_id' => $this->asset->id, 'driver_id' => $this->viewer->id, 'site_id' => $this->site->id, 'search' => 'Retained quay',
            'status' => 'investigating', 'severity' => 'major', 'incident_type' => 'collision', 'tab' => 'under_investigation']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('incidents.meta.total', 1)->where('incidents.data.0.id', $hit->id)
                ->where('filters.date_from', '2026-10-11')->where('tab', 'under_investigation')->where('stats.investigating', 1));
    }

    public function test_actual_route_refuses_invalid_missing_duplicate_shape_or_reversed_window(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::OTHER_MODULE, 'The Fleet register ignores the occurred_* parameters, as on main; their validation exists only in Codex\'s unapplied FleetIncidentReadScope extraction.', 'apply the Fleet register extraction.'));
        foreach ([['occurred_from' => '2026-10-11 01:00:00', 'occurred_to' => self::WINDOW['occurred_to']],
            ['occurred_from' => self::WINDOW['occurred_from']], ['occurred_from' => [self::WINDOW['occurred_from']], 'occurred_to' => self::WINDOW['occurred_to']],
            ['occurred_from' => self::WINDOW['occurred_to'], 'occurred_to' => self::WINDOW['occurred_from']]] as $bad) {
            $this->actingAs($this->viewer)->getJson($this->indexUrl($bad))->assertUnprocessable();
        }
    }

    public function test_actual_detail_site_movement_and_legacy_deep_link_revoke_restore_with_warmed_actor(): void
    {
        $row = $this->incident($this->asset, '2026-10-11 12:00:00'); $row->load('asset'); $this->viewer->load('hrEmployeeProfile', 'permissionOverrides');
        $this->actingAs($this->viewer)->getJson('/fleet-assets/incidents/'.$row->id)->assertOk()->assertJsonPath('incident.id', $row->id);
        $this->asset->update(['site_id' => $this->foreignSite->id]);
        $this->actingAs($this->viewer)->getJson('/fleet-assets/incidents/'.$row->id)->assertNotFound();
        $this->actingAs($this->viewer)->get($this->indexUrl(['incident' => $row->id]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('detail', null));
        $this->asset->update(['site_id' => $this->site->id]);
        $this->actingAs($this->viewer)->get('/fleet-assets/incidents/'.$row->id)->assertRedirect(route('fleet-assets.incidents.index', ['incident' => $row->id]));
        // A real request loads the user fresh; the reused object keeps its first Site list (UserSiteAccessService keys its cache on the object).
        $this->site->update(['is_active' => false]); $this->actingAs($this->viewer->fresh())->getJson('/fleet-assets/incidents/'.$row->id)->assertNotFound();
        $this->site->update(['is_active' => true]); $this->actingAs($this->viewer->fresh())->getJson('/fleet-assets/incidents/'.$row->id)->assertOk();
    }

    public function test_actual_read_permission_and_display_flags_withdraw_restore_without_action_grant_fallback(): void
    {
        $row = $this->incident($this->asset, '2026-10-11 12:00:00'); $this->grant('fleet.incidents.manage'); $this->grant('hr.assets.view');
        $this->viewer->load('permissionOverrides');
        $url = '/fleet-assets/incidents/'.$row->id;
        $this->actingAs($this->viewer)->getJson($url)->assertOk()->assertJsonPath('incident.can.manage', true)->assertJsonPath('incident.can.view_hr_assets', true);
        $this->grant('fleet.incidents.manage', false); $this->grant('hr.assets.view', false);
        // Each later request acts as a freshly loaded user, as a real request does.
        $this->actingAs($this->viewer->fresh())->getJson($url)->assertOk()->assertJsonPath('incident.can.manage', false)->assertJsonPath('incident.can.view_hr_assets', false);
        $this->grant('fleet.viewAny', false); $this->grant('assets.viewAny', false); $this->grant('fleet.incidents.manage');
        $this->actingAs($this->viewer->fresh())->getJson($url)->assertForbidden();
        $this->grant('assets.viewAny'); $this->actingAs($this->viewer->fresh())->getJson($url)->assertOk();
    }

    public function test_exact_foreign_filter_and_hs_bypass_do_not_become_fleet_authority(): void
    {
        $row = $this->incident($this->foreignAsset, '2026-10-11 12:00:00'); $this->grant('healthSafety.viewAllSites'); $this->grant('fleet.incidents.manage');
        $this->actingAs($this->viewer)->get($this->indexUrl(['site_id' => $this->foreignSite->id]))->assertNotFound();
        $this->actingAs($this->viewer)->getJson('/fleet-assets/incidents/'.$row->id)->assertNotFound();
        $this->grant('fleet.manage');
        $this->actingAs($this->viewer->fresh())->getJson('/fleet-assets/incidents/'.$row->id)->assertOk();
    }

    public function test_actual_search_picker_retains_scalar_shape_and_excludes_foreign_or_inactive_people(): void
    {
        $local = $this->staff($this->site, 'Fleet picker driver'); $this->staff($this->foreignSite, 'Fleet picker foreign');
        $inactive = $this->staff($this->site, 'Fleet picker inactive'); HrEmployeeProfile::where('user_id', $inactive->id)->update(['is_active' => false]);
        $users = $this->actingAs($this->viewer)->getJson('/fleet-assets/incidents/options/search?type=users&q=Fleet%20picker')->assertOk()->json('results');
        // Rows also carry User's appended avatar fields; the people listed are what this checks.
        $this->assertSame([['id' => $local->id, 'name' => 'Fleet picker driver']], array_map(fn (array $user): array => ['id' => $user['id'], 'name' => $user['name']], $users));
        $assets = $this->actingAs($this->viewer)->getJson('/fleet-assets/incidents/options/search?type=assets&q=Fleet')->assertOk()->json('results');
        $this->assertSame([$this->asset->id], array_column($assets, 'id'));
        $this->assertSame(['id', 'name', 'registration_number', 'category'], array_keys($assets[0]));
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $this->actingAs($this->viewer->fresh())->getJson('/fleet-assets/incidents/options/search?type=users&q=Fleet%20picker')->assertOk()->assertJsonPath('results.0.name', 'Fleet picker foreign');
    }

    public function test_actual_csv_preserves_all_columns_and_existing_formula_injection_guard(): void
    {
        // Pinned: Faker addresses can contain a line break, and this test splits the CSV on new lines.
        $this->incident($this->asset, '2026-10-11 12:00:00', ['description' => '=2+2', 'location' => 'Depot yard']);
        $csv = $this->actingAs($this->viewer)->get($this->indexUrl([...self::WINDOW, 'export' => 'csv']))->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $header = str_getcsv($lines[0]); $row = str_getcsv($lines[1]);
        $this->assertCount(15, $header); $this->assertCount(15, $row);
        $this->assertSame('Ref', $header[0]); $this->assertSame('Description', $header[14]); $this->assertSame("'=2+2", $row[14]);
    }
}
