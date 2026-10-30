<?php

namespace Tests\Feature\Safeguarding;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Prospective whole acceptance for the UNAPPLIED canonical extraction; all checks UNRUN. */
final class SafeguardingCurrentReadHttpTest extends TestCase
{
    use RefreshDatabase;
    private Site $site;
    private Site $otherSite;
    private User $viewer;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp(); $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]); $this->otherSite = Site::factory()->create(['is_active' => true]);
        $this->viewer = $this->staff($this->site); $this->other = $this->staff($this->otherSite); $this->grant('safeguarding.viewAny');
    }
    private function staff(Site $site): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => '2020-01-01', 'end_date' => null, 'is_active' => true]); return $user;
    }
    private function grant(string $key, bool $allowed = true): void
    {
        $this->viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]);
    }
    private function concern(?Site $site, array $facts = []): SafeguardingConcern
    {
        return SafeguardingConcern::factory()->create(['site_id' => $site?->id, 'reported_by_user_id' => $this->other->id,
            'status' => 'reported', 'is_sensitive' => true, 'description' => 'Private source narrative', 'subject_name' => 'Private subject', ...$facts]);
    }

    public function test_register_hero_tabs_and_masked_rows_share_canonical_site_scope_including_organisation(): void
    {
        $local = $this->concern($this->site); $organisation = $this->concern(null); $foreign = $this->concern($this->otherSite);
        $this->actingAs($this->viewer)->get('/safeguarding?tab=triage')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('safeguarding/index')->where('rows.total', 2)->where('tabCounts.all', 2)->where('tabCounts.triage', 2)
            ->where('hero.openWork.open.value', 2)->where('rows.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$local->id, $organisation->id])->sort()->values()->all())
            ->where('rows.data.0.restricted', true)->where('rows.data.0.subject', null)->where('rows.data.0.description', null)
            ->has('sites')->has('subjects')->has('staff')->where('rowsKind', 'concerns'));
        $this->actingAs($this->viewer)->get('/safeguarding?concern='.$foreign->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('detail', null));
    }

    public function test_reports_bypass_and_sensitive_clearance_remain_distinct_from_hs_scope_and_count_authority(): void
    {
        $this->concern($this->site); $this->concern($this->otherSite); $this->grant('healthSafety.viewAllSites');
        $this->actingAs($this->viewer)->get('/safeguarding')->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 1)->where('rows.data.0.restricted', true));
        $this->grant('reports.viewAny');
        $this->actingAs($this->viewer)->get('/safeguarding')->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 2)->where('rows.data.0.restricted', true));
        $this->grant('safeguarding.viewSensitive');
        $this->actingAs($this->viewer)->get('/safeguarding')->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 2)->where('rows.data.0.restricted', false));
    }

    public function test_direct_reporter_and_assignee_detail_keep_original_access_without_register_permission(): void
    {
        $reported = $this->concern($this->site, ['reported_by_user_id' => $this->viewer->id]);
        $assigned = $this->concern(null, ['assigned_to_user_id' => $this->viewer->id]); $this->grant('safeguarding.viewAny', false);
        foreach ([$reported, $assigned] as $row) {
            $this->actingAs($this->viewer)->get('/safeguarding/'.$row->id.'?return_to='.urlencode('/my-day?tab=safety'))
                ->assertOk()->assertInertia(fn (Assert $page) => $page->component('safeguarding/concern')->where('detail.id', $row->id)
                    ->where('detail.description', 'Private source narrative')->where('detail.restricted', false)->where('returnUrl', '/my-day?tab=safety'));
        }
        $this->actingAs($this->viewer)->get('/safeguarding')->assertForbidden();
    }

    public function test_actual_footer_search_dates_filters_and_twenty_row_pagination_are_preserved(): void
    {
        for ($i = 0; $i < 21; $i++) { $this->concern($this->site, ['description' => 'Known register search', 'severity' => 'high', 'abuse_category' => 'financial', 'reported_at' => '2026-10-01 12:00:00']); }
        $this->concern($this->otherSite, ['description' => 'Known register search', 'reported_at' => '2026-10-01 12:00:00']);
        $this->concern($this->site, ['description' => 'Known register search', 'reported_at' => '2026-10-02 12:00:00']);
        $query = ['q' => 'Known register search', 'tab' => 'triage', 'severity' => 'high', 'category' => 'financial',
            'site_id' => $this->site->id, 'from' => '2026-10-01', 'to' => '2026-10-01', 'page' => 2];
        $this->actingAs($this->viewer)->get('/safeguarding?'.http_build_query($query))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 21)->where('rows.current_page', 2)->has('rows.data', 1)->where('tabCounts.triage', 21)
            ->where('filters.q', 'Known register search')->where('filters.from', '2026-10-01')->where('filters.to', '2026-10-01')
            ->where('rows.links', fn ($links) => collect($links)->filter(fn ($link) => $link['url'] !== null)->every(fn ($link) => str_contains($link['url'], 'site_id=') && str_contains($link['url'], 'from=2026-10-01'))));
    }

    public function test_warmed_view_sensitive_withdraw_restore_changes_actual_detail_mask_without_losing_public_count(): void
    {
        $row = $this->concern($this->site); $this->grant('safeguarding.viewSensitive'); $this->viewer->load('roles.permissions', 'permissionOverrides');
        $this->actingAs($this->viewer)->get('/safeguarding/'.$row->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('detail.restricted', false));
        $this->grant('safeguarding.viewSensitive', false);
        $this->actingAs($this->viewer)->get('/safeguarding/'.$row->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('detail.restricted', true)->missing('detail.description')->missing('detail.people'));
        $this->actingAs($this->viewer)->get('/safeguarding')->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 1));
        $this->grant('safeguarding.viewSensitive');
        $this->actingAs($this->viewer)->get('/safeguarding/'.$row->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('detail.restricted', false));
    }

    public function test_current_foreign_concern_detail_denies_before_private_child_lookup_and_restores_on_scope_return(): void
    {
        $row = $this->concern($this->site, ['reported_by_user_id' => $this->viewer->id]); $row->load('site'); $this->viewer->load('hrEmployeeProfile');
        $this->actingAs($this->viewer)->get('/safeguarding/'.$row->id)->assertOk();
        SafeguardingConcern::whereKey($row->id)->update(['site_id' => $this->otherSite->id]);
        DB::enableQueryLog(); DB::flushQueryLog();
        try { $this->actingAs($this->viewer)->get('/safeguarding/'.$row->id)->assertNotFound(); $sql = implode(' ', array_column(DB::getQueryLog(), 'query')); }
        finally { DB::disableQueryLog(); }
        foreach (['safeguarding_attachments', 'safeguarding_investigations', 'safeguarding_risk_assessments'] as $table) { $this->assertStringNotContainsString($table, $sql); }
        SafeguardingConcern::whereKey($row->id)->update(['site_id' => $this->site->id]); $this->actingAs($this->viewer)->get('/safeguarding/'.$row->id)->assertOk();
    }

    public function test_warmed_current_site_withdrawal_keeps_organisation_record_but_no_foreign_register_facts(): void
    {
        $local = $this->concern($this->site); $organisation = $this->concern(null); $this->viewer->load('hrEmployeeProfile');
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['primary_site_id' => $this->otherSite->id]);
        $this->actingAs($this->viewer)->get('/safeguarding')->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 1)->where('rows.data.0.id', $organisation->id));
        $this->actingAs($this->viewer)->get('/safeguarding?concern='.$local->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('detail', null));
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['primary_site_id' => $this->site->id]);
        $this->actingAs($this->viewer)->get('/safeguarding')->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 2));
    }
}
