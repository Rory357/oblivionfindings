<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\FirstAidRecord;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\HealthSafety\HsDashboardService;
use App\Services\UserSiteAccessService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Actual current admission and source scopes; quiet legacy fixtures are not certified history. */
final class SiteSafetyComparisonTest extends TestCase
{
    use RefreshDatabase;
    private Site $alpha;
    private Site $zulu;
    private Site $foreign;
    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp(); $this->travelTo(Carbon::parse('2026-10-12T01:00:00Z')); $this->seed(RbacSeeder::class);
        $this->alpha = Site::factory()->create(['name' => 'Alpha comparison', 'type' => 'house', 'is_active' => true]);
        $this->zulu = Site::factory()->create(['name' => 'Zulu comparison', 'type' => 'facility', 'is_active' => true]);
        $this->foreign = Site::factory()->create(['name' => 'PRIVATE-FOREIGN-SITE', 'type' => 'house', 'is_active' => true]);
        $this->viewer = User::factory()->frontlineWorker()->create(['approved_at' => now(), 'external_clinical_account' => false]);
        HrEmployeeProfile::factory()->create(['user_id' => $this->viewer->id, 'primary_site_id' => $this->alpha->id,
            'secondary_site_ids' => [$this->zulu->id], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);
        foreach (['hazards.view' => true, 'healthSafety.viewAllSites' => false, 'sites.viewAll' => false,
            'clients.viewAny' => false, 'clients.viewAssigned' => false, 'clinical.accessAllSites' => false,
            'restraints.view' => false, 'hr.wellbeing.view' => false, 'sites.type.house.view' => false,
            'sites.type.facility.view' => false, 'sites.type.head_office.view' => false] as $key => $allowed) { $this->grant($key, $allowed); }
    }
    private function grant(string $key, bool $allowed): void
    {
        $this->viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]);
    }
    private function comparison(int|array|null $sites = null): array
    {
        return app(HsDashboardService::class)->siteLeague(Carbon::parse('2026-10-01T00:00:00Z'),
            Carbon::parse('2026-10-12T01:00:00Z'), $sites, $this->viewer);
    }
    private function event(?Site $site = null, array $fields = []): HsEvent
    {
        return HsEvent::factory()->createQuietly(['site_id' => ($site ?? $this->alpha)->id, 'created_by' => $this->viewer->id,
            'client_id' => null, 'staff_id' => null, 'asset_id' => null,
            'occurred_at' => '2026-10-05 12:00:00', 'event_category' => HsEvent::CATEGORY_INCIDENT,
            'idempotency_key' => (string) Str::uuid(), ...$fields]);
    }
    private function hazard(?Site $site = null, string $status = 'open'): SiteHazard
    {
        return SiteHazard::withoutEvents(fn () => SiteHazard::create(['site_id' => ($site ?? $this->alpha)->id,
            'reference_number' => 'CMP-'.Str::uuid(), 'hazard_type' => 'other', 'severity' => 'low', 'likelihood' => 'rare',
            'risk_rating' => 'low', 'description' => 'PRIVATE-HAZARD-CONTENT', 'reported_by_user_id' => $this->viewer->id, 'status' => $status]));
    }
    private function treatment(Site $site, bool $client = false): array
    {
        $person = $client ? Client::factory()->create(['site_id' => $site->id]) : null;
        $source = FirstAidRecord::factory()->createQuietly(['site_id' => $site->id, 'treated_person_type' => $client ? 'client' : 'visitor',
            'treated_person_id' => null, 'client_id' => $person?->id, 'first_aider_id' => $this->viewer->id, 'treated_person_name' => 'PRIVATE-TREATED-NAME']);
        return [$source, $this->event($site, ['source_type' => $source->getMorphClass(), 'source_id' => $source->id,
            'event_category' => HsEvent::CATEGORY_INJURY, 'client_id' => $person?->id])];
    }
    private function queries(callable $operation): array
    {
        DB::flushQueryLog(); DB::enableQueryLog();
        try { return [$operation(), DB::getQueryLog()]; }
        finally { DB::disableQueryLog(); DB::flushQueryLog(); }
    }
    private function noCountBeforeDenial(callable $operation, int $status): void
    {
        [, $queries] = $this->queries(function () use ($operation, $status): void {
            try { $operation(); $this->fail('Expected fresh comparison denial.'); }
            catch (HttpExceptionInterface $denial) { $this->assertSame($status, $denial->getStatusCode()); }
        });
        foreach ($queries as $query) { $this->assertDoesNotMatchRegularExpression('/from\s+\W?(hs_events|site_hazards)\W/i', $query['query']); }
    }
    public function test_alphabetical_rows_preserve_dto_without_weighted_ranking_or_private_content(): void
    {
        $this->event($this->alpha);
        for ($i = 0; $i < 6; $i++) { $this->event($this->zulu); $this->hazard($this->zulu); }
        $this->event($this->foreign); $this->hazard($this->foreign); $rows = $this->comparison();
        $this->assertSame([$this->alpha->id, $this->zulu->id], array_column($rows, 'id'));
        $this->assertSame(['id', 'name', 'incidents', 'hazards'], array_keys($rows[0]));
        $this->assertSame([1, 6], array_column($rows, 'incidents')); $this->assertSame([0, 6], array_column($rows, 'hazards'));
        foreach (['score', 'risk', 'denominator', 'PRIVATE-FOREIGN-SITE', 'PRIVATE-HAZARD-CONTENT'] as $excluded) {
            $this->assertStringNotContainsString($excluded, json_encode($rows));
        }
    }
    public function test_period_boundaries_and_only_original_report_categories_are_counted(): void
    {
        foreach (['2026-10-01 00:00:00', '2026-10-12 01:00:00'] as $date) { $this->event(fields: ['occurred_at' => $date]); }
        foreach ([HsEvent::CATEGORY_NEAR_MISS, HsEvent::CATEGORY_INJURY] as $category) { $this->event(fields: ['event_category' => $category]); }
        foreach (['2026-09-30 23:59:59', '2026-10-12 01:00:01'] as $date) { $this->event(fields: ['occurred_at' => $date]); }
        foreach ([HsEvent::CATEGORY_RESTRAINT, HsEvent::CATEGORY_HAZARD, HsEvent::CATEGORY_EXPOSURE] as $category) { $this->event(fields: ['event_category' => $category]); }
        $this->event()->deleteQuietly(); $this->assertSame(4, $this->comparison($this->alpha->id)[0]['incidents']);
    }
    public function test_open_hazards_remain_current_register_facts_not_period_ranking(): void
    {
        $this->hazard()->updateQuietly(['created_at' => '2020-01-01 00:00:00']); $this->hazard(status: 'in_progress');
        foreach (['mitigated', 'closed', 'reopened'] as $status) { $this->hazard(status: $status); }
        $this->hazard()->deleteQuietly(); $this->assertSame(2, $this->comparison($this->alpha->id)[0]['hazards']);
    }
    public function test_selected_site_has_exact_grouped_counts_without_private_column_hydration(): void
    {
        $this->event($this->alpha); $this->event($this->zulu); $this->hazard($this->alpha); $this->hazard($this->zulu);
        [$rows, $queries] = $this->queries(fn () => $this->comparison($this->alpha->id));
        $this->assertSame([['id' => $this->alpha->id, 'name' => $this->alpha->name, 'incidents' => 1, 'hazards' => 1]], $rows);
        $counts = array_values(array_filter($queries, fn ($query) => str_contains(strtolower($query['query']), 'count(*) as aggregate')));
        $this->assertCount(2, $counts);
        foreach ($counts as $query) { $this->assertStringNotContainsString('description', $query['query']); $this->assertContains($this->alpha->id, array_map(fn ($value) => is_numeric($value) ? (int) $value : $value, $query['bindings'])); }
    }
    public function test_null_viewer_fails_closed_before_any_sql(): void
    {
        [$rows, $queries] = $this->queries(fn () => app(HsDashboardService::class)->siteLeague(viewer: null));
        $this->assertSame([], $rows); $this->assertSame([], $queries);
    }
    public function test_loaded_module_permission_withdrawal_and_restore_precede_counts(): void
    {
        $this->event(); $this->viewer->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->assertSame(1, $this->comparison()[0]['incidents']); $this->grant('hazards.view', false);
        $this->noCountBeforeDenial(fn () => $this->comparison(), 403); $this->grant('hazards.view', true);
        $this->assertSame(1, $this->comparison()[0]['incidents']);
    }
    public function test_current_staff_and_approved_internal_account_admission_is_not_warmed(): void
    {
        $this->comparison(); $this->viewer->load('hrEmployeeProfile', 'roles.permissions', 'permissionOverrides');
        foreach ([['is_active' => false], ['end_date' => '2026-10-01']] as $change) {
            $this->viewer->hrEmployeeProfile()->update($change); $this->noCountBeforeDenial(fn () => $this->comparison(), 403);
            $this->viewer->hrEmployeeProfile()->update(['is_active' => true, 'end_date' => null]);
        }
        $this->viewer->updateQuietly(['external_clinical_account' => true]); $this->noCountBeforeDenial(fn () => $this->comparison(), 403);
        $this->viewer->updateQuietly(['external_clinical_account' => false, 'approved_at' => null]); $this->noCountBeforeDenial(fn () => $this->comparison(), 403);
    }
    public function test_site_reassignment_does_not_reuse_primary_secondary_or_site_cache(): void
    {
        app(UserSiteAccessService::class)->accessibleSiteIds($this->viewer, ['healthSafety.viewAllSites']);
        $this->comparison(); $this->viewer->load('hrEmployeeProfile');
        $this->viewer->hrEmployeeProfile()->update(['primary_site_id' => $this->zulu->id, 'secondary_site_ids' => []]);
        $this->noCountBeforeDenial(fn () => $this->comparison($this->alpha->id), 404);
        $this->assertSame([$this->zulu->id], array_column($this->comparison(), 'id'));
    }
    public function test_foreign_or_mixed_selected_sites_deny_before_count_sql_or_names(): void
    {
        $this->noCountBeforeDenial(fn () => $this->comparison([$this->alpha->id, $this->foreign->id]), 404);
        $this->noCountBeforeDenial(fn () => $this->comparison($this->foreign->id), 404); $this->assertSame([], $this->comparison([]));
    }
    public function test_site_withdrawal_removes_comparison_and_denies_selected_source(): void
    {
        $this->comparison(); $this->zulu->updateQuietly(['is_active' => false]);
        $this->noCountBeforeDenial(fn () => $this->comparison($this->zulu->id), 404);
        $this->assertSame([$this->alpha->id], array_column($this->comparison(), 'id'));
    }
    public function test_hs_wide_scope_does_not_grant_independent_hazard_access_or_invent_zero(): void
    {
        $this->grant('healthSafety.viewAllSites', true); $this->event($this->foreign); $this->hazard($this->foreign);
        [$rows, $queries] = $this->queries(fn () => $this->comparison($this->foreign->id));
        $this->assertSame([['id' => $this->foreign->id, 'name' => $this->foreign->name, 'incidents' => 1, 'hazards' => null]], $rows);
        foreach ($queries as $query) { $this->assertStringNotContainsString('site_hazards', $query['query']); }
        $this->grant('sites.viewAll', true); $this->assertSame(1, $this->comparison($this->foreign->id)[0]['hazards']);
        $this->viewer->load('roles.permissions', 'permissionOverrides'); $this->grant('sites.viewAll', false);
        $this->assertNull($this->comparison($this->foreign->id)[0]['hazards']);
    }
    public function test_hazard_type_restriction_keeps_general_site_and_null_source_without_hazard_sql(): void
    {
        $this->event($this->zulu); $this->hazard($this->zulu); $this->grant('sites.type.house.view', true);
        [$rows, $queries] = $this->queries(fn () => $this->comparison($this->zulu->id));
        $this->assertSame($this->zulu->name, $rows[0]['name']); $this->assertSame(1, $rows[0]['incidents']); $this->assertNull($rows[0]['hazards']);
        foreach ($queries as $query) { $this->assertStringNotContainsString('site_hazards', $query['query']); }
        $this->grant('sites.type.facility.view', true); $this->assertSame(1, $this->comparison($this->zulu->id)[0]['hazards']);
    }
    public function test_client_treatment_count_requires_fresh_subject_permission_and_exact_source_site(): void
    {
        [$source, $event] = $this->treatment($this->alpha, true); $this->event();
        $this->assertSame(1, $this->comparison($this->alpha->id)[0]['incidents']); $this->grant('clients.viewAny', true);
        $this->assertSame(2, $this->comparison($this->alpha->id)[0]['incidents']);
        $this->viewer->load('roles.permissions', 'permissionOverrides'); $this->grant('clients.viewAny', false);
        $this->assertSame(1, $this->comparison($this->alpha->id)[0]['incidents']); $this->grant('clients.viewAny', true);
        $event->updateQuietly(['site_id' => $this->zulu->id]);
        $this->assertSame(1, $this->comparison($this->alpha->id)[0]['incidents']); $this->assertSame(0, $this->comparison($this->zulu->id)[0]['incidents']);
        $this->assertStringNotContainsString($source->treated_person_name, json_encode($this->comparison()));
    }
    public function test_class_morph_treatment_tuple_cannot_copy_staff_identity(): void
    {
        [$source, $event] = $this->treatment($this->alpha); $this->assertSame(1, $this->comparison($this->alpha->id)[0]['incidents']);
        $event->updateQuietly(['source_type' => FirstAidRecord::class]); $this->assertSame(1, $this->comparison($this->alpha->id)[0]['incidents']);
        $event->updateQuietly(['staff_id' => $this->viewer->id]); $this->assertSame(0, $this->comparison($this->alpha->id)[0]['incidents']);
        $this->assertNotNull($source->fresh());
    }
    public function test_injury_tuple_is_factual_only_and_exact_current_worker_site_bound(): void
    {
        $source = WorkplaceInjury::factory()->createQuietly(['user_id' => $this->viewer->id, 'site_id' => $this->alpha->id,
            'source_schema_version' => 0, 'source_revision' => 0, 'notes' => 'PRIVATE-MEDICAL-CONTENT']);
        $event = $this->event(fields: ['source_type' => $source->getMorphClass(), 'source_id' => $source->id,
            'event_category' => HsEvent::CATEGORY_INJURY, 'staff_id' => $this->viewer->id]);
        [$rows, $queries] = $this->queries(fn () => $this->comparison($this->alpha->id)); $this->assertSame(1, $rows[0]['incidents']);
        foreach ($queries as $query) {
            foreach (['work_capacity_assessments', 'workplace_injury_attachments', 'immediate_treatment', 'medical_treatment_type', 'acc_claim_number'] as $private) {
                $this->assertStringNotContainsString($private, $query['query']);
            }
        }
        $this->assertStringNotContainsString('PRIVATE-MEDICAL-CONTENT', json_encode($rows));
        $event->updateQuietly(['staff_id' => null]); $this->assertSame(0, $this->comparison($this->alpha->id)[0]['incidents']);
        $event->updateQuietly(['staff_id' => $this->viewer->id]);
        $source->updateQuietly(['source_schema_version' => 1, 'source_revision' => 1]);
        // Markers without retained factual proof must not manufacture a readable governed injury count.
        $this->assertSame(0, $this->comparison($this->alpha->id)[0]['incidents']);
    }
    public function test_restraint_source_cannot_be_relabelled_as_injury_to_enter_comparison(): void
    {
        $client = Client::factory()->create(['site_id' => $this->alpha->id]);
        $source = RestraintEvent::factory()->createQuietly(['client_id' => $client->id, 'site_id' => $this->alpha->id,
            'created_by' => $this->viewer->id, 'stay_id' => null, 'source_schema_version' => 0]);
        $this->event(fields: ['source_type' => $source->getMorphClass(), 'source_id' => $source->id,
            'event_category' => HsEvent::CATEGORY_INJURY, 'client_id' => $client->id, 'staff_id' => $this->viewer->id]);
        $this->grant('restraints.view', true); $this->grant('clients.viewAny', true);
        $this->assertSame(0, $this->comparison($this->alpha->id)[0]['incidents']); $this->event();
        $this->assertSame(1, $this->comparison($this->alpha->id)[0]['incidents']);
    }
    public function test_exact_period_and_selected_sites_bound_private_discovery_and_ignore_unrelated_backlog(): void
    {
        $this->event(); [$source, $event] = $this->treatment($this->alpha);
        $event->updateQuietly(['occurred_at' => '2020-01-01 00:00:00']); $this->treatment($this->zulu, true);
        $this->comparison($this->alpha->id);
        [$before, $beforeQueries] = $this->queries(fn () => $this->comparison($this->alpha->id));
        for ($i = 0; $i < 12; $i++) { $this->treatment($this->foreign, true); }
        [$after, $afterQueries] = $this->queries(fn () => $this->comparison($this->alpha->id));
        $this->assertSame($before, $after); $this->assertCount(count($beforeQueries), $afterQueries);
        foreach ([$beforeQueries, $afterQueries] as $queries) {
            foreach ($queries as $query) {
                if (preg_match('/from\s+\W?(first_aid_records|restraint_events|workplace_injuries)\W/i', $query['query'])) {
                    $this->assertStringContainsString('occurred_at', $query['query']); $this->assertStringContainsString('site_id', $query['query']);
                }
                $this->assertStringNotContainsString('treated_person_name', $query['query']);
            }
        }
        $this->assertNotNull($source->fresh());
    }
    public function test_warmed_role_permission_is_reloaded_after_actual_role_permission_withdrawal_and_restore(): void
    {
        $this->event(); $permission = Permission::where('key', 'hazards.view')->sole();
        $role = \App\Models\Role::create(['name' => 'site-comparison-'.Str::uuid(), 'label' => 'Comparison reader',
            'level' => 1, 'type' => 'custom']);
        $role->permissions()->sync([$permission->id]); $this->viewer->roles()->sync([$role->id]);
        $this->viewer->permissionOverrides()->detach($permission->id);
        $this->viewer->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->assertTrue($this->viewer->canDo('hazards.view')); $this->assertSame(1, $this->comparison()[0]['incidents']);
        $role->permissions()->detach($permission->id);
        $this->assertTrue($this->viewer->canDo('hazards.view')); // Actual stale loaded-role premise.
        $this->noCountBeforeDenial(fn () => $this->comparison(), 403);
        $role->permissions()->attach($permission->id);
        $this->assertSame(1, $this->comparison()[0]['incidents']);
    }
}
