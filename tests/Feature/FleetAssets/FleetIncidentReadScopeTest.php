<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\FleetIncident;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\Fleet\FleetIncidentReadScope;
use App\Services\HealthSafety\HealthSafetyReadContext;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Complete source-proposal suite; all checks UNRUN, no live Fleet controller extraction yet. */
final class FleetIncidentReadScopeTest extends TestCase
{
    use RefreshDatabase;
    private Site $site;
    private Site $foreignSite;
    private User $viewer;
    private Asset $local;
    private Asset $foreign;
    private ?FleetIncidentReadScope $scope = null;
    private const FROM = '2026-10-11T01:00:00Z';
    private const TO = '2026-10-12T01:00:00Z';

    protected function setUp(): void
    {
        parent::setUp(); $this->seed(RbacSeeder::class); $this->seed(SeedHrPermissionsSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->foreignSite = Site::factory()->create(['is_active' => true]);
        $this->viewer = $this->staff(['fleet.viewAny']);
        $this->local = Asset::factory()->vehicle()->create(['site_id' => $this->site->id, 'home_site_id' => $this->foreignSite->id]);
        $this->foreign = Asset::factory()->vehicle()->create(['site_id' => $this->foreignSite->id, 'home_site_id' => $this->site->id]);
    }

    private function reader(): FleetIncidentReadScope { return $this->scope ??= app(FleetIncidentReadScope::class); }
    private function staff(array $keys): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);
        foreach ($keys as $key) { $this->allow($user, $key); }
        return $user;
    }
    private function allow(User $user, string $key, bool $allowed = true): void
    {
        $permission = Permission::where('key', $key)->sole();
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
    }
    private function incident(Asset $asset, string $at = '2026-10-11 12:00:00', array $facts = []): FleetIncident
    {
        return FleetIncident::factory()->create(['asset_id' => $asset->id, 'occurred_at' => $at,
            'description' => 'Recorded Fleet occurrence', 'severity' => 'minor', 'incident_type' => 'damage', 'status' => 'reported', ...$facts]);
    }
    private function ids(array $filters = [], ?array $sites = null): array
    {
        return $this->reader()->withCurrentViewer($this->viewer, fn ($current, $reads) => $this->reader()
            ->applyFiltersForCurrentReads($this->reader()->queryForCurrentReads($current, $reads, $sites), $filters, $reads)->orderBy('id')->pluck('id')->all());
    }
    private function invalid(callable $operation, string $field): void
    {
        try { $operation(); $this->fail('Expected a field-specific Fleet interval/filter refusal.'); }
        catch (ValidationException $error) { $this->assertArrayHasKey($field, $error->errors()); }
    }
    private function logic(callable $operation): void
    {
        try { $operation(); $this->fail('Expected a callback/source integrity refusal.'); }
        catch (LogicException) { $this->assertTrue(true); }
    }

    public function test_canonical_asset_site_never_uses_home_site_or_null_site(): void
    {
        $local = $this->incident($this->local); $this->incident($this->foreign);
        $this->incident(Asset::factory()->vehicle()->create(['site_id' => null, 'home_site_id' => $this->site->id]));
        $this->assertSame([$local->id], $this->ids());
        $this->assertSame(['access' => 'readable', 'count' => 1], $this->reader()->windowCount($this->viewer, [$this->site->id], self::FROM, self::TO));
    }

    public function test_assets_read_grant_is_actual_alternative_but_manage_actions_do_not_grant_read(): void
    {
        $this->incident($this->local); $this->allow($this->viewer, 'fleet.viewAny', false); $this->allow($this->viewer, 'assets.viewAny');
        $this->assertSame(1, $this->reader()->windowCount($this->viewer, null, self::FROM, self::TO)['count']);
        $this->allow($this->viewer, 'assets.viewAny', false); $this->allow($this->viewer, 'fleet.incidents.manage');
        $this->assertSame(['access' => 'restricted', 'count' => null], $this->reader()->windowCount($this->viewer, null, self::FROM, self::TO));
        $this->allow($this->viewer, 'fleet.manage');
        $this->assertNull($this->reader()->windowCount($this->viewer, null, self::FROM, self::TO)['count']);
    }

    public function test_hs_all_sites_and_incident_manage_do_not_widen_fleet_sites_but_fleet_manager_does(): void
    {
        $this->incident($this->local); $this->incident($this->foreign);
        $this->allow($this->viewer, 'healthSafety.viewAllSites'); $this->allow($this->viewer, 'fleet.incidents.manage');
        $this->assertSame(1, $this->reader()->windowCount($this->viewer, null, self::FROM, self::TO)['count']);
        $this->assertNull($this->reader()->windowCount($this->viewer, [$this->foreignSite->id], self::FROM, self::TO)['count']);
        $this->allow($this->viewer, 'fleet.manage');
        $this->assertSame(2, $this->reader()->windowCount($this->viewer, null, self::FROM, self::TO)['count']);
    }

    public function test_warmed_role_permission_withdrawal_and_restoration_are_fresh(): void
    {
        $this->incident($this->local); $this->allow($this->viewer, 'fleet.viewAny', false);
        // Use a real current role grant, not a mocked canDo response or invented permission.
        $this->viewer->permissionOverrides()->detach(Permission::where('key', 'fleet.viewAny')->sole()->id);
        $role = Role::create(['name' => 'fleet_read_'.str()->uuid(), 'label' => 'Fleet read fixture', 'level' => 50, 'type' => 'custom']);
        $role->permissions()->sync([Permission::where('key', 'fleet.viewAny')->sole()->id]); $this->viewer->roles()->sync([$role->id]);
        $this->viewer->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->assertSame(1, $this->reader()->windowCount($this->viewer, null, self::FROM, self::TO)['count']);
        $role->permissions()->detach();
        $this->assertNull($this->reader()->windowCount($this->viewer, null, self::FROM, self::TO)['count']);
        $role->permissions()->sync([Permission::where('key', 'fleet.viewAny')->sole()->id]);
        $this->assertSame(1, $this->reader()->windowCount($this->viewer, null, self::FROM, self::TO)['count']);
    }

    public function test_warmed_site_assignment_and_site_activity_withdraw_restore_are_fresh(): void
    {
        $this->incident($this->local); $this->viewer->load('hrEmployeeProfile');
        $this->assertSame(1, $this->reader()->windowCount($this->viewer, [$this->site->id], self::FROM, self::TO)['count']);
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $this->assertNull($this->reader()->windowCount($this->viewer, [$this->site->id], self::FROM, self::TO)['count']);
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['primary_site_id' => $this->site->id]);
        $this->site->update(['is_active' => false]); $this->assertNull($this->reader()->windowCount($this->viewer, [$this->site->id], self::FROM, self::TO)['count']);
        $this->site->update(['is_active' => true]); $this->assertSame(1, $this->reader()->windowCount($this->viewer, [$this->site->id], self::FROM, self::TO)['count']);
    }

    public function test_real_readable_zero_is_distinct_from_denied_or_unadmitted_unknown_without_private_sql(): void
    {
        $this->assertSame(['access' => 'readable', 'count' => 0], $this->reader()->windowCount($this->viewer, [$this->site->id], self::FROM, self::TO));
        DB::enableQueryLog(); DB::flushQueryLog();
        try {
            $foreign = $this->reader()->windowCount($this->viewer, [$this->foreignSite->id], self::FROM, self::TO);
            $this->allow($this->viewer, 'fleet.viewAny', false);
            $denied = $this->reader()->windowCount($this->viewer, null, self::FROM, self::TO);
            $sql = implode(' ', array_column(DB::getQueryLog(), 'query'));
        } finally { DB::disableQueryLog(); }
        $this->assertSame(['access' => 'restricted', 'count' => null], $foreign); $this->assertSame($foreign, $denied);
        $this->assertStringNotContainsString('fleet_incidents', $sql);
    }

    public function test_selected_scope_intersection_cannot_be_forged_to_include_foreign_records(): void
    {
        $local = $this->incident($this->local); $this->incident($this->foreign);
        $this->assertSame([$local->id], $this->ids([], [$this->site->id, $this->foreignSite->id]));
        $this->invalid(fn () => $this->ids([], [(string) $this->site->id]), 'site_id');
        try { $this->ids(['site_id' => $this->foreignSite->id]); $this->fail('Foreign exact Fleet Site filter must conceal.'); }
        catch (HttpExceptionInterface $error) { $this->assertSame(404, $error->getStatusCode()); }
    }

    public function test_rolling_interval_includes_lower_excludes_upper_and_normalises_equivalent_offsets(): void
    {
        $this->incident($this->local, '2026-10-11 00:59:59'); $lower = $this->incident($this->local, '2026-10-11 01:00:00');
        $inside = $this->incident($this->local, '2026-10-12 00:59:59'); $this->incident($this->local, '2026-10-12 01:00:00');
        $this->assertSame([$lower->id, $inside->id], $this->ids(['occurred_from' => '2026-10-11T14:00:00+13:00', 'occurred_to' => '2026-10-12T14:00:00+13:00']));
        $this->assertSame(2, $this->reader()->windowCount($this->viewer, null, self::FROM, self::TO)['count']);
    }

    public function test_invalid_naive_duplicate_shape_or_reversed_instants_never_become_a_broader_query(): void
    {
        foreach (['2026-10-11 01:00:00', '2026-02-30T01:00:00Z', '2026-10-11T25:00:00Z', '2026-10-11T01:00:00+14:30', ['2026-10-11T01:00:00Z']] as $bad) {
            $this->invalid(fn () => $this->ids(['occurred_from' => $bad, 'occurred_to' => self::TO]), 'occurred_from');
        }
        $this->invalid(fn () => $this->ids(['occurred_from' => self::FROM]), 'occurred_to');
        $this->invalid(fn () => $this->ids(['occurred_from' => self::TO, 'occurred_to' => self::FROM]), 'occurred_to');
        $this->invalid(fn () => $this->ids(['occurred_from' => self::FROM, 'occurred_to' => self::FROM]), 'occurred_to');
        $this->invalid(fn () => $this->ids(['unsupported_window' => self::FROM]), 'filters');
    }

    public function test_existing_civil_dates_and_all_scalar_filters_compose_with_the_instant_interval(): void
    {
        $hit = $this->incident($this->local, '2026-10-11 12:00:00', ['driver_user_id' => $this->viewer->id,
            'severity' => 'major', 'incident_type' => 'collision', 'status' => 'investigating', 'location' => 'Known search location']);
        $this->incident($this->local, '2026-10-12 00:00:00', ['severity' => 'major']);
        $this->incident($this->foreign, '2026-10-11 12:00:00', ['location' => 'Known search location']);
        $filters = ['vehicle_id' => $this->local->id, 'driver_id' => $this->viewer->id, 'site_id' => $this->site->id,
            'severity' => 'major', 'incident_type' => 'collision', 'status' => 'investigating', 'search' => 'Known search',
            'date_from' => '2026-10-11', 'date_to' => '2026-10-11', 'tab' => 'all', 'occurred_from' => self::FROM, 'occurred_to' => self::TO];
        $this->assertSame([$hit->id], $this->ids($filters));
        $this->assertCount(1, $this->ids(['date_from' => '2026-10-11', 'date_to' => '2026-10-11']));
    }

    public function test_archived_incident_and_asset_move_are_current_source_facts_not_warmed_relations(): void
    {
        $row = $this->incident($this->local); $row->load('asset');
        $this->local->update(['site_id' => $this->foreignSite->id]); $this->assertSame([], $this->ids());
        $this->local->update(['site_id' => $this->site->id]); $this->assertSame([$row->id], $this->ids());
        $row->delete(); $this->assertSame([], $this->ids());
    }

    public function test_supplied_hs_context_uses_its_current_actor_but_independent_fleet_scope_without_nested_refresh(): void
    {
        $this->incident($this->local); $this->incident($this->foreign); $this->allow($this->viewer, 'healthSafety.viewAllSites');
        $result = HealthSafetyReadContext::within($this->viewer, [...FleetIncidentReadScope::PERMISSIONS, 'healthSafety.viewAllSites'], function ($context) {
            $level = DB::transactionLevel();
            $result = $this->reader()->windowCountInContext($this->viewer, $context, [$this->site->id, $this->foreignSite->id], self::FROM, self::TO);
            $this->assertSame($level, DB::transactionLevel()); return $result;
        });
        $this->assertSame(['access' => 'readable', 'count' => 1], $result);
    }

    public function test_wrong_viewer_missing_preparation_and_expired_context_refuse_without_fresh_fallback(): void
    {
        $other = $this->staff(['fleet.viewAny']); $context = null;
        HealthSafetyReadContext::within($this->viewer, FleetIncidentReadScope::PERMISSIONS, function ($active) use ($other, &$context) {
            $context = $active;
            $this->logic(fn () => $this->reader()->windowCountInContext($other, $active, null, self::FROM, self::TO)); return null;
        });
        $this->logic(fn () => $this->reader()->windowCountInContext($this->viewer, $context, null, self::FROM, self::TO));
        HealthSafetyReadContext::within($this->viewer, ['fleet.viewAny'], function ($active) {
            $this->logic(fn () => $this->reader()->windowCountInContext($this->viewer, $active, null, self::FROM, self::TO)); return null;
        });
    }

    public function test_returned_authority_objects_and_captured_query_after_consumer_exit_are_refused(): void
    {
        $this->logic(fn () => $this->reader()->withCurrentViewer($this->viewer, fn ($current) => $current));
        $this->logic(fn () => $this->reader()->withCurrentViewer($this->viewer, fn ($current, $reads) => $this->reader()->queryForCurrentReads($current, $reads)));
        HealthSafetyReadContext::within($this->viewer, FleetIncidentReadScope::PERMISSIONS, function ($context) {
            $query = null;
            $this->reader()->withReadContext($this->viewer, $context, function ($current, $reads) use (&$query) {
                $query = $this->reader()->queryForCurrentReads($current, $reads); return null;
            });
            $this->logic(fn () => $query->count());
            $this->reader()->withReadContext($this->viewer, $context, function () use ($query) { $this->logic(fn () => $query->count()); return null; });
            return null;
        });
    }

    public function test_forged_current_actor_or_foreign_reads_cannot_use_an_active_read_lease(): void
    {
        config(['database.connections.fleet_read_foreign_fixture' => config('database.connections.'.config('database.default'))]);
        $this->reader()->withCurrentViewer($this->viewer, function ($current, $reads) {
            $this->logic(fn () => $this->reader()->queryForCurrentReads(clone $current, $reads));
            $foreignQuery = (new FleetIncident)->setConnection('fleet_read_foreign_fixture')->newQuery();
            $this->logic(fn () => $this->reader()->applyFiltersForCurrentReads($foreignQuery, [], $reads));
            CurrentAuthorizationReads::within(function ($foreign) use ($current) {
                $this->logic(fn () => $this->reader()->queryForCurrentReads($current, $foreign)); return null;
            }); return null;
        });
    }

    public function test_existing_fleet_manager_site_bypass_is_preserved_without_invented_current_staff_denial(): void
    {
        $this->incident($this->foreign); $this->allow($this->viewer, 'fleet.manage');
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['is_active' => false]);
        $this->assertSame(1, $this->reader()->windowCount($this->viewer, [$this->foreignSite->id], self::FROM, self::TO)['count']);
        $this->viewer->update(['approved_at' => null]);
        $this->assertNull($this->reader()->windowCount($this->viewer, null, self::FROM, self::TO)['count']);
    }

    public function test_dashboard_rolling_interval_and_all_current_open_incidents_share_only_admitted_sites(): void
    {
        foreach (['reported', 'investigating', 'resolved', 'closed'] as $status) { $this->incident($this->local, '2026-10-11 12:00:00', ['status' => $status]); }
        // An older unresolved occurrence still requires work now; it is not a rolling occurrence.
        $this->incident($this->local, '2026-10-01 12:00:00');
        $this->incident($this->local, '2026-10-01 12:00:00', ['status' => 'closed']); $this->incident($this->foreign);
        $projection = HealthSafetyReadContext::within($this->viewer, FleetIncidentReadScope::PERMISSIONS,
            fn ($context) => $this->reader()->dashboardCountsInContext($this->viewer, $context, [$this->site->id], self::FROM, self::TO));
        $this->assertSame(['access' => 'readable', 'rolling_count' => 4, 'open_count' => 3], $projection);
        $restricted = HealthSafetyReadContext::within($this->viewer, FleetIncidentReadScope::PERMISSIONS,
            fn ($context) => $this->reader()->dashboardCountsInContext($this->viewer, $context, [$this->foreignSite->id], self::FROM, self::TO));
        $this->assertSame(['access' => 'restricted', 'rolling_count' => null, 'open_count' => null], $restricted);
    }

    public function test_explicit_display_preparation_retains_fresh_existing_flags_without_becoming_read_authority(): void
    {
        $this->allow($this->viewer, 'fleet.incidents.manage'); $this->allow($this->viewer, 'hr.assets.view');
        $flags = fn () => $this->reader()->withCurrentViewer($this->viewer,
            fn ($current) => ['manage' => $current->canDo('fleet.incidents.manage'), 'hr' => $current->canDo('hr.assets.view')],
            ['fleet.incidents.manage', 'hr.assets.view']);
        $this->assertSame(['manage' => true, 'hr' => true], $flags()); $this->viewer->load('permissionOverrides');
        $this->allow($this->viewer, 'fleet.incidents.manage', false); $this->allow($this->viewer, 'hr.assets.view', false);
        $this->assertSame(['manage' => false, 'hr' => false], $flags());
        $this->logic(fn () => $this->reader()->withCurrentViewer($this->viewer, fn () => null, ['healthSafety.viewAllSites']));
    }
}
