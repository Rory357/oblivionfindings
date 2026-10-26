<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Support\NotInThisBuild;

/** Prospective real GET acceptance for UNAPPLIED H&S Safeguarding consumer, not a passing claim. */
final class HealthSafetyDashboardSafeguardingReadTest extends TestCase
{
    use RefreshDatabase;
    private Site $site;
    private Site $otherSite;
    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp(); $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]); $this->otherSite = Site::factory()->create(['is_active' => true]);
        $this->viewer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        HrEmployeeProfile::factory()->create(['user_id' => $this->viewer->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);
        $this->grant('hazards.view');
    }
    private function grant(string $key, bool $allowed = true): void
    {
        $this->viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]);
    }
    private function concern(?Site $site, string $status = 'reported'): void
    {
        SafeguardingConcern::factory()->create(['site_id' => $site?->id, 'status' => $status, 'is_sensitive' => true,
            'description' => 'PRIVATE-SAFEGUARDING', 'reported_at' => '2020-01-01 12:00:00']);
    }
    private function dashboard(?int $site = null): array
    {
        return $this->actingAs($this->viewer)->get('/health-safety'.($site ? '?site='.$site : ''), ['X-Inertia' => 'true', 'Accept' => 'application/json',
            'X-Inertia-Partial-Component' => 'health-safety/dashboard', 'X-Inertia-Partial-Data' => 'kpis,safeguarding_source,filters'])
            ->assertOk()->json('props');
    }

    public function test_hazards_only_get_denies_source_counts_and_link_without_any_concern_sql(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::NEVER_BUILT, 'the Overview\'s safeguarding source read (the count only with safeguarding.viewAny, plus a safeguarding_source link) was never built; the count stays as on main.', 'safeguarding count privacy on the Overview (logged for Stephan).'));
        $this->concern($this->site); DB::enableQueryLog(); DB::flushQueryLog();
        try { $props = $this->dashboard(); $sql = implode(' ', array_column(DB::getQueryLog(), 'query')); }
        finally { DB::disableQueryLog(); }
        $this->assertNull($props['kpis']['open_safeguarding']);
        $this->assertSame(['access' => 'restricted', 'href' => null], $props['safeguarding_source']);
        $this->assertStringNotContainsString('safeguarding_concerns', $sql);
        $this->assertStringNotContainsString('PRIVATE-SAFEGUARDING', json_encode($props, JSON_THROW_ON_ERROR));
    }

    public function test_actual_selected_site_open_count_link_matches_canonical_register_and_masking_is_not_a_gate(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::NEVER_BUILT, 'the Overview\'s safeguarding source read (the count only with safeguarding.viewAny, plus a safeguarding_source link) was never built; the count stays as on main.', 'safeguarding count privacy on the Overview (logged for Stephan).'));
        $this->grant('safeguarding.viewAny'); $this->grant('safeguarding.viewSensitive', false);
        $this->concern($this->site); $this->concern($this->site, 'monitoring'); $this->concern($this->site, 'closed');
        $this->concern($this->site, 'no_action_required'); $this->concern($this->otherSite); $this->concern(null);
        $props = $this->dashboard(); $this->assertSame(2, $props['kpis']['open_safeguarding']);
        $href = '/safeguarding?tab=open&site_id='.$this->site->id;
        $this->assertSame(['access' => 'readable', 'href' => $href], $props['safeguarding_source']);
        $this->actingAs($this->viewer)->get($href)->assertOk()->assertInertia(fn ($page) => $page
            ->where('rows.total', 2)->where('tab', 'open')->where('rows.data.0.restricted', true));
    }

    public function test_hs_all_sites_does_not_imply_reports_bypass_or_view_and_selected_nondefault_scope_is_exact(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::NEVER_BUILT, 'the Overview\'s safeguarding source read (the count only with safeguarding.viewAny, plus a safeguarding_source link) was never built; the count stays as on main.', 'safeguarding count privacy on the Overview (logged for Stephan).'));
        $this->grant('healthSafety.viewAllSites'); $this->grant('safeguarding.viewAny');
        $this->concern($this->site); $this->concern($this->otherSite); $this->concern($this->otherSite);
        $this->assertSame(1, $this->dashboard()['kpis']['open_safeguarding']);
        $this->assertNull($this->dashboard($this->otherSite->id)['kpis']['open_safeguarding']);
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['secondary_site_ids' => [$this->otherSite->id]]);
        $this->assertSame(2, $this->dashboard($this->otherSite->id)['kpis']['open_safeguarding']);
        $this->grant('reports.viewAny'); $this->assertSame(3, $this->dashboard()['kpis']['open_safeguarding']);
        $this->assertNull($this->dashboard()['safeguarding_source']['href'], 'No exact multi-Site filter exists on the current register.');
    }

    public function test_warmed_source_grant_withdraw_restore_and_readable_zero_do_not_reuse_prior_counts(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::NEVER_BUILT, 'the Overview\'s safeguarding source read (the count only with safeguarding.viewAny, plus a safeguarding_source link) was never built; the count stays as on main.', 'safeguarding count privacy on the Overview (logged for Stephan).'));
        $this->grant('safeguarding.viewAny'); $this->concern($this->site); $this->viewer->load('roles.permissions', 'permissionOverrides');
        $this->assertSame(1, $this->dashboard()['kpis']['open_safeguarding']);
        $this->grant('safeguarding.viewAny', false); $this->assertNull($this->dashboard()['kpis']['open_safeguarding']);
        $this->grant('safeguarding.viewAny'); $this->assertSame(1, $this->dashboard()['kpis']['open_safeguarding']);
        SafeguardingConcern::query()->delete(); $this->assertSame(0, $this->dashboard()['kpis']['open_safeguarding']);
    }
}
