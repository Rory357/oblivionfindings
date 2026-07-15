<?php

namespace Tests\Unit\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class UserSiteAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_accessible_site_ids_include_primary_and_secondary_sites(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $siteC = Site::factory()->create();
        $user = User::factory()->create();

        HrEmployeeProfile::query()->create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'employee_number' => 'EMP-USA-'.$user->id,
            'work_email' => $user->email,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $siteA->id,
            'secondary_site_ids' => [$siteB->id, $siteC->id],
        ]);

        $siteIds = app(UserSiteAccessService::class)->accessibleSiteIds($user);

        $this->assertSame([$siteA->id, $siteB->id, $siteC->id], $siteIds);
    }

    public function test_shift_and_timesheet_scopes_only_return_accessible_site_records(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $clientA = Client::factory()->create(['site_id' => $siteA->id, 'status' => 'active']);
        $clientB = Client::factory()->create(['site_id' => $siteB->id, 'status' => 'active']);
        $user = User::factory()->create();
        $staff = User::factory()->create();

        HrEmployeeProfile::query()->create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'employee_number' => 'EMP-USA-'.$user->id,
            'work_email' => $user->email,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $siteA->id,
            'secondary_site_ids' => [],
        ]);

        $shiftA = Shift::factory()->create([
            'client_id' => $clientA->id,
            'site_id' => $siteA->id,
            'user_id' => $staff->id,
        ]);

        $shiftB = Shift::factory()->create([
            'client_id' => $clientB->id,
            'site_id' => $siteB->id,
            'user_id' => $staff->id,
        ]);

        $timesheetA = Timesheet::factory()->create([
            'shift_id' => $shiftA->id,
            'user_id' => $staff->id,
            'client_id' => $clientA->id,
            'shift_site_id' => $siteA->id,
        ]);

        $timesheetB = Timesheet::factory()->create([
            'shift_id' => $shiftB->id,
            'user_id' => $staff->id,
            'client_id' => $clientB->id,
            'shift_site_id' => $siteB->id,
        ]);

        $service = app(UserSiteAccessService::class);

        $shiftIds = $service->applyShiftScope(Shift::query(), $user)->pluck('id')->all();
        $timesheetIds = $service->applyTimesheetScope(Timesheet::query(), $user)->pluck('id')->all();

        $this->assertSame([$shiftA->id], $shiftIds);
        $this->assertSame([$timesheetA->id], $timesheetIds);
        $this->assertNotContains($shiftB->id, $shiftIds);
        $this->assertNotContains($timesheetB->id, $timesheetIds);
    }

    public function test_client_incident_scope_uses_client_and_shift_fallback_before_site_column_exists(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $clientA = Client::factory()->create(['site_id' => $siteA->id, 'status' => 'active']);
        $clientB = Client::factory()->create(['site_id' => $siteB->id, 'status' => 'active']);
        $user = $this->siteBoundUser($siteA);
        $staff = User::factory()->create();
        $siteAShift = Shift::factory()->create([
            'client_id' => $clientB->id,
            'site_id' => $siteA->id,
            'user_id' => $staff->id,
        ]);
        $throughClient = ClientIncident::factory()->create([
            'client_id' => $clientA->id,
            'shift_id' => null,
        ]);
        $throughShift = ClientIncident::factory()->create([
            'client_id' => $clientB->id,
            'shift_id' => $siteAShift->id,
        ]);
        $hidden = ClientIncident::factory()->create([
            'client_id' => $clientB->id,
            'shift_id' => null,
        ]);

        $ids = app(UserSiteAccessService::class)
            ->applyClientIncidentScope(ClientIncident::query(), $user)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([$throughClient->id, $throughShift->id], $ids);
        $this->assertNotContains($hidden->id, $ids);
    }

    public function test_future_client_incident_site_column_keeps_nullable_legacy_fallback_in_scope(): void
    {
        $site = Site::factory()->create();
        $user = $this->siteBoundUser($site);
        $service = new class extends UserSiteAccessService
        {
            protected function clientIncidentSiteColumnExists(Builder $query): bool
            {
                return true;
            }
        };

        $sql = $service->applyClientIncidentScope(ClientIncident::query(), $user)->toSql();

        $this->assertStringContainsString('`site_id` in', $sql);
        $this->assertStringContainsString('`site_id` is null', $sql);
        $this->assertStringContainsString('`clients`', $sql);
        $this->assertStringContainsString('`shifts`', $sql);
    }

    public function test_client_incident_site_column_metadata_is_cached_per_service_instance(): void
    {
        $site = Site::factory()->create();
        $user = $this->siteBoundUser($site);
        $service = new class extends UserSiteAccessService
        {
            public int $schemaChecks = 0;

            protected function schemaHasColumn(Builder $query, string $column): bool
            {
                $this->schemaChecks++;

                return false;
            }
        };

        $service->applyClientIncidentScope(ClientIncident::query(), $user);
        $service->applyClientIncidentScope(ClientIncident::query(), $user);

        $this->assertSame(1, $service->schemaChecks);
    }

    public function test_bypass_permission_expands_only_to_sites_in_the_users_organization(): void
    {
        $localPrimary = Site::factory()->create(['tenant_id' => 11]);
        $localUnassigned = Site::factory()->create(['tenant_id' => 11]);
        $foreignStaleAssignment = Site::factory()->create(['tenant_id' => 22]);
        $user = User::factory()->create(['organization_id' => 11]);
        foreach (['reports.viewAny', 'healthSafety.viewAllSites', 'fleet.manage'] as $permission) {
            $this->grantPermission($user, $permission);
        }

        HrEmployeeProfile::factory()->create([
            'tenant_id' => 11,
            'user_id' => $user->id,
            'primary_site_id' => $localPrimary->id,
            'secondary_site_ids' => [$foreignStaleAssignment->id],
        ]);

        $service = app(UserSiteAccessService::class);
        foreach (['reports.viewAny', 'healthSafety.viewAllSites', 'fleet.manage'] as $permission) {
            $bypass = [$permission];

            $this->assertSame(
                [$localPrimary->id, $localUnassigned->id],
                $service->accessibleSiteIds($user, $bypass),
                $permission.' must expand only within the user organization.',
            );
            $this->assertSame(
                [$localPrimary->id, $localUnassigned->id],
                $service->applySiteScope(Site::query()->orderBy('id'), $user, $bypass)->pluck('id')->all(),
                $permission.' must not make site queries installation-wide.',
            );

            try {
                $service->assertCanAccessSiteId($user, $foreignStaleAssignment->id, $bypass);
                $this->fail($permission.' must not authorize a foreign-tenant site.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_ordinary_assignments_are_intersected_with_the_users_organization_sites(): void
    {
        $localSite = Site::factory()->create(['tenant_id' => 31]);
        $foreignStaleAssignment = Site::factory()->create(['tenant_id' => 32]);
        $user = User::factory()->create(['organization_id' => 31]);

        HrEmployeeProfile::factory()->create([
            'tenant_id' => 31,
            'user_id' => $user->id,
            'primary_site_id' => $localSite->id,
            'secondary_site_ids' => [$foreignStaleAssignment->id],
        ]);

        $this->assertSame(
            [$localSite->id],
            app(UserSiteAccessService::class)->accessibleSiteIds($user),
        );
    }

    public function test_client_scope_and_assertion_require_client_organization_agreement(): void
    {
        $localSite = Site::factory()->create(['tenant_id' => 41]);
        $user = User::factory()->create(['organization_id' => 41]);
        $this->grantPermission($user, 'reports.viewAny');
        $localClient = Client::factory()->create([
            'organization_id' => 41,
            'site_id' => $localSite->id,
        ]);
        $foreignClientUsingLocalSite = Client::factory()->create([
            'organization_id' => 42,
            'site_id' => $localSite->id,
        ]);

        $service = app(UserSiteAccessService::class);
        $bypass = ['reports.viewAny'];

        $this->assertSame(
            [$localClient->id],
            $service->applyClientScope(Client::query()->orderBy('id'), $user, $bypass)->pluck('id')->all(),
        );

        try {
            $service->assertCanAccessClientId($user, $foreignClientUsingLocalSite->id, $bypass);
            $this->fail('A matching site must not override a foreign client organization.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_hs_event_scope_uses_site_provenance_and_rejects_conflicting_or_unattributed_records(): void
    {
        $localSite = Site::factory()->create(['tenant_id' => 45]);
        $foreignSite = Site::factory()->create(['tenant_id' => 46]);
        $user = User::factory()->create(['organization_id' => 45]);
        $this->grantPermission($user, 'healthSafety.viewAllSites');

        $localLegacyEvent = HsEvent::factory()->create([
            'organization_id' => null,
            'site_id' => $localSite->id,
        ]);
        $localAttributedEvent = HsEvent::factory()->create([
            'organization_id' => 45,
            'site_id' => $localSite->id,
        ]);
        $localOrganizationOnlyEvent = HsEvent::factory()->create([
            'organization_id' => 45,
            'site_id' => null,
        ]);
        $foreignSiteWithLocalOrganization = HsEvent::factory()->create([
            'organization_id' => 45,
            'site_id' => $foreignSite->id,
        ]);
        $foreignOrganizationOnLocalSite = HsEvent::factory()->create([
            'organization_id' => 46,
            'site_id' => $localSite->id,
        ]);
        $unattributedEvent = HsEvent::factory()->create([
            'organization_id' => null,
            'site_id' => null,
        ]);

        $service = app(UserSiteAccessService::class);
        $bypass = ['healthSafety.viewAllSites'];

        $this->assertSame(
            [$localLegacyEvent->id, $localAttributedEvent->id, $localOrganizationOnlyEvent->id],
            $service->applyHsEventScope(HsEvent::query()->orderBy('id'), $user, $bypass)->pluck('id')->all(),
        );
        $service->assertCanAccessHsEvent($user, $localLegacyEvent, $bypass);
        $service->assertCanAccessHsEvent($user, $localOrganizationOnlyEvent, $bypass);

        foreach ([$foreignSiteWithLocalOrganization, $foreignOrganizationOnLocalSite, $unattributedEvent] as $event) {
            try {
                $service->assertCanAccessHsEvent($user, $event, $bypass);
                $this->fail('Conflicting or missing H&S event tenant provenance must be rejected.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_only_an_explicit_platform_admin_without_an_organization_is_unrestricted(): void
    {
        $firstSite = Site::factory()->create(['tenant_id' => 51]);
        $secondSite = Site::factory()->create(['tenant_id' => 52]);
        $platformAdmin = User::factory()->create(['organization_id' => null]);
        $adminRole = Role::query()->create([
            'name' => 'admin',
            'label' => 'Platform administrator',
            'level' => 100,
            'type' => 'system',
        ]);
        $platformAdmin->roles()->attach($adminRole);
        $this->grantPermission($platformAdmin, 'reports.viewAny');

        $service = app(UserSiteAccessService::class);
        $bypass = ['reports.viewAny'];

        $this->assertTrue($service->isUnrestrictedPlatformUser($platformAdmin));
        $this->assertSame(
            [$firstSite->id, $secondSite->id],
            $service->applySiteScope(Site::query()->orderBy('id'), $platformAdmin, $bypass)->pluck('id')->all(),
        );
        $service->assertCanAccessSiteId($platformAdmin, $secondSite->id, $bypass);
    }

    private function siteBoundUser(Site $site): User
    {
        $user = User::factory()->create();

        HrEmployeeProfile::query()->create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'employee_number' => 'EMP-USA-'.$user->id,
            'work_email' => $user->email,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }

    private function grantPermission(User $user, string $key): void
    {
        $permission = Permission::query()->create([
            'key' => $key,
            'description' => $key,
            'group' => 'test',
            'module' => 'Test',
        ]);

        $user->permissionOverrides()->attach($permission, ['allowed' => true]);
    }
}
