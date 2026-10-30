<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\FleetIncident;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Services\Fleet\FleetIncidentReadScope;
use App\Services\HealthSafety\HealthSafetyReadContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Incidents\IncidentRoutingTestCase;

/** Whole actual dashboard boundary for one organisation; native/performance acceptance remains UNRUN. */
final class HsFleetDashboardSourceCompositionTest extends IncidentRoutingTestCase
{
    use \Tests\Support\LoadsHealthSafetyOverview;

    private Site $foreignSite;
    private Asset $local;
    private Asset $foreign;
    private const FROM = '2026-09-12T03:00:00Z';
    private const TO = '2026-10-12T03:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->allow($this->manager, 'hazards.view');
        $this->allow($this->manager, 'fleet.viewAny');
        $this->foreignSite = Site::factory()->create(['is_active' => true]);
        $this->local = Asset::factory()->vehicle()->create(['site_id' => $this->site->id, 'home_site_id' => $this->foreignSite->id]);
        $this->foreign = Asset::factory()->vehicle()->create(['site_id' => $this->foreignSite->id, 'home_site_id' => $this->site->id]);
    }

    public function test_actual_http_rolling_half_open_interval_and_all_open_counts_use_asset_site_not_home_site(): void
    {
        foreach ([['2026-09-12 02:59:59', 'reported'], ['2026-09-12 03:00:00', 'reported'],
            ['2026-10-11 03:00:00', 'investigating'], ['2026-10-11 03:00:00', 'resolved'],
            ['2026-10-12 03:00:00', 'reported'], ['2026-09-01 03:00:00', 'reported'],
            ['2026-10-11 03:00:00', 'closed']] as [$date, $status]) {
            $this->fleet($this->local, ['occurred_at' => $date, 'status' => $status]);
        }
        $this->fleet($this->foreign);
        $this->fleet(Asset::factory()->vehicle()->create(['site_id' => null, 'home_site_id' => $this->site->id]));
        $this->page()->assertOk()->assertJsonPath('props.kpis.fleet_incidents_30d', 4)
            ->assertJsonPath('props.kpis.fleet_unresolved', 5);
    }

    public function test_assets_read_is_a_positive_alternative_but_manage_actions_do_not_grant_read(): void
    {
        $this->fleet($this->local);
        $this->allow($this->manager, 'fleet.viewAny', false);
        $this->allow($this->manager, 'assets.viewAny');
        $this->page()->assertOk()->assertJsonPath('props.kpis.fleet_incidents_30d', 1)->assertJsonPath('props.kpis.fleet_unresolved', 1);
        $this->allow($this->manager, 'assets.viewAny', false);
        $this->allow($this->manager, 'fleet.manage');
        $this->allow($this->manager, 'fleet.incidents.manage');
        $state = (object) ['privateQueries' => 0];
        DB::connection()->beforeExecuting(function (string $sql) use ($state): void {
            if (str_starts_with($sql, 'select ') && str_contains($sql, 'from `fleet_incidents`')) { $state->privateQueries++; }
        });
        $this->page()->assertOk()->assertJsonPath('props.kpis.fleet_incidents_30d', null)->assertJsonPath('props.kpis.fleet_unresolved', null);
        $this->assertSame(0, $state->privateQueries);
    }

    public function test_valid_empty_scope_is_readable_zero_and_foreign_hs_selection_is_restricted_null(): void
    {
        $this->page()->assertOk()->assertJsonPath('props.kpis.fleet_incidents_30d', 0)->assertJsonPath('props.kpis.fleet_unresolved', 0);
        $this->allow($this->manager, 'healthSafety.viewAllSites');
        $this->fleet($this->foreign);
        $this->page(['site' => $this->foreignSite->id])->assertOk()->assertJsonPath('props.kpis.fleet_incidents_30d', null)
            ->assertJsonPath('props.kpis.fleet_unresolved', null);
        $this->allow($this->manager, 'fleet.manage');
        $this->page(['site' => $this->foreignSite->id])->assertOk()->assertJsonPath('props.kpis.fleet_incidents_30d', 1);
    }

    public function test_real_role_permission_read_remains_positive_and_withdrawal_at_actual_sql_withholds_original_counts(): void
    {
        $this->fleet($this->local);
        $permission = Permission::where('key', 'fleet.viewAny')->firstOrFail();
        $this->manager->permissionOverrides()->detach($permission->id);
        $role = Role::create(['name' => 'dashboard_fleet_source_reader', 'label' => 'Dashboard Fleet reader', 'type' => 'custom', 'level' => 20]);
        $role->permissions()->attach($permission->id);
        $this->manager->roles()->attach($role->id);
        $this->page()->assertOk()->assertJsonPath('props.kpis.fleet_incidents_30d', 1);
        $state = (object) ['armed' => true, 'fired' => false];
        DB::connection()->beforeExecuting(function (string $sql) use ($state, $role, $permission): void {
            if (! $state->armed || ! str_starts_with($sql, 'select count(*) as aggregate from `fleet_incidents`')) { return; }
            $state->armed = false; $state->fired = true;
            DB::table('role_permission')->where('role_id', $role->id)->where('permission_id', $permission->id)->delete();
        });
        try { $this->page()->assertNotFound(); $this->assertTrue($state->fired); }
        finally { $state->armed = false; $role->permissions()->syncWithoutDetaching([$permission->id]); }
        $this->page()->assertOk()->assertJsonPath('props.kpis.fleet_incidents_30d', 1);
    }

    public function test_original_context_preserves_fleet_manager_bypass_for_inactive_employee_without_new_staff_prerequisite(): void
    {
        $this->fleet($this->foreign);
        $this->allow($this->manager, 'fleet.manage');
        $this->allow($this->manager, 'healthSafety.viewAllSites');
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => false]);
        $result = $this->projection([$this->foreignSite->id]);
        $this->assertSame(['access' => 'readable', 'rolling_count' => 1, 'open_count' => 1], $result);
    }

    #[DataProvider('actualCountMutations')]
    public function test_actual_count_sql_retains_original_authority_and_source_association(string $mutation): void
    {
        $this->fleet($this->local);
        $state = (object) ['armed' => true, 'fired' => false];
        DB::connection()->beforeExecuting(function (string $sql) use ($state, $mutation): void {
            if (! $state->armed || ! str_starts_with($sql, 'select count(*) as aggregate from `fleet_incidents`')) { return; }
            $state->armed = false; $state->fired = true;
            $this->mutate($mutation);
        });
        try {
            $this->page()->assertNotFound();
            $this->assertTrue($state->fired, 'Mutate at the actual Fleet aggregate after original metadata admission.');
        } finally { $state->armed = false; }
    }

    public static function actualCountMutations(): array
    {
        return array_map(fn (string $mutation): array => [$mutation], ['grant', 'role', 'approval', 'external', 'profile', 'site_active', 'site_archived', 'asset_site']);
    }

    #[DataProvider('lateDestinations')]
    public function test_later_actual_category_or_picker_sql_withdrawal_withholds_positive_fleet_counts_and_fresh_restore_recovers(string $destination): void
    {
        $this->fleet($this->local);
        $this->incident(['occurred_at' => '2026-10-11 00:00:00']);
        $this->allow($this->manager, 'clients.viewAny');
        Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'Readable', 'last_name' => 'Neighbour']);
        $state = (object) ['armed' => true, 'fired' => false];
        DB::connection()->beforeExecuting(function (string $sql) use ($state, $destination): void {
            $match = $destination === 'category'
                ? str_starts_with($sql, 'select `type`, COUNT(*) as count from `client_incidents`')
                : str_starts_with($sql, 'select `id`, `first_name`, `last_name`, `site_id` from `clients`');
            if (! $state->armed || ! $match) { return; }
            $state->armed = false; $state->fired = true;
            $this->allow($this->manager, 'fleet.viewAny', false);
        });
        try {
            $this->page()->assertNotFound();
            $this->assertTrue($state->fired, 'Withdraw at later actual SQL, after the Fleet counters were positive.');
        } finally { $state->armed = false; $this->allow($this->manager, 'fleet.viewAny'); }
        $this->page()->assertOk()->assertJsonPath('props.kpis.fleet_incidents_30d', 1)->assertJsonPath('props.kpis.fleet_unresolved', 1);
    }

    public static function lateDestinations(): array { return ['category' => ['category'], 'picker' => ['picker']]; }

    public function test_equal_count_asset_source_replacement_after_positive_counts_is_not_certified_by_sum_equality(): void
    {
        $this->fleet($this->local); $this->fleet($this->foreign);
        $this->incident(['occurred_at' => '2026-10-11 00:00:00']);
        $state = (object) ['armed' => true, 'fired' => false];
        DB::connection()->beforeExecuting(function (string $sql) use ($state): void {
            if (! $state->armed || ! str_starts_with($sql, 'select `type`, COUNT(*) as count from `client_incidents`')) { return; }
            $state->armed = false; $state->fired = true;
            DB::table('assets')->where('id', $this->local->id)->update(['site_id' => $this->foreignSite->id]);
            DB::table('assets')->where('id', $this->foreign->id)->update(['site_id' => $this->site->id]);
        });
        try { $this->page()->assertNotFound(); $this->assertTrue($state->fired); }
        finally { $state->armed = false; }
        $this->page()->assertOk()->assertJsonPath('props.kpis.fleet_incidents_30d', 1)->assertJsonPath('props.kpis.fleet_unresolved', 1);
    }

    public function test_positive_counts_never_hydrate_fleet_private_descriptions_or_vehicle_names(): void
    {
        $this->fleet($this->local, ['description' => 'PRIVATE-FLEET-BODY']);
        $state = (object) ['bodies' => 0];
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        // Main's sidebar task badge (its fleet incident task provider) loads asset names on every page; that's the
        // shared sidebar, not the Overview's fleet counts under test, so only its share/badge frames are set aside.
        $sidebar = static fn (): bool => array_filter(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), fn (array $frame): bool =>
            ($frame['class'] ?? null) === \App\Http\Middleware\HandleInertiaRequests::class && ($frame['function'] ?? null) !== 'handle') !== []
            && ! in_array(\App\Http\Controllers\HealthSafety\HealthSafetyDashboardController::class, array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'class'), true);
        FleetIncident::retrieved(function (FleetIncident $row) use ($state, $sidebar): void {
            if (array_key_exists('description', $row->getAttributes()) && ! $sidebar()) { $state->bodies++; }
        });
        Asset::retrieved(function (Asset $row) use ($state, $sidebar): void {
            if (array_key_exists('name', $row->getAttributes()) && ! $sidebar()) { $state->bodies++; }
        });
        try {
            $response = $this->page()->assertOk()->assertJsonPath('props.kpis.fleet_incidents_30d', 1);
            $this->assertSame(0, $state->bodies);
            $this->assertStringNotContainsString('PRIVATE-FLEET-BODY', $response->getContent());
        } finally { Model::setEventDispatcher($dispatcher); }
    }

    public function test_dashboard_composition_does_not_weaken_or_reopen_standalone_query_leases(): void
    {
        $this->fleet($this->local); $scope = app(FleetIncidentReadScope::class); $captured = null;
        $scope->withCurrentViewer($this->manager, function ($current, $reads) use ($scope, &$captured): array {
            $captured = $scope->queryForCurrentReads($current, $reads);
            return ['count' => (clone $captured)->count()];
        });
        $this->expired(fn () => $captured->count());
        $this->assertSame(1, $this->projection([$this->site->id])['rolling_count']);
        $scope->withCurrentViewer($this->manager, function () use ($captured): array { $this->expired(fn () => $captured->count()); return []; });
    }

    #[DataProvider('invalidDashboardContexts')]
    public function test_new_dashboard_method_refuses_expired_foreign_viewer_or_unprepared_context_before_any_fleet_sql(string $case, string $expectedMessage): void
    {
        $this->fleet($this->local);
        $state = (object) ['privateQueries' => 0];
        DB::connection()->beforeExecuting(function (string $sql) use ($state): void {
            if (str_starts_with($sql, 'select ') && str_contains($sql, 'from `fleet_incidents`')) { $state->privateQueries++; }
        });
        $scope = app(FleetIncidentReadScope::class);
        $keys = [...FleetIncidentReadScope::PERMISSIONS, 'healthSafety.viewAllSites'];
        $reject = function (HealthSafetyReadContext $context) use ($scope, $case, $expectedMessage): array {
            $viewer = $case === 'different_viewer' ? $this->lead : $this->manager;
            try {
                $scope->dashboardCountsWithOriginalContext($viewer, $context, [$this->site->id], self::FROM, self::TO);
                $this->fail('The new dashboard API must reject this original-context misuse.');
            } catch (LogicException $error) {
                $this->assertStringContainsString($expectedMessage, $error->getMessage());
            }
            return [];
        };
        if ($case === 'expired') {
            $captured = null;
            HealthSafetyReadContext::within($this->manager, $keys, function ($context) use (&$captured): array {
                $captured = $context;
                return [];
            });
            $reject($captured);
        } else {
            HealthSafetyReadContext::within($this->manager, $case === 'unprepared' ? ['healthSafety.viewAllSites'] : $keys, $reject);
        }
        $this->assertSame(0, $state->privateQueries, 'Context misuse must fail before discovery or aggregate SQL touches Fleet sources.');
    }

    public static function invalidDashboardContexts(): array
    {
        return ['expired original callback' => ['expired', 'left its read callback'],
            'different viewer' => ['different_viewer', 'belongs to another viewer'],
            'unprepared Fleet keys' => ['unprepared', 'did not prepare the required capabilities']];
    }

    #[DataProvider('dateMutations')]
    public function test_original_dashboard_proof_retains_timezone_and_day_at_actual_aggregate_and_final_replay(string $mutation): void
    {
        $this->fleet($this->local);
        $state = (object) ['armed' => true, 'fired' => false];
        DB::connection()->beforeExecuting(function (string $sql) use ($state, $mutation): void {
            if (! $state->armed || ! str_starts_with($sql, 'select count(*) as aggregate from `fleet_incidents`')) { return; }
            $state->armed = false; $state->fired = true;
            if ($mutation === 'timezone') { config(['app.worker_timezone' => 'UTC']); }
            else { $this->travelTo(Carbon::parse('2026-10-13T03:00:00Z')); }
        });
        try {
            try { $this->projection([$this->site->id]); $this->fail('Original date evidence must not be reopened after the aggregate.'); }
            catch (LogicException $error) { $this->assertStringContainsString('date evidence changed', $error->getMessage()); }
            $this->assertTrue($state->fired);
        } finally {
            $state->armed = false;
            config(['app.worker_timezone' => 'Pacific/Auckland']);
            $this->travelTo(Carbon::parse('2026-10-12T03:00:00Z'));
        }
        $this->assertSame(1, $this->projection([$this->site->id])['rolling_count']);
    }

    public static function dateMutations(): array { return ['timezone' => ['timezone'], 'worker day' => ['day']]; }

    private function mutate(string $mutation): void
    {
        match ($mutation) {
            'grant' => $this->allow($this->manager, 'fleet.viewAny', false),
            'role' => DB::table('users')->where('id', $this->manager->id)->update(['role' => 'next_of_kin']),
            'approval' => DB::table('users')->where('id', $this->manager->id)->update(['approved_at' => null]),
            'external' => DB::table('users')->where('id', $this->manager->id)->update(['external_clinical_account' => true]),
            'profile' => DB::table('hr_employee_profiles')->where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id]),
            'site_active' => DB::table('sites')->where('id', $this->site->id)->update(['is_active' => false]),
            'site_archived' => DB::table('sites')->where('id', $this->site->id)->update(['archived' => true, 'archived_at' => now()]),
            'asset_site' => DB::table('assets')->where('id', $this->local->id)->update(['site_id' => $this->foreignSite->id]),
        };
    }

    private function fleet(Asset $asset, array $values = []): FleetIncident
    {
        return FleetIncident::factory()->create(['asset_id' => $asset->id, 'reported_by_user_id' => $this->manager->id,
            'driver_user_id' => $this->manager->id, 'occurred_at' => '2026-10-11 03:00:00', 'status' => 'reported', ...$values]);
    }

    private function page(array $filters = []): TestResponse
    {
        // The whole Overview (every background part), so a change in any of its reads is caught as before.
        return $this->actingAs($this->manager)->getOverview('/health-safety?'.http_build_query($filters), ['X-Inertia' => 'true']);
    }

    private function projection(?array $sites): array
    {
        return HealthSafetyReadContext::within($this->manager, [...FleetIncidentReadScope::PERMISSIONS, 'healthSafety.viewAllSites'],
            fn ($context): array => app(FleetIncidentReadScope::class)->dashboardCountsWithOriginalContext($this->manager, $context, $sites, self::FROM, self::TO));
    }

    private function expired(callable $operation): void
    {
        try { $operation(); $this->fail('The original standalone Fleet lease must stay expired.'); }
        catch (LogicException) { $this->assertTrue(true); }
    }
}
