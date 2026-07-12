<?php

namespace Tests\Unit\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
