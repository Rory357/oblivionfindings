<?php

namespace Tests\Feature\Safeguarding;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use App\Policies\SafeguardingConcernPolicy;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\Safeguarding\SafeguardingConcernReadScope;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Whole native source-proposal suite. No Safeguarding controller/dashboard mount; all checks UNRUN. */
final class SafeguardingConcernReadScopeTest extends TestCase
{
    use RefreshDatabase;
    private Site $site;
    private Site $otherSite;
    private User $viewer;
    private User $other;
    private SafeguardingConcernReadScope $reader;

    protected function setUp(): void
    {
        parent::setUp(); $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]); $this->otherSite = Site::factory()->create(['is_active' => true]);
        $this->viewer = $this->staff($this->site); $this->other = $this->staff($this->otherSite);
        $this->allow('safeguarding.viewAny'); $this->reader = app(SafeguardingConcernReadScope::class);
    }

    private function staff(Site $site): User
    {
        $person = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        HrEmployeeProfile::factory()->create(['user_id' => $person->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => '2020-01-01', 'end_date' => null, 'is_active' => true]);
        return $person;
    }
    private function allow(string $key, bool $allowed = true): void
    {
        $this->viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]);
    }
    private function concern(?Site $site, array $facts = []): SafeguardingConcern
    {
        return SafeguardingConcern::factory()->create(['site_id' => $site?->id, 'reported_by_user_id' => $this->other->id,
            'status' => 'reported', 'is_sensitive' => true, 'description' => 'Private allegation', 'subject_name' => 'Private subject', ...$facts]);
    }
    private function ids(array $filters = [], ?array $sites = null): array
    {
        return $this->reader->withCurrentViewer($this->viewer, fn ($current, $reads) => $this->reader
            ->applyFiltersForCurrentReads($this->reader->queryForCurrentReads($current, $reads, $sites), $filters, $reads)->orderBy('id')->pluck('id')->all());
    }
    private function direct(int $id): array
    {
        return $this->reader->withCurrentViewer($this->viewer, function ($current, $reads) use ($id) {
            $row = $this->reader->concernForCurrentReads($id, $current, $reads);
            return ['id' => $row->id, 'description' => $row->description, 'view' => app(SafeguardingConcernPolicy::class)->view($current, $row)];
        });
    }
    private function integrity(callable $operation): void
    {
        try { $operation(); $this->fail('Expected exact current-read integrity refusal.'); } catch (LogicException) { $this->assertTrue(true); }
    }

    public function test_scope_matches_actual_policy_organisation_null_and_approved_owned_sites(): void
    {
        $local = $this->concern($this->site); $organisation = $this->concern(null); $this->concern($this->otherSite);
        $this->assertSame([$local->id, $organisation->id], $this->ids());
        $this->reader->withCurrentViewer($this->viewer, function ($current, $reads) {
            $actual = app(SafeguardingConcernPolicy::class)->applyVisibleScope(SafeguardingConcern::query(), $current)->orderBy('id')->pluck('id')->all();
            $proposed = $this->reader->queryForCurrentReads($current, $reads)->orderBy('id')->pluck('id')->all();
            $this->assertSame($actual, $proposed); return null;
        });
    }

    public function test_sensitive_public_mask_flag_is_not_a_new_count_permission(): void
    {
        $this->concern($this->site); $this->concern($this->site, ['is_sensitive' => false]);
        $this->allow('safeguarding.viewSensitive', false);
        $this->assertSame(['access' => 'readable', 'count' => 2, 'href' => '/safeguarding?tab=open&site_id='.$this->site->id], $this->reader->openCount($this->viewer, [$this->site->id]));
    }

    public function test_reports_bypass_is_actual_policy_but_hs_bypass_does_not_grant_it(): void
    {
        $this->concern($this->site); $this->concern($this->otherSite); $this->concern(null);
        $this->allow('healthSafety.viewAllSites'); $this->assertCount(2, $this->ids());
        $this->assertNull($this->reader->openCount($this->viewer, [$this->otherSite->id])['count']);
        $this->allow('reports.viewAny'); $this->assertCount(3, $this->ids());
        $this->otherSite->update(['is_active' => false]);
        $this->assertSame(1, $this->reader->openCount($this->viewer, [$this->otherSite->id])['count'], 'Actual reports policy bypass is not replaced by invented active-Site denial.');
    }

    public function test_missing_register_read_has_unknown_count_and_no_concern_or_child_sql(): void
    {
        $this->concern($this->site); $this->allow('safeguarding.viewAny', false); $this->allow('reports.viewAny');
        DB::enableQueryLog(); DB::flushQueryLog();
        try { $result = $this->reader->openCount($this->viewer); $sql = implode(' ', array_column(DB::getQueryLog(), 'query')); }
        finally { DB::disableQueryLog(); }
        $this->assertSame(['access' => 'restricted', 'count' => null, 'href' => null], $result);
        $this->assertStringNotContainsString('safeguarding_concerns', $sql); $this->assertStringNotContainsString('safeguarding_external_reports', $sql);
    }

    public function test_true_zero_foreign_selected_unknown_and_organisation_only_without_hr_are_distinct(): void
    {
        $this->assertSame(0, $this->reader->openCount($this->viewer, [$this->site->id])['count']);
        $this->assertSame(['access' => 'restricted', 'count' => null, 'href' => null], $this->reader->openCount($this->viewer, [$this->otherSite->id]));
        $organisation = $this->concern(null); HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['is_active' => false]);
        $this->assertSame([$organisation->id], $this->ids()); $this->assertSame(1, $this->reader->openCount($this->viewer)['count']);
        $this->assertNull($this->reader->openCount($this->viewer, [$this->site->id])['count']);
    }

    public function test_count_and_usable_single_site_link_match_register_filter_terminal_and_old_open_semantics(): void
    {
        $open = $this->concern($this->site, ['reported_at' => '2020-01-01 12:00:00']);
        $this->concern($this->site, ['status' => 'closed']); $this->concern($this->site, ['status' => 'no_action_required']); $this->concern(null);
        $result = $this->reader->openCount($this->viewer, [$this->site->id]);
        parse_str(parse_url($result['href'], PHP_URL_QUERY), $filters);
        $ids = $this->reader->withCurrentViewer($this->viewer, fn ($current, $reads) => $this->reader
            ->applyFiltersForCurrentReads($this->reader->queryForCurrentReads($current, $reads), $filters, $reads)->open()->pluck('id')->all());
        $this->assertSame([$open->id], $ids); $this->assertSame(count($ids), $result['count']);
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['secondary_site_ids' => [$this->otherSite->id]]);
        $this->assertNull($this->reader->openCount($this->viewer, [$this->site->id, $this->otherSite->id])['href'], 'Existing register cannot express an arbitrary multi-Site subset.');
    }

    public function test_all_existing_footer_search_category_subject_site_and_civil_dates_are_retained(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $hit = $this->concern($this->site, ['subject_type' => Client::class, 'subject_id' => $client->id,
            'abuse_category' => 'financial', 'severity' => 'critical', 'description' => 'Retained search match', 'reported_at' => '2026-10-01 12:00:00']);
        $this->concern($this->site, ['description' => 'Retained search match', 'reported_at' => '2026-10-02 12:00:00']);
        $this->assertSame([$hit->id], $this->ids(['q' => 'Retained search', 'category' => 'financial', 'severity' => 'critical',
            'site_id' => $this->site->id, 'subject_id' => $client->id, 'from' => '2026-10-01', 'to' => '2026-10-01', 'tab' => 'all']));
        foreach (['q' => ['duplicate'], 'unknown' => 'value'] as $key => $value) {
            try { $this->ids([$key => $value]); $this->fail('Unsupported/duplicate shape cannot broaden source query.'); }
            catch (ValidationException $error) { $this->assertArrayHasKey($key === 'unknown' ? 'filters' : 'q', $error->errors()); }
        }
    }

    public function test_reporter_and_assignee_direct_admission_survive_without_global_register_grant(): void
    {
        $reported = $this->concern($this->site, ['reported_by_user_id' => $this->viewer->id]);
        $assigned = $this->concern(null, ['assigned_to_user_id' => $this->viewer->id]);
        $this->allow('safeguarding.viewAny', false);
        $this->assertTrue($this->direct($reported->id)['view']); $this->assertSame('Private allegation', $this->direct($assigned->id)['description']);
        $this->assertNull($this->reader->openCount($this->viewer)['count']);
    }

    public function test_direct_canonical_ownership_and_site_changes_reject_stale_incoming_models(): void
    {
        $row = $this->concern($this->site, ['reported_by_user_id' => $this->viewer->id]); $row->load('site');
        $this->allow('safeguarding.viewAny', false); $this->assertSame($row->id, $this->direct($row->id)['id']);
        SafeguardingConcern::whereKey($row->id)->update(['reported_by_user_id' => $this->other->id]);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class); $this->direct($row->id);
    }

    public function test_warmed_role_read_withdrawal_and_restoration_are_current(): void
    {
        $this->concern($this->site); $this->viewer->permissionOverrides()->detach(Permission::where('key', 'safeguarding.viewAny')->sole()->id);
        $role = Role::create(['name' => 'sg_reader_'.str()->uuid(), 'label' => 'Safeguarding read fixture', 'level' => 50, 'type' => 'custom']);
        $id = Permission::where('key', 'safeguarding.viewAny')->sole()->id; $role->permissions()->sync([$id]); $this->viewer->roles()->sync([$role->id]);
        $this->viewer->load('roles.permissions', 'permissionOverrides'); $this->assertSame(1, $this->reader->openCount($this->viewer)['count']);
        $role->permissions()->detach(); $this->assertNull($this->reader->openCount($this->viewer)['count']);
        $role->permissions()->sync([$id]); $this->assertSame(1, $this->reader->openCount($this->viewer)['count']);
    }

    public function test_warmed_site_and_current_activity_withdraw_restore_is_exact(): void
    {
        $row = $this->concern($this->site); $this->viewer->load('hrEmployeeProfile');
        $this->assertSame([$row->id], $this->ids());
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['primary_site_id' => $this->otherSite->id]); $this->assertSame([], $this->ids());
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['primary_site_id' => $this->site->id]);
        $this->site->update(['is_active' => false]); $this->assertSame([], $this->ids());
        $this->site->update(['is_active' => true]); $this->assertSame([$row->id], $this->ids());
    }

    public function test_same_hs_context_prepares_independent_source_and_never_nested_refreshes(): void
    {
        $this->concern($this->site); $this->allow('healthSafety.viewAllSites');
        $result = HealthSafetyReadContext::within($this->viewer, [...SafeguardingConcernReadScope::PERMISSIONS, 'healthSafety.viewAllSites'], function ($context) {
            $level = DB::transactionLevel(); $result = $this->reader->openCountInContext($this->viewer, $context, [$this->site->id]);
            $this->assertSame($level, DB::transactionLevel()); return $result;
        });
        $this->assertSame(1, $result['count']);
    }

    public function test_wrong_viewer_preparation_lifetime_and_returned_authority_refuse_without_fallback(): void
    {
        $expired = null;
        HealthSafetyReadContext::within($this->viewer, SafeguardingConcernReadScope::PERMISSIONS, function ($context) use (&$expired) {
            $expired = $context; $this->integrity(fn () => $this->reader->openCountInContext($this->other, $context)); return null;
        });
        $this->integrity(fn () => $this->reader->openCountInContext($this->viewer, $expired));
        HealthSafetyReadContext::within($this->viewer, ['safeguarding.viewAny'], function ($context) {
            $this->integrity(fn () => $this->reader->openCountInContext($this->viewer, $context)); return null;
        });
        $this->integrity(fn () => $this->reader->withCurrentViewer($this->viewer, fn ($current) => $current));
        $this->integrity(fn () => $this->reader->withCurrentViewer($this->viewer, fn ($current, $reads) => $this->reader->queryForCurrentReads($current, $reads)));
    }

    public function test_captured_query_foreign_reads_or_physical_connection_cannot_reuse_authority(): void
    {
        config(['database.connections.sg_foreign_fixture' => config('database.connections.'.config('database.default'))]);
        HealthSafetyReadContext::within($this->viewer, SafeguardingConcernReadScope::PERMISSIONS, function ($context) {
            $query = null;
            $this->reader->withReadContext($this->viewer, $context, function ($current, $reads) use (&$query) {
                $query = $this->reader->queryForCurrentReads($current, $reads);
                $this->integrity(fn () => $this->reader->queryForCurrentReads(clone $current, $reads));
                $this->integrity(fn () => $this->reader->applyFiltersForCurrentReads((new SafeguardingConcern)->setConnection('sg_foreign_fixture')->newQuery(), [], $reads));
                CurrentAuthorizationReads::within(function ($foreign) use ($current) { $this->integrity(fn () => $this->reader->queryForCurrentReads($current, $foreign)); return null; });
                return null;
            });
            $this->integrity(fn () => $query->count());
            $this->reader->withReadContext($this->viewer, $context, function () use ($query) { $this->integrity(fn () => $query->count()); return null; });
            return null;
        });
    }

    public function test_raw_filter_builder_is_not_a_backdoor_register_grant_for_a_direct_reporter(): void
    {
        $this->allow('safeguarding.viewAny', false);
        try { $this->reader->withCurrentViewer($this->viewer, fn ($current, $reads) => $this->reader->applyFiltersForCurrentReads(SafeguardingConcern::query(), [], $reads)->count()); $this->fail('Direct authority cannot enumerate the register.'); }
        catch (HttpExceptionInterface $error) { $this->assertSame(403, $error->getStatusCode()); }
    }
}
