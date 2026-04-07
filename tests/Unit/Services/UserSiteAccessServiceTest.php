<?php

namespace Tests\Unit\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\UserSiteAccessService;
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
}
