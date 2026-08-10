<?php

namespace Tests\Unit\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use App\Support\LegacyStorageContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class UserSiteAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_employee_profile_includes_primary_and_secondary_site_assignments(): void
    {
        $primarySite = Site::factory()->create();
        $secondarySite = Site::factory()->create();
        $unassignedSite = Site::factory()->create();
        $user = $this->siteBoundUser($primarySite, [$secondarySite->id, $primarySite->id]);

        $siteIds = app(UserSiteAccessService::class)->accessibleSiteIds($user);

        $this->assertSame([$primarySite->id, $secondarySite->id], $siteIds);
        $this->assertNotContains($unassignedSite->id, $siteIds);
    }

    public function test_inactive_archived_and_soft_deleted_sites_are_excluded_from_assignments(): void
    {
        $activeSite = Site::factory()->create();
        $inactiveSite = Site::factory()->create(['is_active' => false]);
        $archivedSite = Site::factory()->create([
            'archived' => true,
            'archived_at' => now()->subDay(),
        ]);
        $softDeletedSite = Site::factory()->create();
        $softDeletedSite->delete();
        $user = $this->siteBoundUser($activeSite, [
            $inactiveSite->id,
            $archivedSite->id,
            $softDeletedSite->id,
        ]);

        $this->assertSame(
            [$activeSite->id],
            app(UserSiteAccessService::class)->accessibleSiteIds($user),
        );
    }

    public function test_missing_inactive_future_and_ended_employee_profiles_have_no_site_access(): void
    {
        $site = Site::factory()->create();
        $service = app(UserSiteAccessService::class);

        $missingProfileUser = User::factory()->create();
        $this->assertSame([], $service->accessibleSiteIds($missingProfileUser));

        foreach ([
            'inactive' => ['is_active' => false],
            'future' => ['start_date' => now()->addDay()->toDateString()],
            'ended' => ['end_date' => now()->subDay()->toDateString()],
        ] as $state => $overrides) {
            $user = $this->siteBoundUser($site, profileOverrides: $overrides);

            $this->assertSame(
                [],
                $service->accessibleSiteIds($user),
                $state.' employee profiles must not grant Site access.',
            );
        }
    }

    public function test_all_sites_bypass_requires_both_held_permission_and_explicit_caller_key(): void
    {
        $assignedSite = Site::factory()->create();
        $unassignedSite = Site::factory()->create();
        $inactiveSite = Site::factory()->create(['is_active' => false]);
        $archivedSite = Site::factory()->create([
            'archived' => true,
            'archived_at' => now()->subDay(),
        ]);
        $softDeletedSite = Site::factory()->create();
        $softDeletedSite->delete();
        $permission = 'reports.viewAny';

        $holder = $this->siteBoundUser($assignedSite);
        $this->grantPermission($holder, $permission);

        $nonHolder = $this->siteBoundUser($assignedSite);
        $service = app(UserSiteAccessService::class);

        $this->assertSame([$assignedSite->id], $service->accessibleSiteIds($holder));
        $this->assertSame([$assignedSite->id], $service->accessibleSiteIds($nonHolder, [$permission]));
        $this->assertSame(
            [$assignedSite->id, $unassignedSite->id],
            $service->accessibleSiteIds($holder, [$permission]),
        );
        $this->assertNotContains($inactiveSite->id, $service->accessibleSiteIds($holder, [$permission]));
        $this->assertNotContains($archivedSite->id, $service->accessibleSiteIds($holder, [$permission]));
        $this->assertNotContains($softDeletedSite->id, $service->accessibleSiteIds($holder, [$permission]));
    }

    public function test_ordinary_site_scope_stays_narrow_and_site_assertions_fail_closed(): void
    {
        $assignedSite = Site::factory()->create();
        $unassignedSite = Site::factory()->create();
        $user = $this->siteBoundUser($assignedSite);
        $service = app(UserSiteAccessService::class);

        $this->assertSame(
            [$assignedSite->id],
            $service->applySiteScope(Site::query()->orderBy('id'), $user)->pluck('id')->all(),
        );
        $service->assertCanAccessSiteId($user, $assignedSite->id);

        foreach ([$unassignedSite->id, PHP_INT_MAX, null] as $siteId) {
            $this->assertForbidden(fn () => $service->assertCanAccessSiteId($user, $siteId));
        }
    }

    public function test_client_scope_and_assertions_use_site_provenance_and_fail_closed(): void
    {
        $assignedSite = Site::factory()->create();
        $unassignedSite = Site::factory()->create();
        $user = $this->siteBoundUser($assignedSite);
        $visibleClient = Client::factory()->create([
            'site_id' => $assignedSite->id,
            'status' => 'active',
        ]);
        $hiddenClient = Client::factory()->create([
            'site_id' => $unassignedSite->id,
            'status' => 'active',
        ]);
        $unattributedClient = Client::factory()->create([
            'site_id' => null,
            'status' => 'active',
        ]);
        $service = app(UserSiteAccessService::class);

        $this->assertSame(
            [$visibleClient->id],
            $service->applyClientScope(Client::query()->orderBy('id'), $user)->pluck('id')->all(),
        );
        $service->assertCanAccessClientId($user, $visibleClient->id);

        foreach ([$hiddenClient->id, $unattributedClient->id, PHP_INT_MAX, null] as $clientId) {
            $this->assertForbidden(fn () => $service->assertCanAccessClientId($user, $clientId));
        }
    }

    public function test_client_incident_scope_uses_direct_site_provenance_before_legacy_relationship_fallback(): void
    {
        $assignedSite = Site::factory()->create();
        $unassignedSite = Site::factory()->create();
        $user = $this->siteBoundUser($assignedSite);
        $visibleClient = Client::factory()->create([
            'site_id' => $assignedSite->id,
            'status' => 'active',
        ]);
        $hiddenClient = Client::factory()->create([
            'site_id' => $unassignedSite->id,
            'status' => 'active',
        ]);
        $directlyVisible = ClientIncident::factory()->create([
            'client_id' => $hiddenClient->id,
            'site_id' => $assignedSite->id,
            'shift_id' => null,
        ]);
        $directlyHidden = ClientIncident::factory()->create([
            'client_id' => $visibleClient->id,
            'site_id' => $unassignedSite->id,
            'shift_id' => null,
        ]);
        $visibleThroughClient = ClientIncident::factory()->create([
            'client_id' => $visibleClient->id,
            'site_id' => null,
            'shift_id' => null,
        ]);
        $service = app(UserSiteAccessService::class);

        $this->assertSame(
            [$directlyVisible->id, $visibleThroughClient->id],
            $service->applyClientIncidentScope(ClientIncident::query()->orderBy('id'), $user)->pluck('id')->all(),
        );
        $service->assertCanAccessClientIncident($user, $directlyVisible);
        $service->assertCanAccessClientIncident($user, $visibleThroughClient);
        $this->assertForbidden(fn () => $service->assertCanAccessClientIncident($user, $directlyHidden));
    }

    public function test_client_incident_scope_uses_client_and_shift_fallback_before_site_column_exists(): void
    {
        $assignedSite = Site::factory()->create();
        $unassignedSite = Site::factory()->create();
        $visibleClient = Client::factory()->create([
            'site_id' => $assignedSite->id,
            'status' => 'active',
        ]);
        $hiddenClient = Client::factory()->create([
            'site_id' => $unassignedSite->id,
            'status' => 'active',
        ]);
        $user = $this->siteBoundUser($assignedSite);
        $staff = User::factory()->create();
        $visibleShift = Shift::factory()->create([
            'client_id' => $hiddenClient->id,
            'site_id' => $assignedSite->id,
            'user_id' => $staff->id,
        ]);
        $throughClient = ClientIncident::factory()->create([
            'client_id' => $visibleClient->id,
            'site_id' => null,
            'shift_id' => null,
        ]);
        $throughShift = ClientIncident::factory()->create([
            'client_id' => $hiddenClient->id,
            'site_id' => null,
            'shift_id' => $visibleShift->id,
        ]);
        $hidden = ClientIncident::factory()->create([
            'client_id' => $hiddenClient->id,
            'site_id' => null,
            'shift_id' => null,
        ]);
        $service = new class extends UserSiteAccessService
        {
            protected function clientIncidentSiteColumnExists(Builder $query): bool
            {
                return false;
            }
        };

        $ids = $service
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

    /**
     * @param  array<int, int>  $secondarySiteIds
     * @param  array<string, mixed>  $profileOverrides
     */
    private function siteBoundUser(
        Site $primarySite,
        array $secondarySiteIds = [],
        array $profileOverrides = [],
    ): User {
        $user = User::factory()->create();

        HrEmployeeProfile::query()->create([
            ...LegacyStorageContext::attributes(),
            'user_id' => $user->id,
            'employee_number' => 'EMP-USA-'.$user->id,
            'work_email' => $user->email,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
            'is_active' => true,
            'primary_site_id' => $primarySite->id,
            'secondary_site_ids' => $secondarySiteIds,
            ...$profileOverrides,
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

    private function assertForbidden(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the inaccessible or forged identifier to be rejected.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
