<?php

namespace Tests\Feature\Timesheets;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Operations\BillingService;
use App\Services\Operations\TimesheetHrSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class TimesheetApprovalWritePathTest extends TestCase
{
    use RefreshDatabase;

    protected User $finance;

    protected User $staff;

    protected User $otherStaff;

    protected Site $site;

    protected ServiceContext $serviceContext;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-04-12 09:00:00'));

        $this->site = Site::factory()->create([
            'name' => 'Matai House',
            'type' => 'house',
        ]);
        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Residential Support',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'active',
        ]);

        $this->finance = $this->makeRoleUser('finance');
        $this->staff = $this->makeRoleUser('support_worker');
        $this->otherStaff = $this->makeRoleUser('support_worker');

        foreach ([$this->finance, $this->staff, $this->otherStaff] as $user) {
            $this->createEmployeeProfile($user);
        }

        $this->grantPermissions($this->finance, [
            'timesheets.approve',
            'timesheets.manageAny',
            'reports.viewAny',
        ]);
    }

    public function test_operations_approve_uses_the_consolidated_write_path(): void
    {
        $timesheet = $this->makeSubmittedTimesheet($this->staff);
        $this->mockApprovalSideEffects(1);

        $this->actingAs($this->finance)
            ->post(route('operations.timesheets.approve', $timesheet), [
                'decision_notes' => 'Approved through operations.',
            ])
            ->assertSessionHas('success', 'Timesheet approved.');

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'status' => 'approved',
            'approved_by' => $this->finance->id,
            'decision_notes' => 'Approved through operations.',
        ]);
    }

    public function test_repeating_approval_is_idempotent_and_does_not_repeat_side_effects(): void
    {
        $timesheet = $this->makeSubmittedTimesheet($this->staff);
        $this->mockApprovalSideEffects(1);

        $this->actingAs($this->finance)
            ->post(route('operations.timesheets.approve', $timesheet), [
                'decision_notes' => 'Approved once.',
            ])
            ->assertSessionHas('success', 'Timesheet approved.');

        $this->actingAs($this->finance)
            ->post(route('operations.timesheets.approve', $timesheet), [
                'decision_notes' => 'Late duplicate click.',
            ])
            ->assertSessionHas('success', 'Timesheet already approved.');

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'status' => 'approved',
            'approved_by' => $this->finance->id,
            'decision_notes' => 'Approved once.',
        ]);
    }

    public function test_operations_bulk_approve_uses_the_same_write_path_for_each_timesheet(): void
    {
        $first = $this->makeSubmittedTimesheet($this->staff);
        $second = $this->makeSubmittedTimesheet($this->otherStaff, [
            'work_date' => '2026-04-11',
        ]);
        $this->mockApprovalSideEffects(2);

        $this->actingAs($this->finance)
            ->post(route('operations.timesheets.bulkApprove'), [
                'ids' => [$first->id, $second->id],
                'decision_notes' => 'Bulk approved through operations.',
            ])
            ->assertSessionHas('success', 'Selected timesheets approved.');

        foreach ([$first, $second] as $timesheet) {
            $this->assertDatabaseHas('timesheets', [
                'id' => $timesheet->id,
                'status' => 'approved',
                'approved_by' => $this->finance->id,
                'decision_notes' => 'Bulk approved through operations.',
            ]);
        }
    }

    protected function mockApprovalSideEffects(int $times): void
    {
        $this->mock(TimesheetHrSyncService::class, function ($mock) use ($times): void {
            $mock->shouldReceive('syncToHr')
                ->times($times)
                ->with(Mockery::type(Timesheet::class));
        });

        $this->mock(BillingService::class, function ($mock) use ($times): void {
            $mock->shouldReceive('generateFromTimesheet')
                ->times($times)
                ->with(Mockery::type(Timesheet::class))
                ->andReturn(new \Illuminate\Database\Eloquent\Collection());
        });
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);

        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    protected function createEmployeeProfile(User $user): void
    {
        HrEmployeeProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'tenant_id' => 1,
                'employee_number' => 'EMP-TSA-'.$user->id,
                'work_email' => $user->email,
                'position_title' => 'Operations',
                'position_role' => $user->role,
                'employment_type' => 'full_time',
                'start_date' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $this->site->id,
                'secondary_site_ids' => [],
            ],
        );
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }

    protected function makeSubmittedTimesheet(User $staff, array $overrides = []): Timesheet
    {
        [$shift, $attendance] = $this->makeCompletedShiftWithAttendance($staff);

        return Timesheet::query()->create(array_merge([
            'user_id' => $staff->id,
            'client_id' => $shift->client_id,
            'shift_id' => $shift->id,
            'attendance_session_id' => $attendance->id,
            'shift_site_id' => $shift->site_id,
            'shift_service_context_id' => $shift->service_context_id,
            'work_date' => $shift->starts_at->toDateString(),
            'starts_at' => $shift->starts_at,
            'ends_at' => $shift->ends_at,
            'break_minutes' => 0,
            'notes' => 'Submitted notes',
            'status' => 'submitted',
            'submitted_at' => now()->subHour(),
            'submitted_by' => $staff->id,
            'created_by' => $staff->id,
            'shift_site_name_snapshot' => $this->site->name,
            'service_context_name_snapshot' => $this->serviceContext->name,
            'client_name_snapshot' => trim($this->client->first_name.' '.$this->client->last_name),
            'staff_name_snapshot' => $staff->name,
            'shift_type_snapshot' => 'standard',
            'coverage_roles_snapshot' => [],
        ], $overrides));
    }

    /**
     * @return array{0: Shift, 1: HrAttendanceSession}
     */
    protected function makeCompletedShiftWithAttendance(User $staff): array
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-10 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-10 17:00:00'),
            'actual_starts_at' => Carbon::parse('2026-04-10 09:00:00'),
            'actual_ends_at' => Carbon::parse('2026-04-10 17:00:00'),
            'expected_break_minutes' => 0,
            'status' => 'completed',
            'created_by' => $staff->id,
            'started_by' => $staff->id,
            'completed_by' => $staff->id,
        ]);

        $attendance = HrAttendanceSession::query()->create([
            'tenant_id' => 1,
            'user_id' => $staff->id,
            'shift_id' => $shift->id,
            'site_id' => $shift->site_id,
            'clock_in_at' => $shift->actual_starts_at,
            'clock_out_at' => $shift->actual_ends_at,
            'break_minutes' => 0,
            'status' => 'closed',
            'source' => 'manual',
            'created_by' => $staff->id,
            'closed_by' => $staff->id,
        ]);

        return [$shift, $attendance];
    }
}
