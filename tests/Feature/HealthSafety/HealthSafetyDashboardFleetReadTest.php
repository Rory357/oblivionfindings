<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\FleetIncident;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Actual mounted H&S dashboard composition, no fake Fleet service; whole suite UNRUN. */
final class HealthSafetyDashboardFleetReadTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;
    private Site $otherSite;
    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp(); $this->travelTo(Carbon::parse('2026-10-12T12:00:00Z')); $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->otherSite = Site::factory()->create(['is_active' => true]);
        $this->viewer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        HrEmployeeProfile::factory()->create(['user_id' => $this->viewer->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);
        $this->grant('hazards.view');
    }

    private function grant(string $key, bool $allowed = true): void
    {
        $permission = Permission::where('key', $key)->sole();
        $this->viewer->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
    }

    private function occurrence(?Site $site, ?Site $home = null, ?string $at = null, string $status = 'reported'): FleetIncident
    {
        $asset = Asset::factory()->vehicle()->create(['site_id' => $site?->id, 'home_site_id' => $home?->id, 'name' => 'Recorded Fleet asset']);
        return FleetIncident::factory()->create(['asset_id' => $asset->id, 'reported_by_user_id' => $this->viewer->id,
            'driver_user_id' => $this->viewer->id, 'occurred_at' => $at ?? now()->subDay()->utc()->format('Y-m-d H:i:s'),
            'status' => $status, 'severity' => 'minor', 'incident_type' => 'damage', 'description' => 'PRIVATE-FLEET-DESCRIPTION']);
    }

    private function dashboard(?int $siteId = null): array
    {
        $url = '/health-safety'.($siteId !== null ? '?site='.$siteId : '');
        return $this->actingAs($this->viewer)->get($url, ['X-Inertia' => 'true', 'Accept' => 'application/json',
            'X-Inertia-Partial-Component' => 'health-safety/dashboard', 'X-Inertia-Partial-Data' => 'kpis,filters'])
            ->assertOk()->json('props');
    }

    private function recordedQueries(callable $read): array
    {
        DB::enableQueryLog(); DB::flushQueryLog();
        try { $result = $read(); $sql = implode(' ', array_column(DB::getQueryLog(), 'query')); }
        finally { DB::disableQueryLog(); }
        return [$result, $sql];
    }

    public function test_hazards_only_dashboard_keeps_fleet_counts_restricted_without_fleet_sql_or_private_echo(): void
    {
        $this->occurrence($this->site);
        [$props, $sql] = $this->recordedQueries(fn () => $this->dashboard());
        $this->assertNull($props['kpis']['fleet_incidents_30d']); $this->assertNull($props['kpis']['fleet_unresolved']);
        $this->assertStringNotContainsString('fleet_incidents', $sql);
        $this->assertStringNotContainsString('PRIVATE-FLEET-DESCRIPTION', json_encode($props, JSON_THROW_ON_ERROR));
    }

    public function test_actual_dashboard_uses_fleet_read_and_asset_site_never_home_site_or_null_site(): void
    {
        $this->grant('fleet.viewAny');
        $this->occurrence($this->site, $this->otherSite); $this->occurrence($this->otherSite, $this->site); $this->occurrence(null, $this->site);
        $props = $this->dashboard();
        $this->assertSame(1, $props['kpis']['fleet_incidents_30d']); $this->assertSame(1, $props['kpis']['fleet_unresolved']);
        $this->assertSame($this->site->id, $props['filters']['site']);
    }

    public function test_hs_all_sites_and_incident_management_do_not_widen_fleet_scope_but_actual_fleet_bypass_does(): void
    {
        $this->grant('fleet.viewAny'); $this->grant('healthSafety.viewAllSites'); $this->grant('fleet.incidents.manage');
        $this->occurrence($this->site); $this->occurrence($this->otherSite);
        $this->assertSame(1, $this->dashboard()['kpis']['fleet_incidents_30d']);
        [$foreign, $sql] = $this->recordedQueries(fn () => $this->dashboard($this->otherSite->id));
        $this->assertNull($foreign['kpis']['fleet_incidents_30d']); $this->assertNull($foreign['kpis']['fleet_unresolved']);
        $this->assertStringNotContainsString('fleet_incidents', $sql);
        $this->grant('fleet.manage'); $this->assertSame(2, $this->dashboard()['kpis']['fleet_incidents_30d']);
        $this->assertSame(1, $this->dashboard($this->otherSite->id)['kpis']['fleet_unresolved']);
    }

    public function test_explicit_nondefault_site_selection_intersects_hs_and_fleet_admission(): void
    {
        $this->grant('assets.viewAny');
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['secondary_site_ids' => [$this->otherSite->id]]);
        $this->occurrence($this->site); $this->occurrence($this->otherSite); $this->occurrence($this->otherSite);
        $default = $this->dashboard(); $selected = $this->dashboard($this->otherSite->id);
        $this->assertSame(1, $default['kpis']['fleet_incidents_30d']);
        $this->assertSame($this->otherSite->id, $selected['filters']['site']);
        $this->assertSame(2, $selected['kpis']['fleet_incidents_30d']); $this->assertSame(2, $selected['kpis']['fleet_unresolved']);
    }

    public function test_actual_dashboard_preserves_old_unresolved_and_exact_rolling_endpoints_excludes_future(): void
    {
        $this->grant('fleet.viewAny');
        $lower = Carbon::now()->subDays(30)->utc(); $upper = Carbon::now()->utc();
        $this->occurrence($this->site, null, $lower->format('Y-m-d H:i:s'));
        $this->occurrence($this->site, null, $upper->copy()->subSecond()->format('Y-m-d H:i:s'), 'investigating');
        $this->occurrence($this->site, null, $lower->copy()->subDays(10)->format('Y-m-d H:i:s'));
        $this->occurrence($this->site, null, $lower->copy()->subSecond()->format('Y-m-d H:i:s'), 'closed');
        $this->occurrence($this->site, null, $upper->format('Y-m-d H:i:s'), 'closed');
        $this->occurrence($this->site, null, $upper->copy()->addDay()->format('Y-m-d H:i:s'), 'closed');
        $props = $this->dashboard();
        $this->assertSame(2, $props['kpis']['fleet_incidents_30d']);
        $this->assertSame(3, $props['kpis']['fleet_unresolved'], 'Older reported occurrence remains unresolved now outside the rolling window.');
    }

    public function test_warmed_fleet_read_withdraw_restore_keeps_dashboard_permitted_and_truthful_zero_distinct(): void
    {
        $this->grant('fleet.viewAny'); $this->occurrence($this->site); $this->viewer->load('roles.permissions', 'permissionOverrides');
        $this->assertSame(1, $this->dashboard()['kpis']['fleet_incidents_30d']);
        $this->grant('fleet.viewAny', false); $this->grant('assets.viewAny', false);
        [$denied, $sql] = $this->recordedQueries(fn () => $this->dashboard());
        $this->assertNull($denied['kpis']['fleet_incidents_30d']); $this->assertNull($denied['kpis']['fleet_unresolved']);
        $this->assertStringNotContainsString('fleet_incidents', $sql);
        $this->grant('assets.viewAny'); $this->assertSame(1, $this->dashboard()['kpis']['fleet_incidents_30d']);
        FleetIncident::query()->delete();
        $empty = $this->dashboard(); $this->assertSame(0, $empty['kpis']['fleet_incidents_30d']); $this->assertSame(0, $empty['kpis']['fleet_unresolved']);
    }

    public function test_warmed_fleet_site_withdraw_restore_does_not_borrow_hs_bypass(): void
    {
        $this->grant('fleet.viewAny'); $this->grant('healthSafety.viewAllSites'); $this->occurrence($this->site);
        $this->viewer->load('hrEmployeeProfile'); $this->assertSame(1, $this->dashboard($this->site->id)['kpis']['fleet_unresolved']);
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['primary_site_id' => $this->otherSite->id]);
        [$denied, $sql] = $this->recordedQueries(fn () => $this->dashboard($this->site->id));
        $this->assertNull($denied['kpis']['fleet_incidents_30d']); $this->assertStringNotContainsString('fleet_incidents', $sql);
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['primary_site_id' => $this->site->id]);
        $this->assertSame(1, $this->dashboard($this->site->id)['kpis']['fleet_unresolved']);
    }
}
