<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\DashboardClientOptionsReadService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HealthSafetyReadMaterialisationProof;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

/** Whole real HTTP/source suite. Canonical Policy is never mocked; native execution remains UNRUN. */
final class HsDashboardClientOptionsSourceReadTest extends RestraintEventCommandTestCase
{
    protected function setUp(): void
    {
        parent::setUp(); $this->withoutVite();
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        $this->grant($this->actor, 'hazards.view', true);
        $this->grant($this->actor, 'clients.viewAny', false);
        $this->grant($this->actor, 'clients.viewAssigned', true);
        $this->grant($this->actor, 'healthSafety.viewAllSites', false);
        $this->grant($this->actor, 'clinical.accessAllSites', false);
        $this->grant($this->actor, 'sites.viewAll', false);
        $this->client->supportWorkers()->attach($this->actor->id);
        // Test-only HTTP contract isolates the real option owner from the existing
        // whole-Overview siteLeague staff gate. No admission or source result is substituted.
        Route::middleware(['web', 'auth'])->get('/_test/hs-client-options/{site}', function (Request $request, int $site): array {
            return HealthSafetyReadContext::within($request->user(), DashboardClientOptionsReadService::PERMISSIONS,
                fn ($context) => HealthSafetyReadMaterialisationProof::within($context,
                    function () use ($context, $site): array {
                        $context->consumeSourceViewer(DashboardClientOptionsReadService::PERMISSIONS,
                            fn ($current, $reads) => abort_unless(in_array($site,
                                app(UserSiteAccessService::class)->accessibleSiteIds($current,
                                    UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads), true), 403));
                        $owner = app(DashboardClientOptionsReadService::class);
                        $owner->prepare($context, $site, [$site]);
                        return ['clients' => $owner->options($context, $site)];
                    }));
        });
    }

    public function test_actual_overview_denied_neighbour_never_hydrates_name_and_assignment_positive_is_preserved(): void
    {
        $private = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'DeniedNeighbourMarker']);
        $state = $this->names();
        try {
            $props = $this->overview()->assertOk()->json('props');
            $this->assertSame([$this->client->id], array_column($props['clients'], 'id'));
            $this->assertSame($this->client->first_name, $props['clients'][0]['first_name']);
            $this->assertNotContains($private->id, $state->ids);
            $this->assertCount(1, $state->sql);
        } finally { $this->closeNames($state); }
    }

    public function test_actual_overview_without_client_permission_has_no_name_sql_or_named_models(): void
    {
        $this->grant($this->actor, 'clients.viewAssigned', false);
        $state = $this->names();
        try {
            $this->overview()->assertOk()->assertJsonPath('props.clients', []);
            $this->assertSame([], $state->ids); $this->assertSame([], $state->sql);
        } finally { $this->closeNames($state); }
    }

    public function test_full_read_keeps_existing_first_name_id_order_with_one_bulk_name_select(): void
    {
        $this->grant($this->actor, 'clients.viewAny', true);
        DB::table('clients')->where('id', $this->client->id)->update(['first_name' => 'Zebra']);
        $first = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'Aardvark']);
        $state = $this->names();
        try {
            $this->assertSame([$first->id, $this->client->id], array_column($this->overview()->assertOk()->json('props.clients'), 'id'));
            $this->assertCount(1, $state->sql);
            $this->assertStringContainsString('order by `first_name` asc, `id` asc', $state->sql[0]);
        } finally { $this->closeNames($state); }
    }

    public function test_hs_wide_scope_does_not_replace_client_site_authority_and_existing_client_bypass_stays_positive(): void
    {
        $remote = Site::factory()->create(['is_active' => true]);
        $other = Client::factory()->create(['site_id' => $remote->id, 'first_name' => 'RemoteClientMarker']);
        $this->grant($this->actor, 'healthSafety.viewAllSites', true); $this->grant($this->actor, 'clients.viewAny', true);
        $state = $this->names();
        try {
            $this->assertSame([$this->client->id], array_column($this->overview()->assertOk()->json('props.clients'), 'id'));
            $this->assertNotContains($other->id, $state->ids);
        } finally { $this->closeNames($state); }
        $this->grant($this->actor, 'clinical.accessAllSites', true);
        $this->assertEqualsCanonicalizing([$this->client->id, $other->id], array_column($this->overview()->assertOk()->json('props.clients'), 'id'));
        $this->assertSame([$other->id], array_column($this->overview($remote->id)->assertOk()->json('props.clients'), 'id'));
    }

    public function test_existing_full_client_and_site_bypass_does_not_invent_current_staff_eligibility(): void
    {
        DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['is_active' => false]);
        foreach (['healthSafety.viewAllSites', 'clients.viewAny', 'clinical.accessAllSites'] as $key) { $this->grant($this->actor, $key, true); }
        $this->sourceHttp($this->actor)->assertOk()->assertJsonPath('clients.0.id', $this->client->id);
        // The Site comparison keeps its current-staff rule: it is withheld (empty) while the rest of the overview
        // opens, as main's overview tests expect (HealthSafetyDashboardController::siteLeagueOrEmpty).
        $this->overview()->assertOk()->assertJsonPath('props.kpis.site_league', []);
    }

    public function test_approved_portal_membership_preserves_own_subject_without_staff_or_full_client_permission(): void
    {
        $portal = $this->portal();
        $private = Client::factory()->create(['site_id' => $this->site->id]);
        $state = $this->names();
        try {
            $this->sourceHttp($portal)->assertOk()->assertJsonPath('clients.0.id', $this->client->id)->assertJsonCount(1, 'clients');
            $this->assertNotContains($private->id, $state->ids);
        } finally { $this->closeNames($state); }
    }

    public function test_archived_hr_presence_keeps_canonical_portal_denial_without_blocking_independent_full_read(): void
    {
        $portal = $this->portal();
        HrEmployeeProfile::factory()->create(['user_id' => $portal->id, 'primary_site_id' => $this->site->id, 'is_active' => false, 'deleted_at' => now()]);
        $state = $this->names();
        try {
            $this->sourceHttp($portal)->assertOk()->assertJsonPath('clients', []);
            $this->assertSame([], $state->ids); $this->assertSame([], $state->sql);
        } finally { $this->closeNames($state); }
        $this->grant($portal, 'clients.viewAny', true); $this->grant($portal, 'clinical.accessAllSites', true);
        $this->sourceHttp($portal)->assertOk()->assertJsonPath('clients.0.id', $this->client->id);
    }

    public static function earlyChanges(): array
    {
        return array_map(fn ($change) => [$change], ['grant', 'assignment', 'profile', 'site', 'actor', 'definition', 'role', 'role_grant']);
    }

    #[DataProvider('earlyChanges')]
    public function test_original_option_authority_is_prepared_before_first_other_private_overview_sql(string $change): void
    {
        if (in_array($change, ['role', 'role_grant'], true)) {
            $role = Role::where('name', 'support_worker')->sole();
            $this->actor->roles()->syncWithoutDetaching([$role->id]);
            $role->permissions()->syncWithoutDetaching([Permission::where('key', 'clients.viewAssigned')->sole()->id]);
        }
        $armed = true; $fired = false; $state = $this->names();
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$fired, $change): void {
            if ($armed && ! $fired && str_starts_with(strtolower($sql), 'select count(*) as aggregate from `client_incidents`')) {
                $fired = true; $this->mutate($change);
            }
        });
        try {
            $this->overview()->assertNotFound(); $this->assertTrue($fired, 'Intercept the actual first other private Overview count.');
            $this->assertSame([], $state->ids);
        } finally { $armed = false; $this->closeNames($state); }
        $this->restore($change);
        $this->overview()->assertOk()->assertJsonPath('props.clients.0.id', $this->client->id);
    }

    public static function nameChanges(): array { return [['grant'], ['assignment'], ['site'], ['client_site']]; }

    #[DataProvider('nameChanges')]
    public function test_actual_name_sql_keeps_original_admission_and_hydrates_zero_after_late_change(string $change): void
    {
        $armed = true; $fired = false; $state = $this->names();
        $other = Site::factory()->create(['is_active' => true]);
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$fired, $change, $other): void {
            if ($armed && ! $fired && $this->isNameSql($sql)) {
                $fired = true;
                if ($change === 'client_site') { DB::table('clients')->where('id', $this->client->id)->update(['site_id' => $other->id]); }
                else { $this->mutate($change); }
            }
        });
        try {
            $this->overview()->assertNotFound(); $this->assertTrue($fired); $this->assertSame([], $state->ids);
        } finally { $armed = false; $this->closeNames($state); }
        DB::table('clients')->where('id', $this->client->id)->update(['site_id' => $this->site->id]);
        $this->restore($change);
        $this->overview()->assertOk()->assertJsonPath('props.clients.0.id', $this->client->id);
    }

    public function test_archived_hr_added_after_original_portal_absence_never_hydrates_client_name(): void
    {
        $portal = $this->portal(); $armed = true; $fired = false; $state = $this->names();
        DB::connection()->beforeExecuting(function (string $sql) use ($portal, &$armed, &$fired): void {
            if ($armed && ! $fired && str_starts_with($sql, 'select `id`, `site_id` from `clients`')) {
                $fired = true;
                HrEmployeeProfile::factory()->create(['user_id' => $portal->id, 'primary_site_id' => $this->site->id, 'is_active' => false, 'deleted_at' => now()]);
            }
        });
        try {
            $this->sourceHttp($portal)->assertNotFound(); $this->assertTrue($fired); $this->assertSame([], $state->ids);
        } finally { $armed = false; $this->closeNames($state); }
        DB::table('hr_employee_profiles')->where('user_id', $portal->id)->delete();
        $this->sourceHttp($portal)->assertOk()->assertJsonPath('clients.0.id', $this->client->id);
    }

    public function test_original_portal_membership_cannot_withdraw_at_actual_name_select(): void
    {
        $portal = $this->portal(); $armed = true; $fired = false; $state = $this->names();
        DB::connection()->beforeExecuting(function (string $sql) use ($portal, &$armed, &$fired): void {
            if ($armed && ! $fired && $this->isNameSql($sql)) {
                $fired = true;
                DB::table('client_portal_users')->where('user_id', $portal->id)->where('client_id', $this->client->id)->delete();
            }
        });
        try { $this->sourceHttp($portal)->assertNotFound(); $this->assertTrue($fired); $this->assertSame([], $state->ids); }
        finally { $armed = false; $this->closeNames($state); }
        $portal->portalClients()->syncWithoutDetaching([$this->client->id => ['relation' => 'self']]);
        $this->sourceHttp($portal)->assertOk()->assertJsonPath('clients.0.id', $this->client->id);
    }

    public function test_resolved_timezone_change_during_metadata_discovery_cannot_compile_later_names(): void
    {
        $original = config('app.worker_timezone'); $armed = true; $fired = false; $state = $this->names();
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$fired): void {
            if ($armed && ! $fired && str_starts_with($sql, 'select `id`, `site_id` from `clients`')) {
                $fired = true; config(['app.worker_timezone' => 'Pacific/Honolulu']);
            }
        });
        $this->withoutExceptionHandling();
        try {
            try { $this->sourceHttp($this->actor); $this->fail('Changed original resolved timezone compiled a later name query.'); }
            catch (LogicException) { $this->assertTrue($fired); $this->assertSame([], $state->ids); }
        } finally { $armed = false; config(['app.worker_timezone' => $original]); $this->closeNames($state); }
        $this->sourceHttp($this->actor)->assertOk()->assertJsonPath('clients.0.id', $this->client->id);
    }

    public function test_shared_final_proof_withholds_already_hydrated_names_after_fact_change(): void
    {
        $armed = true; $fired = false;
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        Client::retrieved(function ($client) use (&$armed, &$fired): void {
            if ($armed && ! $fired && (int) $client->id === (int) $this->client->id && array_key_exists('first_name', $client->getAttributes())) {
                $fired = true; DB::table('clients')->where('id', $client->id)->update(['first_name' => 'ChangedAfterRead']);
            }
        });
        try { $this->overview()->assertNotFound(); $this->assertTrue($fired); }
        finally { $armed = false; Model::setEventDispatcher($dispatcher); }
        DB::table('clients')->where('id', $this->client->id)->update(['first_name' => $this->client->first_name]);
        $this->overview()->assertOk()->assertJsonPath('props.clients.0.first_name', $this->client->first_name);
    }

    public function test_authority_withdrawn_after_named_model_hydration_invalidates_outer_response(): void
    {
        $armed = true; $fired = false;
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        Client::retrieved(function ($client) use (&$armed, &$fired): void {
            if ($armed && ! $fired && array_key_exists('first_name', $client->getAttributes())) { $fired = true; $this->mutate('assignment'); }
        });
        try { $this->overview()->assertNotFound(); $this->assertTrue($fired); }
        finally { $armed = false; Model::setEventDispatcher($dispatcher); }
        $this->restore('assignment');
        $this->overview()->assertOk()->assertJsonPath('props.clients.0.id', $this->client->id);
    }

    public function test_original_site_tuple_and_read_lifetime_cannot_be_replaced_or_revived(): void
    {
        $owner = app(DashboardClientOptionsReadService::class); $escaped = null;
        HealthSafetyReadContext::within($this->actor, DashboardClientOptionsReadService::PERMISSIONS,
            function ($context) use ($owner, &$escaped): array {
                $escaped = $context;
                return HealthSafetyReadMaterialisationProof::within($context, function () use ($owner, $context): array {
                    $owner->prepare($context, $this->site->id, [$this->site->id]);
                    try { $owner->options($context, null); $this->fail('Site tuple was replaced.'); } catch (LogicException) {}
                    return $owner->options($context, $this->site->id);
                });
            });
        $this->expectException(LogicException::class); $owner->options($escaped, $this->site->id);
    }

    public function test_options_require_earliest_prepare_inside_shared_final_proof(): void
    {
        $this->expectException(LogicException::class);
        HealthSafetyReadContext::within($this->actor, DashboardClientOptionsReadService::PERMISSIONS,
            fn ($context) => app(DashboardClientOptionsReadService::class)->prepare($context, $this->site->id, [$this->site->id]));
    }

    public function test_same_collector_repeated_prepare_and_options_preserve_positive_client_options(): void
    {
        $owner = app(DashboardClientOptionsReadService::class);
        $rows = HealthSafetyReadContext::within($this->actor, DashboardClientOptionsReadService::PERMISSIONS,
            fn ($context): array => HealthSafetyReadMaterialisationProof::within($context, function () use ($owner, $context): array {
                $owner->prepare($context, $this->site->id, [$this->site->id]);
                $owner->prepare($context, $this->site->id, [$this->site->id]);
                $first = $owner->options($context, $this->site->id);
                $this->assertSame($first, $owner->options($context, $this->site->id));
                return $first;
            }));
        $this->assertSame([$this->client->id], array_column($rows, 'id'));
    }

    public function test_closed_collector_cannot_be_replaced_or_reused_without_collector_before_any_client_sql(): void
    {
        $owner = app(DashboardClientOptionsReadService::class);
        $state = (object) ['armed' => false, 'sql' => []];
        DB::connection()->beforeExecuting(function (string $sql) use ($state): void {
            if ($state->armed && preg_match('/\bfrom\s+`clients`/i', $sql)) { $state->sql[] = $sql; }
        });
        try {
            HealthSafetyReadContext::within($this->actor, DashboardClientOptionsReadService::PERMISSIONS,
                function ($context) use ($owner, $state): array {
                    HealthSafetyReadMaterialisationProof::within($context, function () use ($owner, $context): array {
                        $owner->prepare($context, $this->site->id, [$this->site->id]);
                        return $owner->options($context, $this->site->id);
                    });
                    $state->armed = true;
                    $refuse = function () use ($owner, $context): void {
                        foreach (['prepare', 'options'] as $method) {
                            try {
                                if ($method === 'prepare') { $owner->prepare($context, $this->site->id, [$this->site->id]); }
                                else { $owner->options($context, $this->site->id); }
                                $this->fail('Closed original collector was reused by '.$method.'.');
                            } catch (LogicException) {}
                        }
                    };
                    $refuse();
                    return HealthSafetyReadMaterialisationProof::within($context, function () use ($refuse): array {
                        $refuse();
                        return [];
                    });
                });
            $this->assertSame([], $state->sql);
        } finally { $state->armed = false; }
        $this->overview()->assertOk()->assertJsonPath('props.clients.0.id', $this->client->id);
    }

    private function portal(): User
    {
        $user = User::factory()->create(['role' => 'client', 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', 'client')->sole());
        foreach (['healthSafety.viewAllSites' => true, 'clients.viewAny' => false, 'clients.viewAssigned' => false] as $key => $allowed) { $this->grant($user, $key, $allowed); }
        $user->portalClients()->attach($this->client->id, ['relation' => 'self']);
        return $user;
    }
    private function overview(?int $site = null)
    {
        return $this->actingAs($this->actor)->get('/health-safety'.($site === null ? '' : '?site='.$site), ['X-Inertia' => 'true']);
    }
    private function sourceHttp(User $viewer) { return $this->actingAs($viewer)->getJson('/_test/hs-client-options/'.$this->site->id); }
    private function grant(User $viewer, string $key, bool $allowed): void
    {
        $viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]);
    }
    private function mutate(string $change): void
    {
        match ($change) {
            'grant' => $this->grant($this->actor, 'clients.viewAssigned', false),
            'assignment' => DB::table('client_user')->where('user_id', $this->actor->id)->where('client_id', $this->client->id)->delete(),
            'profile' => DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['is_active' => false]),
            'site' => DB::table('sites')->where('id', $this->site->id)->update(['archived' => true]),
            'actor' => DB::table('users')->where('id', $this->actor->id)->update(['approved_at' => null]),
            'definition' => DB::table('permissions')->where('key', 'clients.viewAssigned')->update(['key' => 'clients.viewAssigned.withdrawn']),
            'role' => DB::table('role_user')->where('user_id', $this->actor->id)->delete(),
            'role_grant' => DB::table('role_permission')->where('role_id', Role::where('name', 'support_worker')->sole()->id)
                ->where('permission_id', Permission::where('key', 'clients.viewAssigned')->sole()->id)->delete(),
            default => null,
        };
    }
    private function restore(string $change): void
    {
        if ($change === 'definition') { DB::table('permissions')->where('key', 'clients.viewAssigned.withdrawn')->update(['key' => 'clients.viewAssigned']); }
        if (in_array($change, ['role', 'role_grant'], true)) {
            $role = Role::where('name', 'support_worker')->sole();
            $this->actor->roles()->syncWithoutDetaching([$role->id]);
            $role->permissions()->syncWithoutDetaching([Permission::where('key', 'clients.viewAssigned')->sole()->id]);
        }
        $this->grant($this->actor, 'clients.viewAssigned', true);
        $this->client->supportWorkers()->syncWithoutDetaching([$this->actor->id]);
        DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['is_active' => true]);
        DB::table('sites')->where('id', $this->site->id)->update(['archived' => false]);
        DB::table('users')->where('id', $this->actor->id)->update(['approved_at' => $this->actor->approved_at]);
    }
    private function isNameSql(string $sql): bool { return str_starts_with(strtolower($sql), 'select `id`, `first_name`, `last_name`, `site_id` from `clients`'); }
    private function names(): object
    {
        $state = (object) ['armed' => true, 'ids' => [], 'sql' => [], 'replaySql' => [], 'dispatcher' => Model::getEventDispatcher()];
        Model::setEventDispatcher(clone $state->dispatcher);
        Client::retrieved(function ($client) use ($state): void {
            if ($state->armed && array_key_exists('first_name', $client->getAttributes())) { $state->ids[] = (int) $client->id; }
        });
        DB::connection()->beforeExecuting(function (string $sql) use ($state): void {
            if ($state->armed && $this->isNameSql($sql)) {
                // Final proof replays raw facts without another named Model hydration.
                if ($state->ids === []) { $state->sql[] = $sql; } else { $state->replaySql[] = $sql; }
            }
        });
        return $state;
    }
    private function closeNames(object $state): void { $state->armed = false; Model::setEventDispatcher($state->dispatcher); }
}
